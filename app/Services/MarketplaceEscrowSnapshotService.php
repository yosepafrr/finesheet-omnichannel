<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MarketplaceEscrowSnapshotService
{
    public function __construct(
        private readonly OrderEscrowService $orderEscrowService,
    ) {}

    /**
     * @param  array<int, string>|null  $includedCategories
     * @return array{total: float, order_count: int, stores: Collection<int, array<string, mixed>>}
     */
    public function forUser(User $user, ?array $includedCategories = null): array
    {
        $stores = $user->stores()->orderBy('store_name')->get();
        if ($stores->isEmpty()) {
            return [
                'total' => 0.0,
                'order_count' => 0,
                'stores' => collect(),
            ];
        }

        $snapshot = $this->emptySnapshot($stores);

        Order::query()
            ->with(['returns', 'packages'])
            ->whereIn('store_id', $stores->pluck('id'))
            ->whereIn('order_status', $this->orderEscrowService->realtimeStatuses())
            ->chunkById(200, function (Collection $orders) use ($stores, $includedCategories, &$snapshot) {
                $chunk = $this->summarize($stores, $orders, $includedCategories);
                $snapshot = $this->mergeSnapshots($snapshot, $chunk);
            });

        return $snapshot;
    }

    /**
     * @param  Collection<int, mixed>  $stores
     * @param  Collection<int, Order>  $orders
     * @param  array<int, string>|null  $includedCategories
     * @param  Collection<int, array<string, mixed>>|null  $financialSummaries
     * @return array{total: float, order_count: int, stores: Collection<int, array<string, mixed>>}
     */
    public function summarize(
        Collection $stores,
        Collection $orders,
        ?array $includedCategories = null,
        ?Collection $financialSummaries = null,
    ): array {
        $includedCategories ??= $this->defaultCategories();
        $includedCategories = array_values(array_unique($includedCategories));
        $financialSummaries ??= $this->lightweightFinancialSummaries($orders);
        $ordersByStore = $orders->groupBy('store_id');

        $breakdown = $stores->map(function ($store) use (
            $ordersByStore,
            $includedCategories,
            $financialSummaries,
        ) {
            $statusCounts = $this->emptyCategoryValues(0);
            $statusEscrow = $this->emptyCategoryValues(0.0);
            $amount = 0.0;
            $orderCount = 0;

            foreach ($ordersByStore->get($store->id, collect()) as $order) {
                $category = $this->orderEscrowService->category($order);
                if ($category === null) {
                    continue;
                }

                $orderAmount = (float) ($financialSummaries->get($order->id)['escrow_amount'] ?? 0);
                if ($category === OrderEscrowService::CATEGORY_RETURN_CANCEL) {
                    $statusCounts[OrderEscrowService::CATEGORY_SHIPPED]++;
                    $statusEscrow[OrderEscrowService::CATEGORY_SHIPPED] += $orderAmount;
                }

                $statusCounts[$category]++;
                $statusEscrow[$category] += $orderAmount;

                if (! in_array($category, $includedCategories, true)) {
                    continue;
                }

                $amount += $orderAmount;
                $orderCount++;
            }

            return [
                'store_id' => $store->id,
                'store_name' => $store->store_name,
                'platform' => $store->platform,
                'order_count' => $orderCount,
                'amount' => $amount,
                'status_counts' => $statusCounts,
                'status_escrow' => $statusEscrow,
            ];
        })->values();

        return [
            'total' => (float) $breakdown->sum('amount'),
            'order_count' => (int) $breakdown->sum('order_count'),
            'stores' => $breakdown,
        ];
    }

    /** @return array<int, string> */
    public function defaultCategories(): array
    {
        return [
            OrderEscrowService::CATEGORY_NEEDS_SHIPPING,
            OrderEscrowService::CATEGORY_SHIPPED,
        ];
    }

    /** @param array{total: float, stores: Collection<int, array<string, mixed>>} $snapshot */
    public function storeAccountSnapshot(FinancialAccount $account, array $snapshot): FinancialAccount
    {
        $details = $account->integration_details ?? [];

        $account->fill([
            'balance' => $snapshot['total'],
            'synced_at' => now(),
            'integration_details' => [
                ...$details,
                'excludes_seller_balance' => true,
                'stores' => $snapshot['stores']->values()->all(),
                'sync_status' => 'completed',
                'sync_completed_at' => now()->toIso8601String(),
                'sync_error' => null,
            ],
            'is_active' => true,
        ])->save();

        return $account;
    }

    /** @param array{total: float, stores: Collection<int, array<string, mixed>>} $snapshot */
    public function storeExistingAccountSnapshot(User $user, array $snapshot): ?FinancialAccount
    {
        $account = FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('source_type', 'marketplace_escrow')
            ->first();

        return $account ? $this->storeAccountSnapshot($account, $snapshot) : null;
    }

    public function markQueued(FinancialAccount $account): FinancialAccount
    {
        return $this->updateSyncState($account, 'queued', [
            'sync_requested_at' => now()->toIso8601String(),
            'sync_error' => null,
        ]);
    }

    public function markRunning(FinancialAccount $account): FinancialAccount
    {
        return $this->updateSyncState($account, 'running', [
            'sync_started_at' => now()->toIso8601String(),
            'sync_error' => null,
        ]);
    }

    public function markFailed(FinancialAccount $account, ?string $message = null): FinancialAccount
    {
        return $this->updateSyncState($account, 'failed', [
            'sync_failed_at' => now()->toIso8601String(),
            'sync_error' => $message ?: 'Sinkronisasi escrow gagal. Silakan coba kembali.',
        ]);
    }

    public function refreshInProgress(FinancialAccount $account): bool
    {
        $details = $account->integration_details ?? [];
        if (! in_array($details['sync_status'] ?? null, ['queued', 'running'], true)) {
            return false;
        }

        $timestamp = $details['sync_started_at'] ?? $details['sync_requested_at'] ?? null;

        return $timestamp && Carbon::parse($timestamp)->greaterThan(now()->subMinutes(15));
    }

    /** @return array<string, int|float> */
    private function emptyCategoryValues(int|float $value): array
    {
        return [
            OrderEscrowService::CATEGORY_NEEDS_SHIPPING => $value,
            OrderEscrowService::CATEGORY_SHIPPED => $value,
            OrderEscrowService::CATEGORY_RETURN_CANCEL => $value,
        ];
    }

    /** @return Collection<int, array{escrow_amount: float}> */
    private function lightweightFinancialSummaries(Collection $orders): Collection
    {
        return $orders->mapWithKeys(function (Order $order) {
            $amount = $this->orderEscrowService->amount($order);

            if (
                abs($amount) < 0.01
                && ! $this->orderEscrowService->hasReturnOrFailedDelivery($order)
                && is_numeric($order->sale_escrow_amount)
                && (float) $order->sale_escrow_amount > 0
            ) {
                $amount = (float) $order->sale_escrow_amount;
            }

            return [$order->id => ['escrow_amount' => $amount]];
        });
    }

    /** @return array{total: float, order_count: int, stores: Collection<int, array<string, mixed>>} */
    private function emptySnapshot(Collection $stores): array
    {
        return [
            'total' => 0.0,
            'order_count' => 0,
            'stores' => $stores->map(fn ($store) => [
                'store_id' => $store->id,
                'store_name' => $store->store_name,
                'platform' => $store->platform,
                'order_count' => 0,
                'amount' => 0.0,
                'status_counts' => $this->emptyCategoryValues(0),
                'status_escrow' => $this->emptyCategoryValues(0.0),
            ])->values(),
        ];
    }

    /**
     * @param  array{total: float, order_count: int, stores: Collection<int, array<string, mixed>>}  $current
     * @param  array{total: float, order_count: int, stores: Collection<int, array<string, mixed>>}  $chunk
     * @return array{total: float, order_count: int, stores: Collection<int, array<string, mixed>>}
     */
    private function mergeSnapshots(array $current, array $chunk): array
    {
        $chunkStores = $chunk['stores']->keyBy('store_id');
        $current['stores'] = $current['stores']->map(function (array $store) use ($chunkStores) {
            $addition = $chunkStores->get($store['store_id']);
            if (! $addition) {
                return $store;
            }

            $store['order_count'] += $addition['order_count'];
            $store['amount'] += $addition['amount'];
            foreach (array_keys($store['status_counts']) as $category) {
                $store['status_counts'][$category] += $addition['status_counts'][$category] ?? 0;
                $store['status_escrow'][$category] += $addition['status_escrow'][$category] ?? 0;
            }

            return $store;
        })->values();
        $current['total'] += $chunk['total'];
        $current['order_count'] += $chunk['order_count'];

        return $current;
    }

    private function updateSyncState(FinancialAccount $account, string $status, array $attributes): FinancialAccount
    {
        $account->integration_details = [
            ...($account->integration_details ?? []),
            'excludes_seller_balance' => true,
            'sync_status' => $status,
            ...$attributes,
        ];
        $account->is_active = true;
        $account->save();

        return $account;
    }
}

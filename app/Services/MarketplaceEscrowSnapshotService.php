<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

class MarketplaceEscrowSnapshotService
{
    public function __construct(
        private readonly OrderEscrowService $orderEscrowService,
        private readonly OrderProfitService $orderProfitService,
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

        $orders = Order::query()
            ->with(['returns', 'packages', 'orderProducts', 'store'])
            ->whereIn('store_id', $stores->pluck('id'))
            ->whereIn('order_status', $this->orderEscrowService->realtimeStatuses())
            ->get();

        return $this->summarize($stores, $orders, $includedCategories);
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
        $financialSummaries ??= $this->orderProfitService->summaries($orders);
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
        $account->fill([
            'balance' => $snapshot['total'],
            'synced_at' => now(),
            'integration_details' => [
                'excludes_seller_balance' => true,
                'stores' => $snapshot['stores']->values()->all(),
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

    /** @return array<string, int|float> */
    private function emptyCategoryValues(int|float $value): array
    {
        return [
            OrderEscrowService::CATEGORY_NEEDS_SHIPPING => $value,
            OrderEscrowService::CATEGORY_SHIPPED => $value,
            OrderEscrowService::CATEGORY_RETURN_CANCEL => $value,
        ];
    }
}

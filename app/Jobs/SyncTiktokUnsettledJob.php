<?php

namespace App\Jobs;

use App\Events\OrderUpdated;
use App\Models\Order;
use App\Models\Store;
use App\Services\TiktokEscrowAmountResolver;
use App\Services\TiktokService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SyncTiktokUnsettledJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 300;

    public $uniqueFor = 600;

    private const STATEMENT_RECONCILIATION_LIMIT = 25;

    public function __construct(public int $storeId) {}

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        TiktokService $tiktok,
        TiktokEscrowAmountResolver $resolver
    ): void {
        $store = Store::query()
            ->whereKey($this->storeId)
            ->where('platform', 'Tiktokshop')
            ->first();

        if (! $store) {
            Log::warning('TikTok unsettled sync skipped: store not found', [
                'store_id' => $this->storeId,
            ]);

            return;
        }

        $response = $tiktok->getUnsettledTransactions($store);
        if (($response['code'] ?? null) !== 0 || ! is_array($response['data'] ?? null)) {
            throw new RuntimeException(sprintf(
                'TikTok unsettled API failed for store %d: %s',
                $store->id,
                $response['message'] ?? 'Unknown response'
            ));
        }

        $transactionsByOrder = collect($response['data']['transactions'] ?? [])
            ->filter(fn ($transaction) => is_array($transaction) && ! empty($transaction['order_id']))
            ->groupBy(fn (array $transaction) => (string) $transaction['order_id']);

        $updated = 0;
        $lastUpdatedOrder = null;
        foreach ($transactionsByOrder->chunk(500) as $transactionChunk) {
            $orders = Order::query()
                ->where('store_id', $store->id)
                ->where('platform', 'Tiktokshop')
                ->whereIn('order_sn', $transactionChunk->keys()->all())
                ->get()
                ->keyBy(fn (Order $order) => (string) $order->order_sn);

            foreach ($transactionChunk as $orderId => $transactions) {
                $order = $orders->get((string) $orderId);
                if (! $order) {
                    continue;
                }

                $result = $resolver->unsettled([
                    'code' => 0,
                    'data' => [
                        'transactions' => $transactions->values()->all(),
                    ],
                ], (string) $orderId);

                if ($result === null) {
                    continue;
                }

                $order->fill([
                    'escrow_amount' => $result['amount'],
                    'fee_details' => $result['details'],
                ]);

                if (! $order->isDirty(['escrow_amount', 'fee_details'])) {
                    continue;
                }

                $order->saveQuietly();
                $lastUpdatedOrder = $order;
                $updated++;
            }
        }

        $statementQueued = $this->dispatchMissingStatementJobs(
            $store,
            $transactionsByOrder->keys()->all(),
            $resolver
        );

        if ($lastUpdatedOrder) {
            try {
                broadcast(new OrderUpdated($lastUpdatedOrder));
            } catch (\Throwable $e) {
                Log::warning('Failed to broadcast TikTok unsettled batch update', [
                    'store_id' => $store->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::info('TikTok unsettled sync completed', [
            'store_id' => $store->id,
            'pages_fetched' => $response['data']['pages_fetched'] ?? null,
            'transactions' => $transactionsByOrder->flatten(1)->count(),
            'orders_updated' => $updated,
            'statement_jobs_queued' => $statementQueued,
        ]);
    }

    private function dispatchMissingStatementJobs(
        Store $store,
        array $unsettledOrderIds,
        TiktokEscrowAmountResolver $resolver
    ): int {
        $query = Order::query()
            ->where('store_id', $store->id)
            ->where('platform', 'Tiktokshop')
            ->whereIn('order_status', ['DELIVERED', 'COMPLETED'])
            ->where('order_time', '>=', now()->subDays(180))
            ->where(function ($query) {
                $query->whereNull('fee_details')
                    ->orWhereNull('escrow_amount');
            })
            ->with('orderProducts')
            ->orderBy('updated_at')
            ->limit(self::STATEMENT_RECONCILIATION_LIMIT);

        if ($unsettledOrderIds !== []) {
            $query->whereNotIn('order_sn', $unsettledOrderIds);
        }

        $queued = 0;
        foreach ($query->get() as $order) {
            if (! $resolver->needsRefresh(
                $order->fee_details,
                $order->order_status,
                $order->escrow_amount
            )) {
                continue;
            }

            SyncTiktokEscrowJob::dispatch(
                $store->id,
                $order->order_sn,
                $order->order_status ?? '',
                $resolver->fallbackForOrder($order),
                true
            )
                ->onQueue('orders-low')
                ->delay(now()->addSeconds($queued * 2));
            $queued++;
        }

        return $queued;
    }
}

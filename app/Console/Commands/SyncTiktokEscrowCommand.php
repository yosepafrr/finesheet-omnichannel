<?php

namespace App\Console\Commands;

use App\Jobs\SyncTiktokEscrowJob;
use App\Jobs\SyncTiktokUnsettledJob;
use App\Models\Order;
use App\Models\Store;
use App\Services\TiktokEscrowAmountResolver;
use Illuminate\Console\Command;

class SyncTiktokEscrowCommand extends Command
{
    protected $signature = 'sync:tiktok-escrow
        {--store_id= : Only sync escrow for one store}
        {--order_sn= : Only sync escrow for one order}
        {--days=180 : Maximum order age in days}
        {--force : Resync orders that already have finance details}';

    protected $description = 'Queue TikTok unsettled and settled escrow synchronization';

    public function handle(TiktokEscrowAmountResolver $resolver): int
    {
        $force = (bool) $this->option('force');
        $orderSn = $this->option('order_sn');
        $query = Order::query()
            ->where('platform', 'Tiktokshop')
            ->where('order_time', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->when($this->option('store_id'), fn ($q, $storeId) => $q->where('store_id', (int) $storeId))
            ->when($orderSn, fn ($q, $value) => $q->where('order_sn', $value))
            ->with('orderProducts')
            ->orderBy('id');

        if ($orderSn) {
            $order = $query->first();
            if (! $order) {
                $this->error('Pesanan TikTok tidak ditemukan pada rentang yang dipilih.');

                return self::FAILURE;
            }

            if (! $force && ! $resolver->needsRefresh(
                $order->fee_details,
                $order->order_status,
                $order->escrow_amount
            )) {
                $this->info('Escrow pesanan sudah memiliki data finance yang valid. Gunakan --force untuk sinkronisasi ulang.');

                return self::SUCCESS;
            }

            SyncTiktokEscrowJob::dispatch(
                $order->store_id,
                $order->order_sn,
                $order->order_status ?? '',
                $resolver->fallbackForOrder($order)
            )->onQueue('orders-low');

            $this->info('1 sinkronisasi escrow TikTok dimasukkan ke antrean orders-low.');

            return self::SUCCESS;
        }

        $storeIds = (clone $query)
            ->reorder()
            ->select('store_id')
            ->distinct()
            ->pluck('store_id');

        $unsettledQueued = 0;
        Store::query()
            ->where('platform', 'Tiktokshop')
            ->whereIn('id', $storeIds)
            ->each(function (Store $store) use (&$unsettledQueued) {
                SyncTiktokUnsettledJob::dispatch($store->id)->onQueue('orders-low');
                $unsettledQueued++;
            });

        $settledQueued = 0;
        $query->where('order_status', 'COMPLETED')
            ->chunkById(100, function ($orders) use ($resolver, $force, &$settledQueued) {
                foreach ($orders as $order) {
                    if (! $force && ! $resolver->needsRefresh(
                        $order->fee_details,
                        $order->order_status,
                        $order->escrow_amount
                    )) {
                        continue;
                    }

                    SyncTiktokEscrowJob::dispatch(
                        $order->store_id,
                        $order->order_sn,
                        $order->order_status ?? '',
                        $resolver->fallbackForOrder($order)
                    )->onQueue('orders-low');
                    $settledQueued++;
                }
            });

        $this->info(
            "{$unsettledQueued} sinkronisasi unsettled per toko dan {$settledQueued} sinkronisasi settled per pesanan dimasukkan ke antrean orders-low."
        );

        return self::SUCCESS;
    }
}

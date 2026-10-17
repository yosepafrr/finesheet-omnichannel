<?php

namespace App\Console\Commands;

use App\Jobs\SyncShopeeEscrowJob;
use App\Models\Order;
use Illuminate\Console\Command;

class SyncShopeeEscrowCommand extends Command
{
    protected $signature = 'sync:shopee-escrow
        {--store_id= : Only sync escrow for one store}
        {--order_sn= : Only sync escrow for one order}
        {--days=180 : Maximum order age in days}
        {--queue=orders-low : Queue used for escrow jobs}
        {--force : Resync even when an effective escrow value already exists}';

    protected $description = 'Queue Shopee escrow reconciliation for eligible orders';

    public function handle(): int
    {
        $queue = (string) $this->option('queue');
        if (! in_array($queue, ['orders', 'orders-low'], true)) {
            $this->error('Queue harus orders atau orders-low.');

            return self::FAILURE;
        }

        $query = Order::query()
            ->whereRaw('LOWER(platform) = ?', ['shopee'])
            ->when(! $this->option('order_sn'), fn ($query) => $query->whereIn('order_status', [
                'READY_TO_SHIP',
                'PROCESSED',
                'AWAITING_SHIPMENT',
                'AWAITING_COLLECTION',
                'SHIPPED',
                'IN_TRANSIT',
                'TO_CONFIRM_RECEIVE',
                'DELIVERED',
                'COMPLETED',
                'TO_RETURN',
            ]))
            ->when(
                ! $this->option('order_sn'),
                fn ($query) => $query->where(
                    'order_time',
                    '>=',
                    now()->subDays(max(1, (int) $this->option('days'))),
                ),
            )
            ->when($this->option('store_id'), fn ($query, $storeId) => $query->where('store_id', (int) $storeId))
            ->when($this->option('order_sn'), fn ($query, $orderSn) => $query->where('order_sn', $orderSn));

        if (! $this->option('force')) {
            $query->where(function ($query) {
                $query->where(function ($query) {
                    $query->whereNull('escrow_amount')
                        ->orWhere('escrow_amount', '<=', 0);
                })->where(function ($query) {
                    $query->whereNull('escrow_amount_after_adjustment')
                        ->orWhere('escrow_amount_after_adjustment', '<=', 0);
                });
            });
        }

        $queued = 0;
        $query->select(['id', 'store_id', 'order_sn'])
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$queued, $queue) {
                foreach ($orders as $order) {
                    SyncShopeeEscrowJob::dispatch($order->store_id, $order->order_sn)
                        ->onQueue($queue);
                    $queued++;
                }
            });

        $this->info("{$queued} sinkronisasi escrow Shopee dimasukkan ke antrean {$queue}.");

        return self::SUCCESS;
    }
}

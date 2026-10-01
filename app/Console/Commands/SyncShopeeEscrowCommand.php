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
        {--force : Resync even when an effective escrow value already exists}';

    protected $description = 'Queue Shopee escrow reconciliation for active orders';

    public function handle(): int
    {
        $query = Order::query()
            ->whereRaw('LOWER(platform) = ?', ['shopee'])
            ->whereIn('order_status', [
                'READY_TO_SHIP',
                'PROCESSED',
                'AWAITING_SHIPMENT',
                'AWAITING_COLLECTION',
                'SHIPPED',
                'IN_TRANSIT',
                'TO_CONFIRM_RECEIVE',
                'DELIVERED',
            ])
            ->where('order_time', '>=', now()->subDays(max(1, (int) $this->option('days'))))
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
            ->chunkById(100, function ($orders) use (&$queued) {
                foreach ($orders as $order) {
                    SyncShopeeEscrowJob::dispatch($order->store_id, $order->order_sn)
                        ->onQueue('orders-low');
                    $queued++;
                }
            });

        $this->info("{$queued} sinkronisasi escrow Shopee dimasukkan ke antrean orders-low.");

        return self::SUCCESS;
    }
}

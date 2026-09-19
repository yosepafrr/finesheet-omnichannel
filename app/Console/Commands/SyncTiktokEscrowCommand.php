<?php

namespace App\Console\Commands;

use App\Jobs\SyncTiktokEscrowJob;
use App\Models\Order;
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
        $queued = 0;
        $query = Order::query()
            ->where('platform', 'Tiktokshop')
            ->where('order_time', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->when($this->option('store_id'), fn ($q, $storeId) => $q->where('store_id', (int) $storeId))
            ->when($this->option('order_sn'), fn ($q, $orderSn) => $q->where('order_sn', $orderSn))
            ->when(!$this->option('force'), fn ($q) => $q->whereNull('fee_details'))
            ->with('orderProducts')
            ->orderBy('id');

        $query->chunkById(100, function ($orders) use ($resolver, &$queued) {
            foreach ($orders as $order) {
                SyncTiktokEscrowJob::dispatch(
                    $order->store_id,
                    $order->order_sn,
                    $order->order_status ?? '',
                    $resolver->fallbackForOrder($order)
                )->onQueue('orders-low');
                $queued++;
            }
        });

        $this->info("{$queued} sinkronisasi escrow TikTok dimasukkan ke antrean orders-low.");

        return self::SUCCESS;
    }
}

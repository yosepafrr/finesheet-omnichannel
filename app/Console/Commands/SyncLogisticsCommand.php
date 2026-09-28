<?php

namespace App\Console\Commands;

use App\Services\LogisticsSyncService;
use App\Services\PayableService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncLogisticsCommand extends Command
{
    protected $signature = 'sync:logistics
        {--store_id= : Only sync logistics for one store}
        {--order_sn= : Only sync logistics for one order}
        {--force : Ignore the TikTok eight-hour refresh interval}
        {--repair-failed : Recheck TikTok packages currently marked as delivery failed}';

    protected $description = 'Sync logistics and tracking info for active packages';

    public function handle(LogisticsSyncService $logistics, PayableService $payables): int
    {
        Log::info('SyncLogisticsCommand started');

        $storeId = $this->option('store_id') ? (int) $this->option('store_id') : null;
        $orderSn = $this->option('order_sn') ?: null;

        $logistics->prepare($storeId, $orderSn);
        $packageIds = $logistics->packageIds(
            $storeId,
            $orderSn,
            (bool) $this->option('force'),
            (bool) $this->option('repair-failed')
        );

        $this->info('Memproses '.count($packageIds).' paket.');

        foreach (array_chunk($packageIds, 25) as $chunk) {
            $logistics->syncPackages($chunk);
        }

        if ($this->option('repair-failed')) {
            $removedEvents = $payables->cleanupStaleFailedDeliveryEvents();
            $this->info("Membersihkan {$removedEvents} riwayat payable yang sudah tidak valid.");
        }

        Log::info('SyncLogisticsCommand finished');
        $this->info('Sinkronisasi logistik selesai.');

        return self::SUCCESS;
    }
}

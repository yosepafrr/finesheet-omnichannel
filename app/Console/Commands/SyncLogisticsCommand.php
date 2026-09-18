<?php

namespace App\Console\Commands;

use App\Services\LogisticsSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncLogisticsCommand extends Command
{
    protected $signature = 'sync:logistics
        {--store_id= : Only sync logistics for one store}
        {--order_sn= : Only sync logistics for one order}
        {--force : Ignore the TikTok eight-hour refresh interval}';

    protected $description = 'Sync logistics and tracking info for active packages';

    public function handle(LogisticsSyncService $logistics): int
    {
        Log::info('SyncLogisticsCommand started');

        $storeId = $this->option('store_id') ? (int) $this->option('store_id') : null;
        $orderSn = $this->option('order_sn') ?: null;

        $logistics->prepare($storeId, $orderSn);
        $packageIds = $logistics->packageIds($storeId, $orderSn, (bool) $this->option('force'));

        $this->info('Memproses '.count($packageIds).' paket.');

        foreach (array_chunk($packageIds, 25) as $chunk) {
            $logistics->syncPackages($chunk);
        }

        Log::info('SyncLogisticsCommand finished');
        $this->info('Sinkronisasi logistik selesai.');

        return self::SUCCESS;
    }
}

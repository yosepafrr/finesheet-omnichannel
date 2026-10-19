<?php

namespace App\Console\Commands;

use App\Jobs\SyncShopeeAdsSpendJob;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncShopeeAdsSpendCommand extends Command
{
    protected $signature = 'sync:shopee-ads
        {--days=90 : Number of calendar days to sync, including today}
        {--store_id= : Only sync one internal store ID}
        {--queue=orders-low : Queue used for ads spend jobs}';

    protected $description = 'Queue daily Shopee ads spend synchronization';

    public function handle(): int
    {
        $days = max(1, min(366, (int) $this->option('days')));
        $queue = (string) $this->option('queue');

        if (! in_array($queue, ['orders', 'orders-low'], true)) {
            $this->error('Queue harus orders atau orders-low.');

            return self::FAILURE;
        }

        $stores = Store::query()
            ->whereRaw('LOWER(platform) = ?', ['shopee'])
            ->when($this->option('store_id'), fn ($query, $storeId) => $query->whereKey((int) $storeId))
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get(['id', 'store_name']);

        if ($stores->isEmpty()) {
            $this->warn('Tidak ada toko Shopee yang ditemukan.');

            return self::SUCCESS;
        }

        $today = Carbon::today('Asia/Jakarta');
        $queued = 0;

        foreach ($stores as $store) {
            for ($offset = 0; $offset < $days; $offset++) {
                SyncShopeeAdsSpendJob::dispatch(
                    (int) $store->id,
                    $today->copy()->subDays($offset)->toDateString(),
                )->onQueue($queue);
                $queued++;
            }
        }

        $this->info("{$queued} sinkronisasi spend iklan Shopee dimasukkan ke antrean {$queue}.");

        return self::SUCCESS;
    }
}

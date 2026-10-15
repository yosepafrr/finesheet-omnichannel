<?php

namespace App\Console\Commands;

use App\Jobs\SyncShopeeReturnJob;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncShopeeReturnsCommand extends Command
{
    protected $signature = 'sync:shopee-returns
        {--days=180 : Number of days to backfill}
        {--store_id= : Internal store ID}';

    protected $description = 'Queue Shopee return/refund synchronization for existing stores';

    public function handle(): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $storeId = $this->option('store_id');
        $stores = Store::query()
            ->where('platform', 'Shopee')
            ->when($storeId, fn ($query, $id) => $query->whereKey($id))
            ->get(['id', 'store_name']);

        if ($stores->isEmpty()) {
            $this->warn('Tidak ada toko Shopee yang ditemukan.');

            return self::SUCCESS;
        }

        $end = Carbon::now('UTC');
        $start = $end->copy()->subDays($days);
        $jobs = 0;

        foreach ($stores as $store) {
            $cursor = $end->copy();
            while ($cursor > $start) {
                $rangeEnd = $cursor->copy();
                $rangeStart = $cursor->copy()->subDays(15);
                if ($rangeStart < $start) {
                    $rangeStart = $start->copy();
                }

                SyncShopeeReturnJob::dispatch(
                    $store->id,
                    $rangeStart->timestamp,
                    $rangeEnd->timestamp,
                )->onQueue('orders-low');

                $jobs++;
                $cursor = $rangeStart;
            }
        }

        $this->info("Mengantrekan {$jobs} job return Shopee untuk {$stores->count()} toko ({$days} hari).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Services;

use App\Jobs\SyncShopeeOrderJob;
use App\Jobs\SyncShopeeReturnJob;
use App\Jobs\SyncStoreLogisticsJob;
use App\Jobs\SyncTiktokOrderJob;
use App\Jobs\SyncTiktokReturnJob;
use App\Models\Store;
use Carbon\Carbon;

class InitialOrderSyncDispatcher
{
    public const RECENT_DAYS = 30;
    public const HISTORY_DAYS = 180;

    public function dispatch(Store $store): void
    {
        $now = Carbon::now('UTC');
        $recentStart = $now->copy()->subDays(self::RECENT_DAYS);
        $historyStart = $now->copy()->subDays(self::HISTORY_DAYS);

        $this->dispatchRanges($store, $recentStart, $now, 'orders', true, 'initial');
        $this->dispatchReturnSync($store, $recentStart, $now, 'orders');

        SyncStoreLogisticsJob::dispatch($store->id, true, 'initial')->onQueue('logistics');

        $this->dispatchRanges($store, $historyStart, $recentStart, 'orders-low', false, 'backfill');
        $this->dispatchReturnSync($store, $historyStart, $recentStart, 'orders-low');
    }

    private function dispatchRanges(
        Store $store,
        Carbon $start,
        Carbon $end,
        string $queue,
        bool $showProgress,
        string $context
    ): void {
        $windowDays = $store->platform === 'Shopee' ? 15 : 14;
        $ranges = [];
        $cursor = $end->copy();

        while ($cursor > $start) {
            $rangeEnd = $cursor->copy();
            $rangeStart = $cursor->copy()->subDays($windowDays);
            if ($rangeStart < $start) {
                $rangeStart = $start->copy();
            }

            $ranges[] = [$rangeStart->timestamp, $rangeEnd->timestamp];
            $cursor = $rangeStart;
        }

        foreach ($ranges as [$timeFrom, $timeTo]) {
            if ($store->platform === 'Shopee') {
                SyncShopeeOrderJob::dispatch(
                    $store->id,
                    $windowDays,
                    $showProgress,
                    $context,
                    $timeFrom,
                    $timeTo
                )->onQueue($queue);
            } elseif ($store->platform === 'Tiktokshop') {
                SyncTiktokOrderJob::dispatch(
                    $store->id,
                    $windowDays,
                    $timeFrom,
                    $timeTo,
                    $showProgress,
                    $context
                )->onQueue($queue);
            }
        }
    }

    private function dispatchReturnSync(
        Store $store,
        Carbon $start,
        Carbon $end,
        string $queue
    ): void {
        if ($store->platform === 'Shopee') {
            SyncShopeeReturnJob::dispatch($store, $start->timestamp, $end->timestamp)->onQueue($queue);
        } elseif ($store->platform === 'Tiktokshop') {
            SyncTiktokReturnJob::dispatch($store, $start->timestamp, $end->timestamp)->onQueue($queue);
        }
    }
}

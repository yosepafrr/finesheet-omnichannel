<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Store;
use App\Services\TiktokService;
use App\Http\Controllers\TikTokController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class SyncTiktokOrderJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public $tries = 120;
    public $maxExceptions = 3;
    public $timeout = 300;
    public $uniqueFor = 1800;
    public $storeId;
    public $daysToSync;
    public $timeFrom;
    public $timeTo;
    public $showProgress;
    public $syncContext;

    public function __construct(
        $storeId = null,
        $daysToSync = 14,
        $timeFrom = null,
        $timeTo = null,
        $showProgress = false,
        $syncContext = 'manual'
    )
    {
        $this->storeId = $storeId;
        $this->daysToSync = $daysToSync;
        $this->timeFrom = $timeFrom;
        $this->timeTo = $timeTo;
        $this->showProgress = $showProgress;
        $this->syncContext = $syncContext;
    }

    public function uniqueId(): string
    {
        if ($this->timeFrom !== null && $this->timeTo !== null) {
            return ($this->storeId ?? 'all') . ":range:{$this->timeFrom}:{$this->timeTo}";
        }

        return ($this->storeId ?? 'all') . ':' . $this->daysToSync;
    }

    public function middleware(): array
    {
        if (!$this->storeId) {
            return [];
        }

        return [
            (new WithoutOverlapping("order-sync:{$this->storeId}"))
                ->shared()
                ->releaseAfter(5)
                ->expireAfter(330),
        ];
    }

    public function handle(): void
    {
        Log::info('SyncTiktokOrderJob started', ['time' => now(), 'daysToSync' => $this->daysToSync]);

        $query = Store::where('platform', 'Tiktokshop');
        if ($this->storeId) {
            $query->where('id', $this->storeId);
        }
        $stores = $query->get();
        if ($stores->isEmpty()) {
            Log::info('No Tiktok stores found');
            return;
        }

        $tiktokService = new TiktokService();
        $controller = new TikTokController();
        
        $now = Carbon::now('UTC');
        $intervalDays = 14; // Tiktok limit is usually 14-30 days per request

        foreach ($stores as $store) {
            try {
                if ($this->timeFrom !== null && $this->timeTo !== null) {
                    $controller->syncOrders($store, $tiktokService, $this->timeFrom, $this->timeTo);
                    continue;
                }

                $cursorDate = $now->copy()->subDays($this->daysToSync)->startOfDay();

                if ($this->daysToSync > $intervalDays) {
                    while ($cursorDate < $now) {
                        $startTime = $cursorDate->copy();
                        $endTime = $cursorDate->copy()->addDays($intervalDays);

                        if ($endTime > $now) {
                            $endTime = $now;
                        }

                        self::dispatch(
                            $store->id,
                            $intervalDays,
                            $startTime->timestamp,
                            $endTime->timestamp,
                            $this->showProgress,
                            $this->syncContext
                        )->onQueue($this->queue ?: 'orders');

                        $cursorDate = $endTime;
                    }

                    \App\Jobs\SyncTiktokReturnJob::dispatch(
                        $store,
                        $now->copy()->subDays($this->daysToSync)->timestamp,
                        $now->timestamp
                    )->onQueue($this->queue ?: 'orders');

                    if ($this->showProgress) {
                        SyncStoreLogisticsJob::dispatch(
                            $store->id,
                            true,
                            $this->syncContext
                        )->onQueue('logistics');
                    }

                    continue;
                }

                while ($cursorDate < $now) {
                    $startTime = $cursorDate->copy();
                    $endTime = $cursorDate->copy()->addDays($intervalDays);

                    if ($endTime > $now) {
                        $endTime = $now;
                    }

                    $controller->syncOrders($store, $tiktokService, $startTime->timestamp, $endTime->timestamp);

                    $cursorDate->addDays($intervalDays);
                }

                // Dispatch return sync for TikTok store
                \App\Jobs\SyncTiktokReturnJob::dispatch(
                    $store,
                    $now->copy()->subDays($this->daysToSync)->timestamp,
                    $now->timestamp
                )->onQueue($this->queue ?: 'orders');

                if ($this->showProgress) {
                    SyncStoreLogisticsJob::dispatch(
                        $store->id,
                        true,
                        $this->syncContext
                    )->onQueue('logistics');
                }
            } catch (\Exception $e) {
                Log::error('Failed to sync Tiktok store orders', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::info('SyncTiktokOrderJob finished');
    }
}

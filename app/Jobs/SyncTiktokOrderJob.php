<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Store;
use App\Services\TiktokService;
use App\Http\Controllers\TikTokController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncTiktokOrderJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public $tries = 3;
    public $timeout = 300;
    public $uniqueFor = 1800;
    public $storeId;
    public $daysToSync;

    public function __construct($storeId = null, $daysToSync = 14)
    {
        $this->storeId = $storeId;
        $this->daysToSync = $daysToSync;
    }

    public function uniqueId(): string
    {
        return ($this->storeId ?? 'all') . ':' . $this->daysToSync;
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
                $cursorDate = $now->copy()->subDays($this->daysToSync)->startOfDay();

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
                \App\Jobs\SyncTiktokReturnJob::dispatch($store, $now->copy()->subDays($this->daysToSync)->timestamp, $now->timestamp)->onQueue('orders');
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

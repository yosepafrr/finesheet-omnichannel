<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Store;
use App\Services\TiktokService;
use App\Http\Controllers\TiktokController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncTiktokOrderJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $timeout = 120;
    public $storeId;

    public function __construct($storeId = null)
    {
        $this->storeId = $storeId;
    }

    public function handle(): void
    {
        Log::info('SyncTiktokOrderJob started', ['time' => now()]);

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
        $controller = new TiktokController();
        
        $now = Carbon::now('UTC');
        $startTime = $now->copy()->subDays(15);

        foreach ($stores as $store) {
            try {
                $controller->syncOrders($store, $tiktokService, $startTime->timestamp, $now->timestamp);
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

<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\TiktokService;
use App\Http\Controllers\TiktokController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncTiktokProductJob implements ShouldQueue
{
    use Queueable;

    public $storeId;

    public function __construct($storeId = null)
    {
        $this->storeId = $storeId;
    }

    public function handle(): void
    {
        Log::info('SyncTiktokProductJob started', ['time' => now()]);

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
        
        foreach ($stores as $store) {
            try {
                $controller->syncProducts($store, $tiktokService);
            } catch (\Exception $e) {
                Log::error('Failed to sync Tiktok store products', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::info('SyncTiktokProductJob finished');
    }
}

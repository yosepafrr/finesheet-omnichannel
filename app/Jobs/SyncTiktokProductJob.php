<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\TiktokService;
use App\Http\Controllers\TikTokController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncTiktokProductJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public $tries = 3;
    public $timeout = 300;
    public $uniqueFor = 1800;
    public $storeId;

    public function __construct($storeId = null)
    {
        $this->storeId = $storeId;
    }

    public function uniqueId(): string
    {
        return (string) ($this->storeId ?? 'all');
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
        $controller = new TikTokController();
        
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

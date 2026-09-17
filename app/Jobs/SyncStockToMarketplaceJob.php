<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\SkuSyncMember;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use Illuminate\Support\Facades\Log;

class SyncStockToMarketplaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    
    protected $memberId;
    protected $stock;

    /**
     * Create a new job instance.
     */
    public function __construct(int $memberId, int $stock)
    {
        $this->memberId = $memberId;
        $this->stock = $stock;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $member = SkuSyncMember::with('store')->find($this->memberId);
        
        if (!$member || !$member->store) {
            Log::warning("SyncStockToMarketplaceJob: Member {$this->memberId} or store not found.");
            return;
        }

        $store = $member->store;

        try {
            if ($store->platform === 'Shopee') {
                $shopeeService = new ShopeeService();
                $modelId = $member->platform_variant_id ?? '0';
                $shopeeService->updateStock($store, $member->platform_product_id, $modelId, $this->stock);
            } elseif ($store->platform === 'Tiktokshop') {
                $tiktokService = new TiktokService();
                $tiktokService->updateInventory($store, $member->platform_product_id, $member->platform_variant_id, $this->stock);
            }

            // Update last_synced_at timestamp on the group
            $group = $member->group;
            if ($group) {
                $group->last_synced_at = now();
                $group->save();
            }

            Log::info("SyncStockToMarketplaceJob: Successfully pushed stock {$this->stock} for member {$this->memberId}");
        } catch (\Exception $e) {
            Log::error("SyncStockToMarketplaceJob: Error pushing stock for member {$this->memberId}: " . $e->getMessage());
            throw $e;
        }
    }
}

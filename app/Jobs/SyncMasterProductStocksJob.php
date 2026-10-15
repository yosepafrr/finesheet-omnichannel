<?php

namespace App\Jobs;

use App\Models\MasterProductVariant;
use App\Services\BundleInventoryService;
use App\Services\MasterSkuSyncService;
use App\Services\StockSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMasterProductStocksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public array $variantIds) {}

    public function uniqueId(): string
    {
        $ids = $this->variantIds;
        sort($ids);

        return hash('sha256', implode(',', $ids));
    }

    public function handle(
        MasterSkuSyncService $skuSync,
        StockSyncService $stockSync,
        BundleInventoryService $bundleInventory
    ): void {
        $variants = MasterProductVariant::query()
            ->whereIn('id', $this->variantIds)
            ->with('masterProduct')
            ->orderBy('id')
            ->get();

        $variants->each(function (MasterProductVariant $variant) use ($skuSync, $stockSync, $bundleInventory) {
            if ($variant->is_bundle) {
                $bundleInventory->refreshBundle($variant);

                return;
            }

            $group = $skuSync->syncVariant($variant);
            if ($group) {
                $stockSync->setMasterStock($group, (int) $variant->stock);
            }
        });

        $bundleInventory->refreshAffectedByVariantIds(
            $variants->where('is_bundle', false)->pluck('id')
        );
    }
}

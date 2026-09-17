<?php

namespace App\Services;

use App\Models\SkuSyncGroup;
use App\Models\SkuSyncMember;
use App\Models\VariantProduct;
use App\Jobs\SyncStockToMarketplaceJob;
use Illuminate\Support\Facades\Log;

class StockSyncService
{
    /**
     * Deduct stock from the master stock of a group, and dispatch jobs to update marketplaces.
     */
    public function deductStock(SkuSyncGroup $group, int $qty)
    {
        if (!$group->is_active) {
            return;
        }

        // Deduct the master stock
        $newStock = $group->master_stock - $qty;
        if ($newStock < 0) {
            $newStock = 0;
        }

        $group->master_stock = $newStock;
        $group->save();

        Log::info("StockSyncService: Deducted stock for group {$group->id} (SKU: {$group->sku}). New stock: {$newStock}");

        // Update all members locally and dispatch jobs
        foreach ($group->members as $member) {
            // Update local DB
            if ($member->variant_product_id) {
                $variant = $member->variant;
                if ($variant) {
                    $variant->stock = $newStock;
                    $variant->save();
                }
            } else {
                $product = $member->product;
                if ($product) {
                    $product->stock = $newStock;
                    $product->save();
                }
            }

            // Dispatch job to update marketplace
            dispatch(new SyncStockToMarketplaceJob($member->id, $newStock))->onQueue('products');
        }
    }

    /**
     * Set master stock manually (e.g. from UI) and push to all marketplaces.
     */
    public function setMasterStock(SkuSyncGroup $group, int $stock)
    {
        if ($stock < 0) $stock = 0;

        $group->master_stock = $stock;
        $group->save();

        Log::info("StockSyncService: Set master stock for group {$group->id} (SKU: {$group->sku}) to {$stock}");

        if (!$group->is_active) {
            return;
        }

        // Update all members locally and dispatch jobs
        foreach ($group->members as $member) {
            // Update local DB
            if ($member->variant_product_id) {
                $variant = $member->variant;
                if ($variant) {
                    $variant->stock = $stock;
                    $variant->save();
                }
            } else {
                $product = $member->product;
                if ($product) {
                    $product->stock = $stock;
                    $product->save();
                }
            }

            // Dispatch job to update marketplace
            dispatch(new SyncStockToMarketplaceJob($member->id, $stock))->onQueue('products');
        }
    }
}

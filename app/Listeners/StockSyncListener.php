<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Models\SkuSyncGroup;
use App\Models\VariantProduct;
use App\Services\StockSyncService;
use Illuminate\Support\Facades\Log;

class StockSyncListener
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;
        $order->load('orderProducts');
        
        $userId = $event->resolveUserId();
        
        if (!$userId) {
            return;
        }

        $stockSyncService = new StockSyncService();

        foreach ($order->orderProducts as $item) {
            $skuToSync = null;

            // Find variant product to get model_sku as requested by user
            if ($item->product_id && $item->model_name) {
                // Usually we can query VariantProduct by product_id and model_name
                $product = \App\Models\Product::where('product_id', $item->product_id)->first();
                if ($product) {
                    $variant = VariantProduct::where('product_id', $product->id)
                        ->where('model_name', $item->model_name)
                        ->first();
                    
                    if ($variant && !empty($variant->model_sku)) {
                        $skuToSync = $variant->model_sku;
                    }
                }
            }

            // Fallback: If we can't find model_sku, we could fallback to product_sku but user specified model_sku.
            // If they want only model_sku, we continue. If they also want product_sku fallback for single variant products:
            if (empty($skuToSync)) {
                $product = \App\Models\Product::where('product_id', $item->product_id)->first();
                if ($product && !empty($product->product_sku)) {
                    $skuToSync = $product->product_sku;
                }
            }

            if ($skuToSync) {
                // Find active group for this SKU and user
                $group = SkuSyncGroup::where('user_id', $userId)
                    ->where('sku', $skuToSync)
                    ->where('is_active', true)
                    ->first();

                if ($group) {
                    $qty = (int)$item->quantity_purchased;
                    if ($qty > 0) {
                        Log::info("StockSyncListener: Deducting {$qty} for SKU {$skuToSync} (Group ID: {$group->id}) due to Order {$order->order_sn}");
                        $stockSyncService->deductStock($group, $qty);
                    }
                }
            }
        }
    }
}

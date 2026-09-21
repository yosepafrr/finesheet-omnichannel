<?php

namespace App\Listeners;

use App\Events\OrderStockSyncRequested;
use App\Models\Product;
use App\Models\SkuSyncGroup;
use App\Models\VariantProduct;
use App\Services\StockSyncService;
use Illuminate\Support\Facades\DB;
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
    public function handle(OrderStockSyncRequested $event): void
    {
        $status = strtoupper(trim((string) $event->order->order_status));
        if (! in_array($status, ['READY_TO_SHIP', 'PROCESSED', 'AWAITING_SHIPMENT', 'AWAITING_COLLECTION'], true)) {
            return;
        }

        DB::transaction(function () use ($event) {
            $order = $event->order->newQuery()
                ->with(['orderProducts', 'store'])
                ->lockForUpdate()
                ->find($event->order->id);

            if (! $order || $order->stock_sync_processed_at || $order->orderProducts->isEmpty()) {
                return;
            }

            $userId = $order->store?->user_id;
            if (! $userId) {
                return;
            }

            $stockSyncService = app(StockSyncService::class);
            $stockChanges = [];

            foreach ($order->orderProducts as $item) {
                $product = Product::query()
                    ->where('store_id', $order->store_id)
                    ->where('product_id', $item->product_id)
                    ->first();

                if (! $product) {
                    Log::info('StockSyncListener: Waiting for product synchronization', [
                        'order_sn' => $order->order_sn,
                        'platform_product_id' => $item->product_id,
                    ]);

                    return;
                }

                $variant = null;
                if ($item->model_name) {
                    $variant = VariantProduct::query()
                        ->where('product_id', $product->id)
                        ->where(function ($query) use ($item) {
                            $query->where('model_name', $item->model_name)
                                ->orWhere('variant_name', $item->model_name);
                        })
                        ->first();
                }

                $skuToSync = $variant?->model_sku ?: $product->product_sku;
                if (! $skuToSync) {
                    continue;
                }

                $group = SkuSyncGroup::query()
                    ->where('user_id', $userId)
                    ->where('sku', $skuToSync)
                    ->where('is_active', true)
                    ->first();

                $quantity = (int) $item->quantity_purchased;
                if ($group && $quantity > 0) {
                    if (! isset($stockChanges[$group->id])) {
                        $stockChanges[$group->id] = [
                            'group' => $group,
                            'sku' => $skuToSync,
                            'quantity' => 0,
                        ];
                    }

                    $stockChanges[$group->id]['quantity'] += $quantity;
                }
            }

            ksort($stockChanges);

            foreach ($stockChanges as $change) {
                Log::info('StockSyncListener: Deducting stock for order', [
                    'order_sn' => $order->order_sn,
                    'group_id' => $change['group']->id,
                    'sku' => $change['sku'],
                    'quantity' => $change['quantity'],
                ]);

                $stockSyncService->deductStock($change['group'], $change['quantity']);
            }

            $order->updateQuietly(['stock_sync_processed_at' => now()]);
        });
    }
}

<?php

namespace App\Listeners;

use App\Events\OrderStockSyncRequested;
use App\Models\OrderProduct;
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

                [$skuToSync, $shouldRetry] = $this->resolveSku($product, $item);
                if ($shouldRetry) {
                    Log::warning('StockSyncListener: Variant identity has not been synchronized', [
                        'order_sn' => $order->order_sn,
                        'platform_product_id' => $item->product_id,
                        'platform_variant_id' => $item->platform_variant_id,
                        'seller_sku' => $item->sku,
                        'model_name' => $item->model_name,
                    ]);

                    return;
                }

                if (! $skuToSync) {
                    continue;
                }

                $group = SkuSyncGroup::query()
                    ->where('user_id', $userId)
                    ->whereRaw('LOWER(sku) = ?', [mb_strtolower(trim($skuToSync))])
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

    /**
     * Resolve a marketplace order item to the SKU used by a master stock group.
     * Stable platform identifiers are preferred; names only support legacy rows.
     */
    private function resolveSku(Product $product, OrderProduct $item): array
    {
        $variantQuery = VariantProduct::query()->where('product_id', $product->id);
        $variant = null;

        if ($item->platform_variant_id) {
            $variant = (clone $variantQuery)
                ->where('model_id', $item->platform_variant_id)
                ->first();
        }

        if (! $variant && $this->normalizeSku($item->sku)) {
            $variant = (clone $variantQuery)
                ->whereRaw('LOWER(model_sku) = ?', [mb_strtolower(trim($item->sku))])
                ->first();
        }

        $modelName = trim((string) $item->model_name);
        if (! $variant && $modelName !== '' && strcasecmp($modelName, 'without variant') !== 0) {
            $variant = (clone $variantQuery)
                ->where(function ($query) use ($modelName) {
                    $query->where('model_name', $modelName)
                        ->orWhere('variant_name', $modelName);
                })
                ->first();
        }

        $sku = $this->normalizeSku($variant?->model_sku)
            ?? $this->normalizeSku($item->sku);
        if ($sku) {
            return [$sku, false];
        }

        $hasVariantIdentity = $item->platform_variant_id
            || ($modelName !== '' && strcasecmp($modelName, 'without variant') !== 0);
        if ($hasVariantIdentity && $product->variantProducts()->exists()) {
            return [null, true];
        }

        return [$this->normalizeSku($product->product_sku), false];
    }

    private function normalizeSku(mixed $sku): ?string
    {
        $sku = trim((string) $sku);

        return $sku !== '' && $sku !== '0' ? $sku : null;
    }
}

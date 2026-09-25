<?php

namespace App\Listeners;

use App\Events\OrderStockSyncRequested;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\SkuSyncGroup;
use App\Models\VariantProduct;
use App\Services\StockSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockSyncListener
{
    private const DEDUCTIBLE_STATUSES = [
        'READY_TO_SHIP',
        'PROCESSED',
        'AWAITING_SHIPMENT',
        'AWAITING_COLLECTION',
    ];

    private const FINAL_CANCELLATION_STATUSES = [
        'CANCEL',
        'CANCELLED',
    ];

    private const SHIPPED_STATUSES = [
        'SHIPPED',
        'IN_TRANSIT',
        'DELIVERED',
        'TO_CONFIRM_RECEIVE',
        'COMPLETED',
        'TO_RETURN',
        'RETURNED',
    ];

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

        if (in_array($status, self::FINAL_CANCELLATION_STATUSES, true)) {
            $this->restoreCancelledOrder($event);

            return;
        }

        if (in_array($status, self::SHIPPED_STATUSES, true)) {
            $this->markOrderAsShipped($event);

            return;
        }

        if (! in_array($status, self::DEDUCTIBLE_STATUSES, true)) {
            return;
        }

        $this->deductOrderStock($event);
    }

    private function deductOrderStock(OrderStockSyncRequested $event): void
    {
        DB::transaction(function () use ($event) {
            $order = $event->order->newQuery()
                ->with(['orderProducts', 'store'])
                ->lockForUpdate()
                ->find($event->order->id);

            if (! $order || $order->stock_sync_processed_at || $order->orderProducts->isEmpty()) {
                return;
            }

            $currentStatus = strtoupper(trim((string) $order->order_status));
            if (! in_array($currentStatus, self::DEDUCTIBLE_STATUSES, true)) {
                return;
            }

            $userId = $order->store?->user_id;
            if (! $userId) {
                return;
            }

            $stockSyncService = app(StockSyncService::class);
            $stockChanges = $this->resolveStockChanges($order, $userId);
            if ($stockChanges === null) {
                return;
            }

            $deductions = [];

            foreach ($stockChanges as $change) {
                Log::info('StockSyncListener: Deducting stock for order', [
                    'order_sn' => $order->order_sn,
                    'group_id' => $change['group']->id,
                    'sku' => $change['sku'],
                    'quantity' => $change['quantity'],
                ]);

                $result = $stockSyncService->deductStockWithResult(
                    $change['group'],
                    $change['quantity'],
                );

                if ($result['deducted_quantity'] > 0) {
                    $deductions[] = [
                        'group_id' => $change['group']->id,
                        'sku' => $change['sku'],
                        'quantity' => $result['deducted_quantity'],
                    ];
                }
            }

            $order->updateQuietly([
                'stock_sync_processed_at' => now(),
                'stock_sync_deductions' => $deductions,
            ]);
        });
    }

    private function restoreCancelledOrder(OrderStockSyncRequested $event): void
    {
        DB::transaction(function () use ($event) {
            $order = $event->order->newQuery()
                ->with(['orderProducts', 'store', 'packages'])
                ->lockForUpdate()
                ->find($event->order->id);

            if (
                ! $order
                || ! $order->stock_sync_processed_at
                || $order->stock_sync_reverted_at
                || $order->stock_sync_shipped_at
            ) {
                return;
            }

            $currentStatus = strtoupper(trim((string) $order->order_status));
            if (! in_array($currentStatus, self::FINAL_CANCELLATION_STATUSES, true)) {
                return;
            }

            if ($order->packages->contains('normalized_logistics_status', 'DELIVERY_FAILED')) {
                Log::info('StockSyncListener: Cancellation belongs to a failed delivery; automatic restock skipped', [
                    'order_sn' => $order->order_sn,
                ]);

                return;
            }

            $userId = $order->store?->user_id;
            if (! $userId) {
                return;
            }

            $deductions = $order->stock_sync_deductions;
            if ($deductions === null) {
                $deductions = $this->reconstructLegacyDeductions($order, $userId);
                if ($deductions === null) {
                    return;
                }
            }

            $restorations = [];
            foreach ($deductions as $deduction) {
                $quantity = max(0, (int) ($deduction['quantity'] ?? 0));
                if ($quantity === 0) {
                    continue;
                }

                $groupId = (int) ($deduction['group_id'] ?? 0);
                $group = SkuSyncGroup::query()
                    ->where('user_id', $userId)
                    ->find($groupId);

                if (! $group) {
                    Log::warning('StockSyncListener: Cannot restore cancelled order because its stock group is missing', [
                        'order_sn' => $order->order_sn,
                        'group_id' => $groupId,
                        'sku' => $deduction['sku'] ?? null,
                    ]);

                    return;
                }

                $restorations[] = [
                    'group' => $group,
                    'quantity' => $quantity,
                ];
            }

            $stockSyncService = app(StockSyncService::class);
            foreach ($restorations as $restoration) {
                $stockSyncService->restoreStock(
                    $restoration['group'],
                    $restoration['quantity'],
                );
            }

            $order->updateQuietly([
                'stock_sync_deductions' => $deductions,
                'stock_sync_reverted_at' => now(),
            ]);

            Log::info('StockSyncListener: Restored stock for cancelled order', [
                'order_sn' => $order->order_sn,
                'groups' => count($restorations),
            ]);
        });
    }

    private function markOrderAsShipped(OrderStockSyncRequested $event): void
    {
        DB::transaction(function () use ($event) {
            $order = $event->order->newQuery()
                ->lockForUpdate()
                ->find($event->order->id);

            if (! $order || ! $order->stock_sync_processed_at || $order->stock_sync_shipped_at) {
                return;
            }

            $currentStatus = strtoupper(trim((string) $order->order_status));
            if (! in_array($currentStatus, self::SHIPPED_STATUSES, true)) {
                return;
            }

            $order->updateQuietly(['stock_sync_shipped_at' => now()]);
        });
    }

    private function reconstructLegacyDeductions(Order $order, int $userId): ?array
    {
        $stockChanges = $this->resolveStockChanges($order, $userId);
        if ($stockChanges === null) {
            return null;
        }

        $deductions = [];
        foreach ($stockChanges as $change) {
            if (
                $change['group']->created_at
                && $change['group']->created_at->gt($order->stock_sync_processed_at)
            ) {
                Log::warning('StockSyncListener: Legacy deduction cannot be reconstructed safely', [
                    'order_sn' => $order->order_sn,
                    'group_id' => $change['group']->id,
                    'sku' => $change['sku'],
                ]);

                return null;
            }

            $deductions[] = [
                'group_id' => $change['group']->id,
                'sku' => $change['sku'],
                'quantity' => $change['quantity'],
            ];
        }

        Log::warning('StockSyncListener: Reconstructed a legacy stock deduction during cancellation', [
            'order_sn' => $order->order_sn,
            'groups' => count($deductions),
        ]);

        return $deductions;
    }

    private function resolveStockChanges(Order $order, int $userId): ?array
    {
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

                return null;
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

                return null;
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

        return $stockChanges;
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

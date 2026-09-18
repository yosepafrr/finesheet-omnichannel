<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PayablePeriod;
use App\Models\PayableEvent;
use App\Models\VariantProduct;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProductMapping;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PayableService
{
    /**
     * Get or create the payable period for a given date, user, and supplier.
     */
    public function getPeriodForDate(Carbon $date, int $userId, Supplier $supplier): ?PayablePeriod
    {
        // 1. Check if there is ANY period (manual or auto) that covers this date for this supplier
        $existingPeriod = PayablePeriod::where('user_id', $userId)
            ->where('supplier_id', $supplier->id)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();

        if ($existingPeriod) {
            return $existingPeriod;
        }

        $firstPeriodStart = $supplier->first_period_start;
        if (!$firstPeriodStart) return null;

        // If the date is before the first period start AND no manual period covers it, ignore it (historical data)
        if ($date->lt($firstPeriodStart)) {
            return null;
        }

        $lengthDays = $supplier->period_length_days ?? 14;

        // Calculate how many periods have passed
        $diffDays = $firstPeriodStart->diffInDays($date, false);
        $periodsPassed = floor($diffDays / $lengthDays);

        $periodStart = $firstPeriodStart->copy()->addDays($periodsPassed * $lengthDays);
        $periodEnd = $periodStart->copy()->addDays($lengthDays)->subSecond();

        $periodName = 'Periode ' . $periodStart->format('d M Y') . ' - ' . $periodEnd->format('d M Y');

        return PayablePeriod::firstOrCreate(
            [
                'user_id' => $userId,
                'supplier_id' => $supplier->id,
                'start_date' => $periodStart
            ],
            [
                'name' => $periodName,
                'end_date' => $periodEnd,
                'payment_status' => 'UNPAID',
                'is_closed' => false,
                'is_manual' => false
            ]
        );
    }

    /**
     * Pre-create ALL consecutive periods from $start up to now for a given user and supplier
     * so there are no gaps even when intermediate periods have no events.
     */
    public function ensureAllPeriods(Carbon $start, int $userId, Supplier $supplier): void
    {
        $firstPeriodStart = $supplier->first_period_start;
        if (!$firstPeriodStart) return;

        $lengthDays = $supplier->period_length_days ?? 14;

        // Figure out what period $start falls in (align to grid)
        if ($start->lt($firstPeriodStart)) {
            $start = $firstPeriodStart->copy();
        }

        $now = Carbon::now(); // only create periods that have already started

        $cursor = $firstPeriodStart->copy();

        while ($cursor->lte($now)) {
            $periodEnd  = $cursor->copy()->addDays($lengthDays)->subSecond();
            $periodName = 'Periode ' . $cursor->format('d M Y') . ' - ' . $periodEnd->format('d M Y');

            PayablePeriod::firstOrCreate(
                [
                    'user_id' => $userId,
                    'supplier_id' => $supplier->id,
                    'start_date' => $cursor->copy()
                ],
                [
                    'name'           => $periodName,
                    'end_date'       => $periodEnd,
                    'payment_status' => 'UNPAID',
                    'is_closed'      => false,
                    'is_manual'      => false,
                ]
            );

            $cursor->addDays($lengthDays);
        }

        Log::info("PayableService::ensureAllPeriods created periods for supplier {$supplier->id} from {$firstPeriodStart} to {$now}");
    }

    /**
     * Calculate HPP for an order
     * Lookup path: order_products.product_id (platform ID) → products.product_id → products.id → variant_products.product_id
     */
    public function calculateOrderHpp(Order $order): float
    {
        $totalHpp = 0;
        foreach ($order->orderProducts as $item) {
            $hpp = $this->getItemHpp($item);
            $totalHpp += $hpp * $item->quantity_purchased;
        }
        return $totalHpp;
    }

    /**
     * Get HPP for a single order product item
     */
    private function getItemHpp($item): float
    {
        // Step 1: Find internal Product by platform product_id
        $product = \App\Models\Product::where('product_id', $item->product_id)->first();

        if ($product) {
            // Step 2a: Try to find variant by model_name (normalize spaces around commas)
            $modelName = $item->model_name ?? '';
            $modelNameNorm = str_replace(', ', ',', $modelName);
            
            $isWithoutVariant = in_array(strtolower($modelName), ['without variant', '']);

            if (!$isWithoutVariant) {
                $variant = \App\Models\VariantProduct::where('product_id', $product->id)
                    ->where(function ($q) use ($modelName, $modelNameNorm) {
                        $q->where('model_name', $modelName)
                          ->orWhere('model_name', $modelNameNorm);
                    })
                    ->first();

                if ($variant && $variant->hpp > 0) return (float)$variant->hpp;
                if ($variant && $variant->price > 0) return (float)$variant->price;
            }

            // Step 2b: Fallback — first variant of this product
            $firstVariant = \App\Models\VariantProduct::where('product_id', $product->id)->first();
            if ($firstVariant) {
                if ($firstVariant->hpp > 0) return (float)$firstVariant->hpp;
                if ($firstVariant->price > 0) return (float)$firstVariant->price;
            }

            // Step 2c: Fallback — use product-level hpp or price
            if ($product->hpp > 0) return (float)$product->hpp;
            if ($product->price > 0) return (float)$product->price;
        }

        // Step 3: Last resort — use order item price directly
        if ($item->price > 0) {
            Log::info("PayableService: Using item price as HPP fallback for product_id={$item->product_id}");
            return (float)$item->price;
        }

        Log::warning("PayableService: Could not determine HPP for product_id={$item->product_id} model_name={$item->model_name}");
        return 0;
    }

    /**
     * Resolve supplier_id for an order item
     */
    public function resolveSupplierIdForItem($item, int $userId): ?int
    {
        // 1. Direct product relation
        $product = \App\Models\Product::where('product_id', $item->product_id)->first();
        if ($product && $product->supplier_id) {
            return $product->supplier_id;
        }

        // 2. Variant model_sku in supplier_product_mappings
        $modelSku = null;
        if ($product) {
            $modelName = $item->model_name ?? '';
            $modelNameNorm = str_replace(', ', ',', $modelName);
            $variant = \App\Models\VariantProduct::where('product_id', $product->id)
                ->where(function ($q) use ($modelName, $modelNameNorm) {
                    $q->where('model_name', $modelName)
                      ->orWhere('model_name', $modelNameNorm);
                })
                ->first();
            if ($variant && !empty($variant->model_sku)) {
                $modelSku = $variant->model_sku;
            }
        }

        if ($modelSku) {
            $mapping = \App\Models\SupplierProductMapping::where('user_id', $userId)
                ->where('sku', $modelSku)
                ->first();
            if ($mapping) {
                if ($product && !$product->supplier_id) {
                    $product->update(['supplier_id' => $mapping->supplier_id]);
                }
                return $mapping->supplier_id;
            }
        }

        // 3. Product product_sku in supplier_product_mappings
        if ($product && !empty($product->product_sku)) {
            $mapping = \App\Models\SupplierProductMapping::where('user_id', $userId)
                ->where('sku', $product->product_sku)
                ->first();
            if ($mapping) {
                if (!$product->supplier_id) {
                    $product->update(['supplier_id' => $mapping->supplier_id]);
                }
                return $mapping->supplier_id;
            }
        }

        // 4. Platform product_id in supplier_product_mappings
        $mapping = \App\Models\SupplierProductMapping::where('user_id', $userId)
            ->where('platform_product_id', (string)$item->product_id)
            ->first();
        if ($mapping) {
            if ($product && !$product->supplier_id) {
                $product->update(['supplier_id' => $mapping->supplier_id]);
            }
            return $mapping->supplier_id;
        }

        // 5. If user has only 1 supplier, auto-assign to that 1 supplier
        $userSuppliers = \App\Models\Supplier::where('user_id', $userId)->pluck('id');
        if ($userSuppliers->count() === 1) {
            $singleSupplierId = $userSuppliers->first();
            if ($product && !$product->supplier_id) {
                $product->update(['supplier_id' => $singleSupplierId]);
            }
            return $singleSupplierId;
        }

        return null;
    }

    /**
     * Record an order creation event
     */
    public function recordOrderEvent(Order $order)
    {
        Log::info("PayableService::recordOrderEvent called for Order " . $order->order_sn);
        
        $statusUpper = strtoupper(trim($order->order_status ?? ''));

        // If order is cancelled, remove the CREATE_ORDER event entirely (as if it never happened).
        // Only orders that have actually been shipped/completed are valid payable debts.
        if (in_array($statusUpper, ['CANCEL', 'CANCELLED', 'IN_CANCEL'])) {
            PayableEvent::where('source_id', $order->order_sn)
                ->whereIn('source_type', ['CREATE_ORDER', 'FAILED_DELIVERY', 'BUYER_CANCEL'])
                ->delete();
            Log::info("PayableService: Deleted CREATE_ORDER events for cancelled order " . $order->order_sn);
            return;
        }

        // Skip orders that are UNPAID, UNKNOWN, or ON_HOLD
        if (empty($statusUpper) || in_array($statusUpper, ['UNPAID', 'UNKNOWN', 'ON_HOLD'])) {
            PayableEvent::where('source_id', $order->order_sn)
                ->where('source_type', 'CREATE_ORDER')
                ->delete();
            return;
        }

        $userId = $order->store?->user_id ?? \App\Models\Store::where('id', $order->store_id)->value('user_id');
        if (!$userId) {
            Log::warning("PayableService: Could not determine user_id for Order {$order->order_sn} (store_id={$order->store_id})");
            return;
        }

        $date = $order->order_time;
        if (!$date) return;

        // Group order products by resolved supplier
        $orderProducts = $order->orderProducts;
        $itemsBySupplier = [];

        if ($orderProducts->isEmpty()) {
            $singleSupplierId = \App\Models\Supplier::where('user_id', $userId)->value('id');
            $itemsBySupplier[$singleSupplierId] = [];
        } else {
            foreach ($orderProducts as $item) {
                $supplierId = $this->resolveSupplierIdForItem($item, $userId);
                $itemsBySupplier[$supplierId][] = $item;
            }
        }

        $recordedSupplierIds = [];

        foreach ($itemsBySupplier as $supplierId => $items) {
            $actualSupplierId = !empty($supplierId) ? (int)$supplierId : null;
            if (!$actualSupplierId) continue;
            
            $supplier = \App\Models\Supplier::find($actualSupplierId);
            if (!$supplier) continue;
            
            $period = $this->getPeriodForDate($date, $userId, $supplier);
            if (!$period) continue; // Before first period

            $groupHpp = 0;
            if (empty($items)) {
                $groupHpp = $this->calculateOrderHpp($order);
            } else {
                foreach ($items as $item) {
                    $hpp = $this->getItemHpp($item);
                    $groupHpp += $hpp * $item->quantity_purchased;
                }
            }

            if ($groupHpp <= 0) continue;

            $recordedSupplierIds[] = $actualSupplierId;

            $existingEvent = PayableEvent::where('source_id', $order->order_sn)
                ->where('source_type', 'CREATE_ORDER')
                ->where(function ($q) use ($actualSupplierId) {
                    if ($actualSupplierId) {
                        $q->where('supplier_id', $actualSupplierId);
                    } else {
                        $q->whereNull('supplier_id');
                    }
                })
                ->first();

            // Match legacy event if migrating
            if (!$existingEvent && $actualSupplierId) {
                $legacy = PayableEvent::where('source_id', $order->order_sn)
                    ->where('source_type', 'CREATE_ORDER')
                    ->whereNull('supplier_id')
                    ->first();
                if ($legacy && count($itemsBySupplier) === 1) {
                    $existingEvent = $legacy;
                }
            }

            $periodId = ($existingEvent && $existingEvent->is_manual_moved) 
                ? $existingEvent->payable_period_id 
                : $period->id;

            PayableEvent::updateOrCreate(
                [
                    'source_id' => $order->order_sn,
                    'source_type' => 'CREATE_ORDER',
                    'supplier_id' => $actualSupplierId,
                ],
                [
                    'user_id' => $userId,
                    'payable_period_id' => $periodId,
                    'store_id' => $order->store_id,
                    'platform' => $order->platform,
                    'event_date' => $date,
                    'amount' => $groupHpp, // positive
                ]
            );
        }

        // Clean up any stale supplier events for this order
        $cleanup = PayableEvent::where('source_id', $order->order_sn)
            ->where('source_type', 'CREATE_ORDER');
        if (!in_array(null, $recordedSupplierIds, true)) {
            $cleanup->where(function ($q) use ($recordedSupplierIds) {
                $q->whereNotIn('supplier_id', $recordedSupplierIds)
                  ->orWhereNull('supplier_id');
            })->delete();
        } else {
            $nonNull = array_filter($recordedSupplierIds);
            $cleanup->whereNotNull('supplier_id')->whereNotIn('supplier_id', $nonNull)->delete();
        }
    }

    /**
     * Record a return event
     */
    public function recordReturnEvent(OrderReturn $return, string $type = 'RETURN_ORDER')
    {
        $date = $return->created_at_platform ?? $return->updated_at_platform ?? $return->created_at;
        
        $order = $return->order ?? Order::find($return->order_id);
        $orderSn = $order?->order_sn ?? $return->external_return_id;

        $userId = $order?->store?->user_id ?? ($order ? \App\Models\Store::where('id', $order->store_id)->value('user_id') : null);
        if (!$userId) return;

        // Group returns by supplier
        $returnsBySupplier = [];

        if ($order) {
            $orderReturns = OrderReturn::with('items')->where('order_id', $order->id)->get();
            foreach ($orderReturns as $ret) {
                foreach ($ret->items as $item) {
                    $variant = VariantProduct::where('model_id', $item->sku_id)
                        ->orWhere('model_sku', $item->sku_id)
                        ->first();
                        
                    $product = $variant ? $variant->product : Product::where('product_id', $item->sku_id)->first();
                    $supplierId = $product?->supplier_id;
                    if (!$supplierId && $variant) {
                        $supplierId = $this->resolveSupplierIdForItem((object)['product_id' => $product?->product_id, 'model_name' => $variant->model_name], $userId);
                    }

                    if ($variant && $variant->hpp > 0) {
                        $returnsBySupplier[$supplierId] = ($returnsBySupplier[$supplierId] ?? 0) + ($variant->hpp * $item->quantity);
                    }
                }
            }
        } else {
            foreach ($return->items as $item) {
                $variant = VariantProduct::where('model_id', $item->sku_id)
                    ->orWhere('model_sku', $item->sku_id)
                    ->first();
                $product = $variant ? $variant->product : Product::where('product_id', $item->sku_id)->first();
                $supplierId = $product?->supplier_id;
                
                if ($variant && $variant->hpp > 0) {
                    $returnsBySupplier[$supplierId] = ($returnsBySupplier[$supplierId] ?? 0) + ($variant->hpp * $item->quantity);
                }
            }
        }

        // Fallback: If return items don't have supplier, distribute from CREATE_ORDER events
        if (empty($returnsBySupplier) && $orderSn) {
            $createEvents = PayableEvent::where('source_id', $orderSn)
                ->where('source_type', 'CREATE_ORDER')
                ->get();
            if ($createEvents->isNotEmpty()) {
                foreach ($createEvents as $ce) {
                    $returnsBySupplier[$ce->supplier_id] = (float)$ce->amount;
                }
            } else {
                $singleSupplierId = \App\Models\Supplier::where('user_id', $userId)->value('id');
                $returnsBySupplier[$singleSupplierId] = $this->calculateOrderHpp($order);
            }
        }

        if ($orderSn && !empty($returnsBySupplier)) {
            // Delete legacy event where source_id was external_return_id
            if ($return->external_return_id && $return->external_return_id !== $orderSn) {
                PayableEvent::where('source_id', (string)$return->external_return_id)
                    ->where('source_type', $type)
                    ->delete();
            }

            // Also delete duplicate FAILED_DELIVERY
            PayableEvent::where('source_id', (string)$orderSn)
                ->where('source_type', 'FAILED_DELIVERY')
                ->delete();

            $recordedSupplierIds = [];
            foreach ($returnsBySupplier as $supplierId => $retHpp) {
                if ($retHpp <= 0) continue;

                $actualSupplierId = !empty($supplierId) ? (int)$supplierId : null;
                if (!$actualSupplierId) continue;
                
                $supplier = \App\Models\Supplier::find($actualSupplierId);
                if (!$supplier) continue;
                
                $period = $this->getPeriodForDate($date, $userId, $supplier);
                if (!$period) continue;

                $recordedSupplierIds[] = $actualSupplierId;

                $existingEvent = PayableEvent::where('source_id', $orderSn)
                    ->where('source_type', $type)
                    ->where(function ($q) use ($actualSupplierId) {
                        if ($actualSupplierId) $q->where('supplier_id', $actualSupplierId);
                        else $q->whereNull('supplier_id');
                    })
                    ->first();

                $periodId = ($existingEvent && $existingEvent->is_manual_moved) 
                    ? $existingEvent->payable_period_id 
                    : $period->id;

                PayableEvent::updateOrCreate(
                    [
                        'source_id' => $orderSn,
                        'source_type' => $type,
                        'supplier_id' => $actualSupplierId,
                    ],
                    [
                        'user_id' => $userId,
                        'payable_period_id' => $periodId,
                        'store_id' => $order?->store_id ?? $return->order?->store_id,
                        'platform' => $return->platform,
                        'event_date' => $date,
                        'amount' => -$retHpp, // negative
                        'notes' => $return->external_return_id ? ('Return ID: ' . $return->external_return_id) : null,
                    ]
                );
            }

            // Clean up any stale return events for this return/order
            $cleanup = PayableEvent::where('source_id', $orderSn)
                ->where('source_type', $type);
            if (!in_array(null, $recordedSupplierIds, true)) {
                $cleanup->where(function ($q) use ($recordedSupplierIds) {
                    $q->whereNotIn('supplier_id', $recordedSupplierIds)
                      ->orWhereNull('supplier_id');
                })->delete();
            } else {
                $nonNull = array_filter($recordedSupplierIds);
                $cleanup->whereNotNull('supplier_id')->whereNotIn('supplier_id', $nonNull)->delete();
            }
        }
    }
    
    /**
     * Record a cancellation event.
     *
     * LOGIC:
     * - True cancellation (CANCEL/CANCELLED/IN_CANCEL): The order never reached the supplier,
     *   so we DELETE the CREATE_ORDER event entirely. No reduction event is created.
     * - Failed delivery (DELIVERY_FAILED on a SHIPPED/COMPLETED order): The order WAS shipped,
     *   so the CREATE_ORDER event stays and we add a FAILED_DELIVERY reduction.
     */
    public function recordCancellationEvent(Order $order, string $type = 'FAILED_DELIVERY')
    {
        $statusUpper = strtoupper(trim($order->order_status ?? ''));
        $isTrueCancellation = in_array($statusUpper, ['CANCEL', 'CANCELLED', 'IN_CANCEL']);

        if ($isTrueCancellation) {
            // True cancellation: remove CREATE_ORDER completely (order never materialized into a debt)
            PayableEvent::where('source_id', $order->order_sn)
                ->whereIn('source_type', ['CREATE_ORDER', 'FAILED_DELIVERY', 'BUYER_CANCEL'])
                ->delete();
            Log::info("PayableService: Deleted payable events for truly cancelled order " . $order->order_sn);
            return;
        }

        // Below: handle FAILED_DELIVERY on a shipped/completed order only
        $hasFailedPackage = $order->packages()->where('normalized_logistics_status', 'DELIVERY_FAILED')->exists();
        if (!$hasFailedPackage) {
            return;
        }

        // Do not record if order was never in payable (no CREATE_ORDER event)
        $createEvents = PayableEvent::where('source_id', $order->order_sn)
            ->where('source_type', 'CREATE_ORDER')
            ->get();

        if ($createEvents->isEmpty()) {
            return;
        }

        // If order already has a return, return takes precedence over failed delivery
        $hasReturn = PayableEvent::where('source_id', $order->order_sn)
            ->where('source_type', 'RETURN_ORDER')
            ->exists() || OrderReturn::where('order_id', $order->id)->exists();

        if ($hasReturn) {
            return;
        }

        $userId = $order->store?->user_id ?? \App\Models\Store::where('id', $order->store_id)->value('user_id');
        if (!$userId) return;

        $date = $order->updated_at;
        $recordedSupplierIds = [];

        foreach ($createEvents as $createEvent) {
            $actualSupplierId = !empty($createEvent->supplier_id) ? (int)$createEvent->supplier_id : null;
            if (!$actualSupplierId) continue;
            
            $supplier = \App\Models\Supplier::find($actualSupplierId);
            if (!$supplier) continue;
            
            $period = $this->getPeriodForDate($date, $userId, $supplier);
            if (!$period) continue;

            $recordedSupplierIds[] = $actualSupplierId;

            $existingEvent = PayableEvent::where('source_id', $order->order_sn)
                ->where('source_type', $type)
                ->where(function ($q) use ($actualSupplierId) {
                    if ($actualSupplierId) $q->where('supplier_id', $actualSupplierId);
                    else $q->whereNull('supplier_id');
                })
                ->first();

            $periodId = ($existingEvent && $existingEvent->is_manual_moved) 
                ? $existingEvent->payable_period_id 
                : $period->id;

            PayableEvent::updateOrCreate(
                [
                    'source_id' => $order->order_sn,
                    'source_type' => $type,
                    'supplier_id' => $actualSupplierId,
                ],
                [
                    'user_id' => $userId,
                    'payable_period_id' => $periodId,
                    'store_id' => $order->store_id,
                    'platform' => $order->platform,
                    'event_date' => $date,
                    'amount' => -abs((float)$createEvent->amount),
                ]
            );
        }

        // Clean up any stale cancellation events for this order
        $cleanup = PayableEvent::where('source_id', $order->order_sn)
            ->where('source_type', $type);
        if (!in_array(null, $recordedSupplierIds, true)) {
            $cleanup->where(function ($q) use ($recordedSupplierIds) {
                $q->whereNotIn('supplier_id', $recordedSupplierIds)
                  ->orWhereNull('supplier_id');
            })->delete();
        } else {
            $nonNull = array_filter($recordedSupplierIds);
            $cleanup->whereNotNull('supplier_id')->whereNotIn('supplier_id', $nonNull)->delete();
        }
    }

    /**
     * Synchronously sync all payable events for a user
     */
    public function syncPayableForUser(int $userId, ?string $startDate = null): void
    {
        $start = $startDate ? Carbon::parse($startDate) : null;
        
        $userStores = \App\Models\Store::where('user_id', $userId)->pluck('id');
        if ($userStores->isEmpty()) return;

        // Step 1: Pre-create ALL consecutive periods for this user's suppliers
        $suppliers = \App\Models\Supplier::where('user_id', $userId)->get();
        foreach ($suppliers as $supplier) {
            $supplierStart = $start ?? $supplier->first_period_start;
            if ($supplierStart) {
                $this->ensureAllPeriods($supplierStart, $userId, $supplier);
            }
        }

        // Step 2: Sync order events for this user's stores
        $ordersQuery = Order::with('orderProducts')
            ->whereIn('store_id', $userStores)
            ->whereNotNull('order_status')
            ->whereNotIn(\Illuminate\Support\Facades\DB::raw('UPPER(order_status)'), ['UNPAID', 'UNKNOWN', 'ON_HOLD', '']);
            
        if ($start) {
            $ordersQuery->where('order_time', '>=', $start);
        }
        
        $orders = $ordersQuery->get();

        foreach ($orders as $order) {
            $this->recordOrderEvent($order);
            $this->recordCancellationEvent($order, 'FAILED_DELIVERY');
        }

        // Step 3: Sync return events for this user's stores
        $returns = OrderReturn::with('items', 'order')
            ->whereHas('order', function ($q) use ($userStores) {
                $q->whereIn('store_id', $userStores);
            })
            ->where(function ($q) {
                $q->whereNotNull('updated_at_platform')
                  ->orWhereNotNull('created_at_platform');
            })
            ->get();

        foreach ($returns as $return) {
            $date = $return->created_at_platform ?? $return->updated_at_platform ?? $return->created_at;
            if (!$start || Carbon::parse($date)->gte($start)) {
                $this->recordReturnEvent($return);
            }
        }

        // Step 4: Clean up any stale null supplier events if user has suppliers
        $hasSuppliers = Supplier::where('user_id', $userId)->exists();
        if ($hasSuppliers) {
            PayableEvent::where('user_id', $userId)
                ->whereNull('supplier_id')
                ->whereIn('source_id', function ($query) use ($userId) {
                    $query->select('source_id')
                        ->from('payable_events')
                        ->where('user_id', $userId)
                        ->whereNotNull('supplier_id');
                })
                ->delete();
        }
    }
}

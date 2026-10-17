<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\MasterProductVariant;
use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\OrderProduct;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\PayableEvent;
use App\Models\PayablePeriod;
use App\Models\Product;
use App\Models\SkuSyncGroup;
use App\Models\SkuSyncMember;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierProductMapping;
use App\Models\User;
use App\Models\VariantProduct;
use App\Services\PayableService;
use App\Services\ProductHppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHppSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_listing_reads_and_updates_master_hpp(): void
    {
        [, $variant, $masterVariant] = $this->createLinkedVariant(localHpp: 25000, masterHpp: 18000);
        $service = app(ProductHppService::class);

        $this->assertSame([
            'hpp' => 18000.0,
            'source' => 'master',
            'master_variant_id' => $masterVariant->id,
        ], $service->variantDetails($variant));

        $result = $service->updateVariant($variant, 21000);

        $this->assertSame('master', $result['source']);
        $this->assertSame(21000.0, $result['hpp']);
        $this->assertSame('21000.00', $masterVariant->fresh()->hpp);
        $this->assertSame(25000, (int) $variant->fresh()->hpp);
    }

    public function test_unlinked_listing_keeps_marketplace_hpp_as_fallback(): void
    {
        [$product, $variant] = $this->createMarketplaceVariant(localHpp: 25000);
        $service = app(ProductHppService::class);

        $result = $service->updateVariant($variant, 27000);

        $this->assertSame('marketplace', $result['source']);
        $this->assertSame(27000.0, $result['hpp']);
        $this->assertSame(27000, (int) $variant->fresh()->hpp);
        $this->assertSame($product->id, $variant->product_id);
    }

    public function test_payable_calculation_uses_master_hpp_for_linked_sku(): void
    {
        [$product] = $this->createLinkedVariant(localHpp: 25000, masterHpp: 18000);
        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $product->store_id,
            'platform' => 'Shopee',
            'order_sn' => 'ORDER-HPP-MASTER',
            'order_status' => 'READY_TO_SHIP',
            'order_time' => now(),
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => 'Black',
            'platform_variant_id' => 'variant-1',
            'sku' => 'SKU-HPP',
            'quantity_purchased' => 2,
            'price' => 50000,
        ]));

        $this->assertSame(36000.0, app(PayableService::class)->calculateOrderHpp(
            $order->fresh('orderProducts')
        ));
    }

    public function test_order_list_uses_zero_hpp_and_escrow_as_profit_when_hpp_is_unavailable(): void
    {
        [$product, $variant, $user] = $this->createMarketplaceVariant(localHpp: 0);
        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $product->store_id,
            'platform' => 'Shopee',
            'order_sn' => 'ORDER-HPP-UNAVAILABLE',
            'order_status' => 'SHIPPED',
            'order_time' => now(),
            'escrow_amount' => 100000,
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 2,
            'price' => 50000,
        ]));

        $this->actingAs($user)
            ->getJson('/api/orders?statuses=SHIPPED')
            ->assertOk()
            ->assertJsonPath('orders.0.hpp_amount', 0)
            ->assertJsonPath('orders.0.escrow_amount', 100000)
            ->assertJsonPath('orders.0.profit_amount', 100000)
            ->assertJsonPath('orders.0.has_missing_hpp', true)
            ->assertJsonPath('totals.hpp_amount', 0)
            ->assertJsonPath('totals.profit_amount', 100000)
            ->assertJsonPath('totals.has_missing_hpp', true);
    }

    public function test_payable_prefers_master_supplier_and_falls_back_to_marketplace_mapping(): void
    {
        [$product, $variant, $masterVariant, $user] = $this->createLinkedVariant(25000, 18000);
        $masterSupplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Master',
            'period_length_days' => 14,
        ]);
        $marketplaceSupplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Marketplace',
            'period_length_days' => 14,
        ]);
        SupplierProductMapping::create([
            'user_id' => $user->id,
            'supplier_id' => $marketplaceSupplier->id,
            'sku' => strtolower($variant->model_sku),
            'product_id' => $product->id,
            'platform_product_id' => (string) $product->product_id,
        ]);
        $item = (object) [
            'product_id' => $product->product_id,
            'platform_variant_id' => $variant->model_id,
            'model_name' => $variant->model_name,
            'sku' => $variant->model_sku,
            'order' => (object) ['store_id' => $product->store_id],
        ];
        $service = app(PayableService::class);

        $masterVariant->update(['supplier_id' => $masterSupplier->id]);
        $this->assertSame($masterSupplier->id, $service->resolveSupplierIdForItem($item, $user->id));

        $masterVariant->update(['supplier_id' => null]);
        $this->assertSame($marketplaceSupplier->id, $service->resolveSupplierIdForItem($item, $user->id));
    }

    public function test_zero_master_hpp_falls_back_to_marketplace_hpp_for_order_and_return_history(): void
    {
        [$product, $variant, $masterVariant, $user] = $this->createLinkedVariant(
            localHpp: 25000,
            masterHpp: 0
        );
        $service = app(ProductHppService::class);

        $this->assertSame([
            'hpp' => 25000.0,
            'source' => 'marketplace',
            'master_variant_id' => $masterVariant->id,
        ], $service->variantDetails($variant));

        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier HPP Fallback',
            'period_length_days' => 14,
            'first_period_start' => now()->subDays(2)->startOfDay(),
        ]);
        SupplierProductMapping::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'sku' => $variant->model_sku,
            'product_id' => $product->id,
            'platform_product_id' => (string) $product->product_id,
        ]);
        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $product->store_id,
            'platform' => 'Shopee',
            'order_sn' => 'ORDER-HPP-FALLBACK',
            'order_status' => 'SHIPPED',
            'order_time' => now(),
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 2,
            'price' => 50000,
        ]));

        $payable = app(PayableService::class);
        $payable->recordOrderEvent($order->fresh(['orderProducts', 'store']));

        $this->assertDatabaseHas('payable_events', [
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'source_id' => $order->order_sn,
            'source_type' => 'CREATE_ORDER',
            'amount' => 50000,
        ]);

        $returnCreatedAt = now()->subDay()->startOfMinute();
        $return = OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'platform' => 'Shopee',
            'external_return_id' => 'RETURN-HPP-FALLBACK',
            'return_status' => 'ACCEPTED',
            'normalized_status' => 'RETURN_COMPLETED',
            'created_at_platform' => $returnCreatedAt,
            'updated_at_platform' => now()->addDay(),
        ]));
        OrderReturnItem::withoutEvents(fn () => OrderReturnItem::create([
            'order_return_id' => $return->id,
            'external_line_item_id' => 'RETURN-LINE-HPP-FALLBACK',
            'sku_id' => $variant->model_sku,
            'product_name' => $product->product_name,
            'quantity' => 1,
        ]));

        $payable->recordReturnEvent($return->fresh(['items', 'order.store']));

        $this->assertDatabaseHas('payable_events', [
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'source_id' => $order->order_sn,
            'source_type' => 'RETURN_ORDER',
            'amount' => -25000,
        ]);

        $event = PayableEvent::query()
            ->where('source_id', $order->order_sn)
            ->where('source_type', 'RETURN_ORDER')
            ->firstOrFail();
        $this->assertSame($returnCreatedAt->format('Y-m-d H:i:s'), $event->event_date->format('Y-m-d H:i:s'));

        $return->updateQuietly(['updated_at_platform' => now()->addDays(3)]);
        $payable->recordReturnEvent($return->fresh(['items', 'order.store']));

        $this->assertSame(
            $returnCreatedAt->format('Y-m-d H:i:s'),
            $event->fresh()->event_date->format('Y-m-d H:i:s')
        );
    }

    public function test_shopee_cancelled_order_with_return_data_stays_in_payable_history(): void
    {
        [$product, $variant, , $user] = $this->createLinkedVariant(25000, 25000);
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Shopee Return Supplier',
            'period_length_days' => 14,
            'first_period_start' => now()->subDays(5)->startOfDay(),
        ]);
        SupplierProductMapping::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'sku' => $variant->model_sku,
            'product_id' => $product->id,
            'platform_product_id' => (string) $product->product_id,
        ]);

        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $product->store_id,
            'platform' => 'Shopee',
            'order_sn' => 'SHOPEE-CANCELLED-WITH-RETURN',
            'order_status' => 'SHIPPED',
            'order_time' => now()->subDays(2),
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 1,
            'price' => 50000,
        ]));

        $payable = app(PayableService::class);
        $payable->recordOrderEvent($order->fresh(['orderProducts', 'store']));
        $order->updateQuietly(['order_status' => 'CANCELLED']);

        $return = OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'platform' => 'Shopee',
            'external_return_id' => 'SHOPEE-RETURN-AFTER-CANCEL',
            'return_status' => 'REFUND_PAID',
            'normalized_status' => 'COMPLETED',
            'created_at_platform' => now()->subDay(),
        ]));
        OrderReturnItem::withoutEvents(fn () => OrderReturnItem::create([
            'order_return_id' => $return->id,
            'external_line_item_id' => $variant->model_id,
            'sku_id' => $variant->model_id,
            'product_name' => $product->product_name,
            'quantity' => 1,
        ]));

        $payable->reconcileOrderLogistics(
            $order->fresh(['orderProducts', 'store', 'returns.items', 'packages'])
        );

        $this->assertDatabaseHas('payable_events', [
            'source_id' => $order->order_sn,
            'source_type' => 'CREATE_ORDER',
            'supplier_id' => $supplier->id,
            'amount' => 25000,
        ]);
        $this->assertDatabaseHas('payable_events', [
            'source_id' => $order->order_sn,
            'source_type' => 'RETURN_ORDER',
            'supplier_id' => $supplier->id,
            'amount' => -25000,
        ]);
    }

    public function test_shopee_return_without_platform_timestamps_is_repaired_into_payable_history(): void
    {
        [$product, $variant, , $user] = $this->createLinkedVariant(25000, 25000);
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Shopee Legacy Return Supplier',
            'period_length_days' => 14,
            'first_period_start' => now()->subDays(30)->startOfDay(),
        ]);
        SupplierProductMapping::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'sku' => $variant->model_sku,
            'product_id' => $product->id,
            'platform_product_id' => (string) $product->product_id,
        ]);

        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $product->store_id,
            'platform' => 'Shopee',
            'order_sn' => 'SHOPEE-LEGACY-RETURN-NO-TIMESTAMP',
            'order_status' => 'COMPLETED',
            'order_time' => now()->subDays(10),
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 1,
            'price' => 50000,
        ]));

        $return = OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'platform' => 'Shopee',
            'external_return_id' => 'SHOPEE-LEGACY-RETURN',
            'return_status' => 'REFUND_PAID',
            'normalized_status' => 'COMPLETED',
            'created_at_platform' => null,
            'updated_at_platform' => null,
        ]));
        OrderReturnItem::withoutEvents(fn () => OrderReturnItem::create([
            'order_return_id' => $return->id,
            'external_line_item_id' => $variant->model_id,
            'sku_id' => $variant->model_id,
            'product_name' => $product->product_name,
            'quantity' => 1,
        ]));

        app(PayableService::class)->syncPayableForUser(
            $user->id,
            $supplier->first_period_start->toDateTimeString(),
        );

        $this->assertDatabaseHas('payable_events', [
            'source_id' => $order->order_sn,
            'source_type' => 'CREATE_ORDER',
            'supplier_id' => $supplier->id,
            'amount' => 25000,
        ]);
        $this->assertDatabaseHas('payable_events', [
            'source_id' => $order->order_sn,
            'source_type' => 'RETURN_ORDER',
            'supplier_id' => $supplier->id,
            'amount' => -25000,
        ]);
    }

    public function test_legacy_shopee_closed_return_with_refund_remains_in_payable_history(): void
    {
        [$product, $variant, , $user] = $this->createLinkedVariant(25000, 25000);
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Shopee Closed Return Supplier',
            'period_length_days' => 14,
            'first_period_start' => now()->subDays(30)->startOfDay(),
        ]);
        SupplierProductMapping::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'sku' => $variant->model_sku,
            'product_id' => $product->id,
            'platform_product_id' => (string) $product->product_id,
        ]);

        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $product->store_id,
            'platform' => 'Shopee',
            'order_sn' => 'SHOPEE-CLOSED-RETURN-WITH-REFUND',
            'order_status' => 'COMPLETED',
            'order_time' => now()->subDays(10),
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 1,
            'price' => 50000,
        ]));

        $payable = app(PayableService::class);
        $payable->recordOrderEvent($order->fresh(['orderProducts', 'store']));
        $return = OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'platform' => 'Shopee',
            'external_return_id' => 'SHOPEE-CLOSED-RETURN',
            'return_status' => 'CLOSED',
            'platform_status' => 'CLOSED',
            'normalized_status' => 'REJECTED',
            'refund_amount' => 50000,
            'created_at_platform' => now()->subDay(),
        ]));
        OrderReturnItem::withoutEvents(fn () => OrderReturnItem::create([
            'order_return_id' => $return->id,
            'external_line_item_id' => $variant->model_id,
            'sku_id' => $variant->model_id,
            'product_name' => $product->product_name,
            'quantity' => 1,
        ]));

        $payable->recordReturnEvent($return->fresh(['items', 'order.store']));

        $this->assertDatabaseHas('payable_events', [
            'source_id' => $order->order_sn,
            'source_type' => 'RETURN_ORDER',
            'supplier_id' => $supplier->id,
            'amount' => -25000,
        ]);
    }

    public function test_shopee_to_return_order_without_return_detail_creates_stable_payable_reduction(): void
    {
        [$product, $variant, , $user] = $this->createLinkedVariant(25000, 25000);
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Shopee Status Return Supplier',
            'period_length_days' => 14,
            'first_period_start' => now()->subDays(30)->startOfDay(),
        ]);
        SupplierProductMapping::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'sku' => $variant->model_sku,
            'product_id' => $product->id,
            'platform_product_id' => (string) $product->product_id,
        ]);

        $returnStartedAt = now()->subDay()->startOfMinute();
        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $product->store_id,
            'platform' => 'Shopee',
            'order_sn' => 'SHOPEE-TO-RETURN-WITHOUT-DETAIL',
            'order_status' => 'TO_RETURN',
            'order_time' => now()->subDays(10),
            'raw_data' => ['update_time' => $returnStartedAt->timestamp],
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 1,
            'price' => 50000,
        ]));

        $payable = app(PayableService::class);
        $payable->recordOrderEvent($order->fresh(['orderProducts', 'store']));

        $event = PayableEvent::query()
            ->where('source_id', $order->order_sn)
            ->where('source_type', 'RETURN_ORDER')
            ->firstOrFail();
        $this->assertSame(-25000.0, (float) $event->amount);
        $this->assertSame($returnStartedAt->format('Y-m-d H:i:s'), $event->event_date->format('Y-m-d H:i:s'));
        $this->assertSame('Shopee return fallback: TO_RETURN', $event->notes);

        $order->updateQuietly(['raw_data' => ['update_time' => now()->timestamp]]);
        $payable->recordOrderEvent($order->fresh(['orderProducts', 'store']));

        $this->assertSame(
            $returnStartedAt->format('Y-m-d H:i:s'),
            $event->fresh()->event_date->format('Y-m-d H:i:s'),
        );
    }

    public function test_tiktok_logistics_return_keeps_first_failure_date_and_manual_period(): void
    {
        [$product, $variant, , $user] = $this->createLinkedVariant(25000, 25000);
        $product->store->update(['platform' => 'Tiktokshop']);
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'TikTok Supplier',
            'period_length_days' => 14,
            'first_period_start' => now()->subDays(40)->startOfDay(),
        ]);
        SupplierProductMapping::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'sku' => $variant->model_sku,
            'product_id' => $product->id,
            'platform_product_id' => (string) $product->product_id,
        ]);

        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $product->store_id,
            'platform' => 'Tiktokshop',
            'order_sn' => 'TIKTOK-LOGISTICS-RETURN',
            // TikTok can mark a shipped order CANCELLED after final delivery failure.
            'order_status' => 'CANCELLED',
            'order_time' => now()->subDays(20),
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 1,
            'price' => 50000,
        ]));

        $payable = app(PayableService::class);
        $payable->recordOrderEvent($order->fresh(['orderProducts', 'store']));
        $this->assertDatabaseMissing('payable_events', [
            'source_id' => $order->order_sn,
            'source_type' => 'CREATE_ORDER',
        ]);

        $failedAt = now()->subDays(11)->startOfMinute();
        OrderPackage::withoutEvents(fn () => OrderPackage::create([
            'order_id' => $order->id,
            'platform' => 'Tiktokshop',
            'package_id' => $order->order_sn,
            'normalized_logistics_status' => 'DELIVERY_FAILED',
            'failed_at' => $failedAt,
        ]));

        $payable->reconcileOrderLogistics($order->fresh(['orderProducts', 'store']));

        $this->assertDatabaseHas('payable_events', [
            'source_id' => $order->order_sn,
            'source_type' => 'CREATE_ORDER',
            'supplier_id' => $supplier->id,
        ]);

        $failedEvent = PayableEvent::where('source_id', $order->order_sn)
            ->where('source_type', 'FAILED_DELIVERY')
            ->firstOrFail();
        $retryAttemptAt = $failedAt->copy()->subDay();
        $manualPeriod = PayablePeriod::withoutEvents(fn () => PayablePeriod::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'name' => 'Manual Previous Period',
            'start_date' => now()->subDays(30),
            'end_date' => now()->subDays(16),
            'is_manual' => true,
        ]));
        $failedEvent->update([
            'original_period_id' => $failedEvent->payable_period_id,
            'payable_period_id' => $manualPeriod->id,
            'is_manual_moved' => true,
            'event_date' => $retryAttemptAt,
        ]);

        $return = OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'platform' => 'Tiktokshop',
            'external_return_id' => 'TIKTOK-RETURN-1',
            'return_status' => 'COMPLETED',
            'normalized_status' => 'REFUND_COMPLETED',
            'created_at_platform' => now()->subDay(),
        ]));
        OrderReturnItem::withoutEvents(fn () => OrderReturnItem::create([
            'order_return_id' => $return->id,
            'external_line_item_id' => 'TIKTOK-RETURN-LINE-1',
            'sku_id' => $variant->model_sku,
            'product_name' => $product->product_name,
            'quantity' => 1,
        ]));

        $payable->recordReturnEvent($return->fresh(['items', 'order.store']));

        $returnEvent = PayableEvent::where('source_id', $order->order_sn)
            ->where('source_type', 'RETURN_ORDER')
            ->firstOrFail();
        $this->assertSame($manualPeriod->id, $returnEvent->payable_period_id);
        $this->assertTrue($returnEvent->is_manual_moved);
        $this->assertSame($failedAt->format('Y-m-d H:i:s'), $returnEvent->event_date->format('Y-m-d H:i:s'));
        $this->assertDatabaseMissing('payable_events', [
            'source_id' => $order->order_sn,
            'source_type' => 'FAILED_DELIVERY',
        ]);
    }

    public function test_paid_seller_late_cancellation_is_deducted_in_the_cancellation_period(): void
    {
        [$product, $variant, , $user] = $this->createLinkedVariant(25000, 25000);
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Late Cancellation Supplier',
            'period_length_days' => 10,
            'first_period_start' => now()->subDays(20)->startOfDay(),
        ]);
        SupplierProductMapping::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'sku' => $variant->model_sku,
            'product_id' => $product->id,
            'platform_product_id' => (string) $product->product_id,
        ]);

        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $product->store_id,
            'platform' => 'Tiktokshop',
            'order_sn' => 'SELLER-LATE-AFTER-PAID',
            'order_status' => 'AWAITING_SHIPMENT',
            'order_time' => now()->subDays(18),
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 1,
            'price' => 50000,
        ]));

        $payable = app(PayableService::class);
        $payable->recordOrderEvent($order->fresh(['orderProducts', 'store']));
        $createEvent = PayableEvent::where('source_id', $order->order_sn)
            ->where('source_type', 'CREATE_ORDER')
            ->firstOrFail();
        $originPeriod = $createEvent->period;
        $originPeriod->update(['payment_status' => 'PAID']);

        // Simulate historical data where the old cancellation logic removed the order event.
        $createEvent->delete();
        $cancelledAt = now()->subDays(8)->startOfMinute();
        $order->updateQuietly([
            'order_status' => 'CANCELLED',
            'cancel_source' => 'SYSTEM',
            'cancel_reason' => 'Late dispatch: seller did not ship in time',
            'normalized_cancel_category' => 'SELLER_LATE_SHIPMENT',
            'raw_data' => ['update_time' => $cancelledAt->timestamp],
        ]);

        $payable->recordOrderEvent($order->fresh(['orderProducts', 'store']));
        $payable->recordCancellationEvent($order->fresh(['orderProducts', 'store']));

        $restoredCreateEvent = PayableEvent::where('source_id', $order->order_sn)
            ->where('source_type', 'CREATE_ORDER')
            ->firstOrFail();
        $cancelEvent = PayableEvent::where('source_id', $order->order_sn)
            ->where('source_type', 'SELLER_LATE_CANCEL')
            ->firstOrFail();

        $this->assertSame($originPeriod->id, $restoredCreateEvent->payable_period_id);
        $this->assertNotSame($originPeriod->id, $cancelEvent->payable_period_id);
        $this->assertSame($originPeriod->id, $cancelEvent->original_period_id);
        $this->assertSame('UNPAID', $cancelEvent->period->payment_status);
        $this->assertSame('-25000.00', $cancelEvent->amount);
        $this->assertSame($cancelledAt->format('Y-m-d H:i:s'), $cancelEvent->event_date->format('Y-m-d H:i:s'));

        $order->updateQuietly(['raw_data' => ['update_time' => now()->timestamp]]);
        $payable->recordCancellationEvent($order->fresh(['orderProducts', 'store']));
        $this->assertSame(
            $cancelledAt->format('Y-m-d H:i:s'),
            $cancelEvent->fresh()->event_date->format('Y-m-d H:i:s')
        );
    }

    public function test_unpaid_seller_late_cancellation_does_not_create_payable_events(): void
    {
        [$product, $variant, , $user] = $this->createLinkedVariant(25000, 25000);
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Unpaid Late Cancellation Supplier',
            'period_length_days' => 10,
            'first_period_start' => now()->subDays(20)->startOfDay(),
        ]);
        SupplierProductMapping::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'sku' => $variant->model_sku,
            'product_id' => $product->id,
            'platform_product_id' => (string) $product->product_id,
        ]);
        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $product->store_id,
            'platform' => 'Tiktokshop',
            'order_sn' => 'SELLER-LATE-BEFORE-PAID',
            'order_status' => 'CANCELLED',
            'normalized_cancel_category' => 'SELLER_LATE_SHIPMENT',
            'cancel_reason' => 'Late dispatch',
            'order_time' => now()->subDays(18),
            'raw_data' => ['update_time' => now()->subDays(8)->timestamp],
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 1,
            'price' => 50000,
        ]));

        $payable = app(PayableService::class);
        $payable->recordOrderEvent($order->fresh(['orderProducts', 'store']));
        $payable->recordCancellationEvent($order->fresh(['orderProducts', 'store']));

        $this->assertDatabaseMissing('payable_events', ['source_id' => $order->order_sn]);
    }

    private function createLinkedVariant(int $localHpp, int $masterHpp): array
    {
        [$product, $variant, $user] = $this->createMarketplaceVariant($localHpp);
        $masterProduct = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Master Product',
            'status' => 'active',
            'source' => 'manual',
        ]);
        $masterVariant = MasterProductVariant::create([
            'master_product_id' => $masterProduct->id,
            'user_id' => $user->id,
            'sku' => 'SKU-HPP',
            'variant_name' => 'Black',
            'hpp' => $masterHpp,
            'stock' => 10,
            'is_active' => true,
        ]);
        $group = SkuSyncGroup::create([
            'user_id' => $user->id,
            'master_product_variant_id' => $masterVariant->id,
            'sku' => 'SKU-HPP',
            'master_stock' => 10,
            'is_active' => true,
        ]);
        SkuSyncMember::create([
            'sku_sync_group_id' => $group->id,
            'store_id' => $product->store_id,
            'product_id' => $product->id,
            'variant_product_id' => $variant->id,
            'platform_product_id' => $product->product_id,
            'platform_variant_id' => $variant->model_id,
        ]);

        return [$product, $variant, $masterVariant, $user];
    }

    private function createMarketplaceVariant(int $localHpp): array
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'HPP Test Store '.$user->id,
            'shopee_shop_id' => 'hpp-shop-'.$user->id,
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 'product-'.$user->id,
            'product_name' => 'HPP Test Product',
            'product_sku' => 'SKU-HPP',
            'stock' => 10,
            'price' => 50000,
        ]);
        $variant = VariantProduct::create([
            'product_id' => $product->id,
            'model_id' => 'variant-1',
            'model_name' => 'Black',
            'model_sku' => 'SKU-HPP',
            'stock' => 10,
            'price' => 50000,
            'hpp' => $localHpp,
        ]);

        return [$product, $variant, $user];
    }
}

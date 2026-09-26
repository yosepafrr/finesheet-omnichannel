<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\MasterProductVariant;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\PayableEvent;
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

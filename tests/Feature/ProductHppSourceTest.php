<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\MasterProductVariant;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\SkuSyncGroup;
use App\Models\SkuSyncMember;
use App\Models\Store;
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

        return [$product, $variant, $masterVariant];
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

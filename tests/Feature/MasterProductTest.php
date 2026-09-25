<?php

namespace Tests\Feature;

use App\Jobs\SyncMasterProductVariantsJob;
use App\Jobs\SyncStockToMarketplaceJob;
use App\Models\MasterProduct;
use App\Models\MasterProductVariant;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MasterProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_master_sku_links_matching_marketplace_products_without_pushing_stock(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        foreach ([
            $this->createStore($user, 'Shopee', 'Toko Shopee', 'SHOP-1'),
            $this->createStore($user, 'Tiktokshop', 'Toko TikTok', 'SHOP-2'),
        ] as $index => $store) {
            Product::create([
                'store_id' => $store->id,
                'platform' => $store->platform,
                'product_id' => 1000 + $index,
                'product_name' => "Kemeja {$index}",
                'product_sku' => 'SKU-GABUNG',
                'stock' => 12,
                'price' => 100000,
            ]);
        }

        $response = $this->actingAs($user)->postJson('/api/master-products', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('name', 'Kemeja Oxford')
            ->assertJsonPath('variants.0.sku', 'SKU-GABUNG')
            ->assertJsonPath('variants.0.stores_count', 2)
            ->assertJsonPath('variants.0.same_sku_across_stores', true);
        $this->assertDatabaseCount('master_products', 1);
        $this->assertDatabaseCount('sku_sync_groups', 1);
        $this->assertDatabaseCount('sku_sync_members', 2);
        Bus::assertNotDispatched(SyncStockToMarketplaceJob::class);
    }

    public function test_master_list_is_paginated_per_variant_sku(): void
    {
        $user = User::factory()->create();
        $product = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Kemeja Oxford',
            'status' => 'active',
            'source' => 'manual',
        ]);
        foreach (['SKU-HITAM', 'SKU-PUTIH'] as $sku) {
            MasterProductVariant::create([
                'master_product_id' => $product->id,
                'user_id' => $user->id,
                'sku' => $sku,
                'stock' => 10,
                'hpp' => 50000,
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/master-products')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_updating_master_stock_pushes_to_every_matching_listing(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Toko Shopee', 'SHOP-STOCK');
        Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 2001,
            'product_name' => 'Kemeja Marketplace',
            'product_sku' => 'SKU-GABUNG',
            'stock' => 12,
            'price' => 100000,
        ]);

        $created = $this->actingAs($user)->postJson('/api/master-products', $this->payload());
        $productId = $created->json('id');
        $variantId = $created->json('variants.0.id');
        $payload = [
            'name' => 'Kemeja Oxford',
            'image' => null,
            'brand' => null,
            'category' => null,
            'description' => null,
            'status' => 'active',
            'sku' => 'SKU-GABUNG',
            'variant_name' => 'Hitam / L',
            'barcode' => '899000000001',
            'stock' => 25,
            'hpp' => 50000,
            'is_active' => true,
        ];

        $this->actingAs($user)
            ->putJson("/api/master-products/{$productId}/variants/{$variantId}", $payload)
            ->assertOk()
            ->assertJsonPath('stock', 25);

        $this->assertDatabaseHas('sku_sync_groups', ['master_stock' => 25]);
        Bus::assertDispatchedTimes(SyncStockToMarketplaceJob::class, 1);
    }

    public function test_deleting_master_sku_does_not_delete_marketplace_product(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Toko Shopee', 'SHOP-DELETE');
        $marketplaceProduct = Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 3001,
            'product_name' => 'Produk Marketplace',
            'product_sku' => 'SKU-GABUNG',
            'stock' => 7,
            'price' => 80000,
        ]);
        $created = $this->actingAs($user)->postJson('/api/master-products', $this->payload());

        $this->actingAs($user)
            ->deleteJson('/api/master-products/'.$created->json('id').'/variants/'.$created->json('variants.0.id'))
            ->assertOk();

        $this->assertDatabaseHas('products', ['id' => $marketplaceProduct->id]);
        $this->assertDatabaseCount('master_products', 0);
        $this->assertDatabaseCount('sku_sync_groups', 0);
    }

    public function test_duplicate_marketplace_sku_is_suggested_until_master_is_created(): void
    {
        $user = User::factory()->create();
        foreach ([
            $this->createStore($user, 'Shopee', 'Toko A', 'SHOP-A'),
            $this->createStore($user, 'Tiktokshop', 'Toko B', 'SHOP-B'),
        ] as $index => $store) {
            Product::create([
                'store_id' => $store->id,
                'platform' => $store->platform,
                'product_id' => 4000 + $index,
                'product_name' => "Produk {$index}",
                'product_sku' => 'SKU-DETEKSI',
                'stock' => 5,
                'price' => 60000,
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/sku-sync/detect')
            ->assertOk()
            ->assertJsonPath('0.sku', 'SKU-DETEKSI')
            ->assertJsonPath('0.stores_count', 2);
    }

    public function test_detected_skus_can_be_added_to_master_catalog_in_bulk_with_defaults(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $stores = [
            $this->createStore($user, 'Shopee', 'Toko A', 'SHOP-BULK-A'),
            $this->createStore($user, 'Tiktokshop', 'Toko B', 'SHOP-BULK-B'),
        ];

        foreach ($stores as $storeIndex => $store) {
            foreach (['SKU-BULK-A', 'SKU-BULK-B'] as $skuIndex => $sku) {
                Product::create([
                    'store_id' => $store->id,
                    'platform' => $store->platform,
                    'product_id' => 5000 + ($storeIndex * 10) + $skuIndex,
                    'product_name' => $sku === 'SKU-BULK-A' ? 'Kemeja Bulk' : 'Celana Bulk',
                    'product_sku' => $sku,
                    'stock' => 8 + $skuIndex,
                    'price' => 90000,
                ]);
            }
        }

        $response = $this->actingAs($user)->postJson('/api/master-products/bulk', [
            'skus' => ['SKU-BULK-A'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('requested_count', 1)
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('sync_queued', true);
        $this->assertDatabaseHas('master_products', [
            'user_id' => $user->id,
            'name' => 'Kemeja Bulk',
            'source' => 'manual',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('master_product_variants', [
            'user_id' => $user->id,
            'sku' => 'SKU-BULK-A',
            'barcode' => null,
            'hpp' => 0,
            'stock' => 8,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('master_product_variants', [
            'user_id' => $user->id,
            'sku' => 'SKU-BULK-B',
        ]);
        Bus::assertDispatched(SyncMasterProductVariantsJob::class, function ($job) {
            return count($job->variantIds) === 1;
        });
    }

    public function test_bulk_add_skips_skus_that_are_not_in_the_users_detected_list(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/master-products/bulk', ['skus' => ['SKU-TIDAK-TERDETEKSI']])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('sync_queued', false);

        $this->assertDatabaseCount('master_products', 0);
        Bus::assertNotDispatched(SyncMasterProductVariantsJob::class);
    }

    public function test_master_list_is_isolated_per_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $product = MasterProduct::create([
            'user_id' => $owner->id,
            'name' => 'Produk Rahasia',
            'status' => 'active',
            'source' => 'manual',
        ]);
        MasterProductVariant::create([
            'master_product_id' => $product->id,
            'user_id' => $owner->id,
            'sku' => 'SKU-RAHASIA',
            'stock' => 1,
            'hpp' => 1000,
        ]);

        $this->actingAs($other)
            ->getJson('/api/master-products')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    private function payload(): array
    {
        return [
            'name' => 'Kemeja Oxford',
            'status' => 'active',
            'variants' => [[
                'sku' => 'SKU-GABUNG',
                'variant_name' => 'Hitam / L',
                'barcode' => '899000000001',
                'hpp' => 50000,
                'stock' => 12,
                'is_active' => true,
            ]],
        ];
    }

    private function createStore(User $user, string $platform, string $name, string $shopId): Store
    {
        return Store::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'store_name' => $name,
            'shopee_shop_id' => $shopId,
        ]);
    }
}

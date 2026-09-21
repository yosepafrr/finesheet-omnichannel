<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\Product;
use App\Models\SkuSyncGroup;
use App\Models\Store;
use App\Models\User;
use App\Services\MasterCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_master_product_and_link_an_existing_sync_group(): void
    {
        $user = User::factory()->create();
        $group = SkuSyncGroup::create([
            'user_id' => $user->id,
            'sku' => 'KMO-HITAM-XXXL',
            'master_stock' => 17,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/master-products', [
            'name' => 'Kemeja Oxford Pria',
            'brand' => 'Vilion',
            'category' => 'Kemeja',
            'status' => 'active',
            'variants' => [[
                'sku' => 'KMO-HITAM-XXXL',
                'variant_name' => 'Hitam / XXXL',
                'barcode' => '899000000001',
                'hpp' => 45000,
                'stock' => 4,
                'is_active' => true,
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Kemeja Oxford Pria')
            ->assertJsonPath('variants.0.stock', 4)
            ->assertJsonPath('variants.0.sync_group_id', $group->id);

        $variantId = $response->json('variants.0.id');
        $this->assertDatabaseHas('master_product_variants', [
            'id' => $variantId,
            'user_id' => $user->id,
            'sku' => 'KMO-HITAM-XXXL',
        ]);
        $this->assertSame($variantId, $group->fresh()->master_product_variant_id);
    }

    public function test_master_product_list_is_isolated_per_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        MasterProduct::create([
            'user_id' => $owner->id,
            'name' => 'Produk Rahasia',
            'status' => 'active',
        ]);

        $this->actingAs($otherUser)
            ->getJson('/api/master-products')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_sku_must_be_unique_for_each_user(): void
    {
        $user = User::factory()->create();
        $payload = [
            'name' => 'Produk Pertama',
            'status' => 'active',
            'variants' => [[
                'sku' => 'SKU-SAMA',
                'hpp' => 10000,
                'stock' => 5,
                'is_active' => true,
            ]],
        ];

        $this->actingAs($user)->postJson('/api/master-products', $payload)->assertCreated();

        $payload['name'] = 'Produk Kedua';
        $this->actingAs($user)
            ->postJson('/api/master-products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants');
    }

    public function test_same_sku_from_three_stores_is_merged_and_uses_reference_store_stock(): void
    {
        $user = User::factory()->create();
        $stores = collect([
            $this->createStore($user, 'Shopee', 'Toko Shopee', 'SHOP-1'),
            $this->createStore($user, 'Tiktokshop', 'Toko TikTok', 'SHOP-2'),
            $this->createStore($user, 'Shopee', 'Toko Kedua', 'SHOP-3'),
        ]);
        $stocks = [5, 12, 20];
        $catalog = app(MasterCatalogService::class);

        foreach ($stores as $index => $store) {
            $product = Product::create([
                'store_id' => $store->id,
                'platform' => $store->platform,
                'product_id' => 1000 + $index,
                'product_name' => "Kemeja Toko {$index}",
                'product_sku' => 'SKU-GABUNG',
                'stock' => $stocks[$index],
                'price' => 100000,
            ]);

            $catalog->syncProduct($product);
        }

        $this->assertDatabaseCount('master_products', 1);
        $this->assertDatabaseCount('master_product_variants', 1);
        $this->assertDatabaseCount('master_product_variant_listings', 3);

        $master = MasterProduct::firstOrFail();
        $this->assertNull($master->reference_store_id);

        $this->actingAs($user)
            ->putJson("/api/master-products/{$master->id}/reference-store", [
                'store_id' => $stores[1]->id,
            ])
            ->assertOk()
            ->assertJsonPath('reference_store.id', $stores[1]->id)
            ->assertJsonPath('variants.0.stock', 12)
            ->assertJsonPath('linked_listings_count', 3);
    }

    public function test_deleting_a_store_keeps_the_master_product_snapshot(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Toko Utama', 'SHOP-DELETE');
        $product = Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 9999,
            'product_name' => 'Produk Bertahan',
            'product_sku' => 'SKU-BERTAHAN',
            'stock' => 33,
            'price' => 120000,
        ]);
        $catalog = app(MasterCatalogService::class);
        $catalog->syncProduct($product);
        $master = MasterProduct::firstOrFail();
        $catalog->setReferenceStore($master, $store);

        $store->delete();

        $this->assertDatabaseHas('master_products', [
            'id' => $master->id,
            'name' => 'Produk Bertahan',
            'reference_store_id' => null,
        ]);
        $this->assertDatabaseHas('master_product_variants', [
            'master_product_id' => $master->id,
            'sku' => 'SKU-BERTAHAN',
            'stock' => 33,
        ]);
        $this->assertDatabaseCount('master_product_variant_listings', 0);
    }

    public function test_changed_store_sku_reuses_its_unshared_master_variant(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Toko Utama', 'SHOP-SKU-CHANGE');
        $product = Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 8888,
            'product_name' => 'Produk Ganti SKU',
            'product_sku' => 'SKU-LAMA',
            'stock' => 8,
            'price' => 90000,
        ]);
        $catalog = app(MasterCatalogService::class);
        $catalog->syncProduct($product);
        $variantId = MasterProduct::firstOrFail()->variants()->value('id');

        $product->update(['product_sku' => 'SKU-BARU']);
        $catalog->syncProduct($product->fresh());

        $this->assertDatabaseCount('master_product_variants', 1);
        $this->assertDatabaseHas('master_product_variants', [
            'id' => $variantId,
            'sku' => 'SKU-BARU',
        ]);
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

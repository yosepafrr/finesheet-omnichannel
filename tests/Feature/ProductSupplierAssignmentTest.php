<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierProductMapping;
use App\Models\User;
use App\Models\VariantProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProductSupplierAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_can_be_assigned_and_removed_from_a_marketplace_product(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Marketplace',
            'period_length_days' => 14,
        ]);
        $store = $this->createStore($user, 'SHOP-SUPPLIER');
        $product = Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 'PLATFORM-100',
            'product_name' => 'Kemeja Marketplace',
            'product_sku' => 'SKU-PRODUK',
            'stock' => 12,
            'price' => 100000,
        ]);
        VariantProduct::create([
            'product_id' => $product->id,
            'model_id' => 'MODEL-1',
            'model_name' => 'Hitam L',
            'model_sku' => 'SKU-HITAM-L',
            'stock' => 5,
            'price' => 100000,
        ]);

        $this->actingAs($user)
            ->putJson('/api/products/supplier', [
                'product_ids' => [$product->id],
                'supplier_id' => $supplier->id,
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('supplier_id', $supplier->id);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'supplier_id' => $supplier->id,
        ]);
        $this->assertDatabaseHas('supplier_product_mappings', [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'sku' => 'SKU-PRODUK',
        ]);
        $this->assertDatabaseHas('supplier_product_mappings', [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'sku' => 'SKU-HITAM-L',
        ]);

        $this->actingAs($user)
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('stores.0.products.0.supplier_name', 'Supplier Marketplace')
            ->assertJsonPath('suppliers.0.name', 'Supplier Marketplace');

        $this->actingAs($user)
            ->putJson('/api/products/supplier', [
                'product_ids' => [$product->id],
                'supplier_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('supplier_id', null);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'supplier_id' => null]);
        $this->assertSame(0, SupplierProductMapping::where('product_id', $product->id)->count());
    }

    public function test_marketplace_supplier_assignment_is_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier User',
            'period_length_days' => 14,
        ]);
        $otherProduct = Product::create([
            'store_id' => $this->createStore($otherUser, 'SHOP-OTHER')->id,
            'platform' => 'Shopee',
            'product_id' => 'PLATFORM-OTHER',
            'product_name' => 'Produk User Lain',
            'product_sku' => 'SKU-OTHER',
            'stock' => 2,
            'price' => 50000,
        ]);

        $this->actingAs($user)
            ->putJson('/api/products/supplier', [
                'product_ids' => [$otherProduct->id],
                'supplier_id' => $supplier->id,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('products', ['id' => $otherProduct->id, 'supplier_id' => null]);
    }

    public function test_marketplace_products_are_paginated_per_store(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'SHOP-PAGINATION');

        foreach (range(1, 35) as $index) {
            Product::create([
                'store_id' => $store->id,
                'platform' => 'Shopee',
                'product_id' => 'PLATFORM-'.$index,
                'product_name' => 'Produk '.$index,
                'product_sku' => 'SKU-'.$index,
                'stock' => $index,
                'price' => 50000,
            ]);
        }

        $query = http_build_query([
            'pages' => json_encode([(string) $store->id => 2]),
            'limits' => json_encode([(string) $store->id => 10]),
        ]);

        $this->actingAs($user)
            ->getJson('/api/products?'.$query)
            ->assertOk()
            ->assertJsonCount(10, 'stores.0.products')
            ->assertJsonPath('stores.0.product_count', 35)
            ->assertJsonPath('stores.0.pagination.current_page', 2)
            ->assertJsonPath('stores.0.pagination.per_page', 10)
            ->assertJsonPath('stores.0.pagination.last_page', 4);
    }

    private function createStore(User $user, string $shopId): Store
    {
        return Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Toko '.$shopId,
            'shopee_shop_id' => $shopId,
        ]);
    }
}

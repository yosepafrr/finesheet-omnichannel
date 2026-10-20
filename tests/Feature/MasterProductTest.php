<?php

namespace Tests\Feature;

use App\Jobs\SyncMasterProductVariantsJob;
use App\Jobs\SyncPayableHistoryJob;
use App\Jobs\SyncStockToMarketplaceJob;
use App\Models\MasterProduct;
use App\Models\MasterProductVariant;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VariantProduct;
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

    public function test_bundle_master_sku_derives_stock_and_hpp_from_its_components(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $product = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Kaos Dasar',
            'status' => 'active',
            'source' => 'manual',
        ]);
        $black = MasterProductVariant::create([
            'master_product_id' => $product->id,
            'user_id' => $user->id,
            'sku' => 'DSP-KLR-HTM-M',
            'stock' => 10,
            'hpp' => 20000,
        ]);
        $navy = MasterProductVariant::create([
            'master_product_id' => $product->id,
            'user_id' => $user->id,
            'sku' => 'DSP-KLR-NVY-M',
            'stock' => 7,
            'hpp' => 22000,
        ]);

        $response = $this->actingAs($user)->postJson('/api/master-products', [
            'name' => 'Paket Kaos 3 PCS',
            'status' => 'active',
            'variants' => [[
                'sku' => '3PCS-DSP-KLR-HHN-M',
                'hpp' => 0,
                'stock' => 0,
                'is_active' => true,
                'is_bundle' => true,
                'components' => [
                    ['master_product_variant_id' => $black->id, 'quantity' => 2],
                    ['master_product_variant_id' => $navy->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('variants.0.is_bundle', true)
            ->assertJsonPath('variants.0.stock', 5)
            ->assertJsonPath('variants.0.hpp', 62000.0)
            ->assertJsonCount(2, 'variants.0.components');
        $this->assertDatabaseHas('master_product_bundle_components', [
            'component_variant_id' => $black->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('sku_sync_groups', [
            'sku' => '3PCS-DSP-KLR-HHN-M',
            'master_stock' => 5,
        ]);
    }

    public function test_component_sku_cannot_be_deleted_while_it_is_used_by_a_bundle(): void
    {
        $user = User::factory()->create();
        $componentProduct = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Komponen',
            'status' => 'active',
            'source' => 'manual',
        ]);
        $component = MasterProductVariant::create([
            'master_product_id' => $componentProduct->id,
            'user_id' => $user->id,
            'sku' => 'KOMPONEN-1',
            'stock' => 10,
            'hpp' => 10000,
        ]);
        $bundleProduct = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Bundle',
            'status' => 'active',
            'source' => 'manual',
        ]);
        $bundle = MasterProductVariant::create([
            'master_product_id' => $bundleProduct->id,
            'user_id' => $user->id,
            'sku' => 'BUNDLE-1',
            'stock' => 10,
            'hpp' => 10000,
            'is_bundle' => true,
        ]);
        $bundle->bundleComponents()->create([
            'component_variant_id' => $component->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/master-products/{$componentProduct->id}/variants/{$component->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('master_product_variants', ['id' => $component->id]);
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
        Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Bulk',
            'period_length_days' => 14,
            'first_period_start' => now()->subMonth(),
        ]);

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
            ->assertJsonPath('sync_queued', true)
            ->assertJsonPath('payable_sync_queued', true);
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
        Bus::assertDispatched(SyncPayableHistoryJob::class);
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

    public function test_master_variants_can_be_updated_and_pushed_in_bulk(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Toko Bulk', 'SHOP-BULK-UPDATE');
        $variantIds = collect(['SKU-BULK-1', 'SKU-BULK-2'])->map(function (string $sku, int $index) use ($store, $user) {
            Product::create([
                'store_id' => $store->id,
                'platform' => 'Shopee',
                'product_id' => 7000 + $index,
                'product_name' => "Produk {$sku}",
                'product_sku' => $sku,
                'stock' => 5,
                'price' => 100000,
            ]);
            $payload = $this->payload();
            $payload['name'] = "Master {$sku}";
            $payload['variants'][0]['sku'] = $sku;

            return $this->actingAs($user)
                ->postJson('/api/master-products', $payload)
                ->json('variants.0.id');
        })->all();

        $this->actingAs($user)
            ->putJson('/api/master-products/variants/bulk', [
                'variant_ids' => $variantIds,
                'field' => 'hpp',
                'value' => 17500,
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 2);

        $this->assertDatabaseCount('master_product_variants', 2);
        $this->assertSame(2, MasterProductVariant::where('hpp', 17500)->count());

        $this->actingAs($user)
            ->putJson('/api/master-products/variants/bulk', [
                'variant_ids' => $variantIds,
                'field' => 'stock',
                'value' => 31,
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('queued_count', 2);

        $this->actingAs($user)
            ->postJson('/api/master-products/variants/bulk/push', [
                'variant_ids' => $variantIds,
            ])
            ->assertStatus(202)
            ->assertJsonPath('processed_count', 2)
            ->assertJsonPath('queued_count', 2);

        $this->assertSame(2, MasterProductVariant::where('stock', 31)->count());
        Bus::assertDispatchedTimes(SyncStockToMarketplaceJob::class, 4);
    }

    public function test_master_list_can_be_filtered_and_hpp_updated_by_product_and_variant_cluster(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Jas',
            'period_length_days' => 14,
        ]);
        $product = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Jas Wanita',
            'status' => 'active',
            'source' => 'manual',
        ]);

        foreach (['jas-w-navy-xxl', 'jas-w-hitam-l', 'celana-p-hitam-l'] as $sku) {
            MasterProductVariant::create([
                'master_product_id' => $product->id,
                'user_id' => $user->id,
                'supplier_id' => $supplier->id,
                'sku' => $sku,
                'hpp' => 10000,
                'stock' => 5,
                'is_active' => true,
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/master-products?product_cluster=jas-w')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.product_cluster_key', 'jas-w')
            ->assertJsonPath('data.0.supplier_name', 'Supplier Jas');

        $this->actingAs($user)
            ->putJson('/api/master-products/variants/bulk', [
                'product_cluster_key' => 'jas-w',
                'variant_cluster_key' => 'navy',
                'supplier_filter' => (string) $supplier->id,
                'field' => 'hpp',
                'value' => 27500,
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 1);

        $this->assertDatabaseHas('master_product_variants', [
            'sku' => 'jas-w-navy-xxl',
            'hpp' => 27500,
        ]);
        $this->assertDatabaseHas('master_product_variants', [
            'sku' => 'jas-w-hitam-l',
            'hpp' => 10000,
        ]);
    }

    public function test_supplier_can_be_assigned_and_removed_from_multiple_master_skus(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Utama',
            'period_length_days' => 14,
        ]);
        $product = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Kemeja Oxford',
            'status' => 'active',
            'source' => 'manual',
        ]);
        $variants = collect(['SKU-HITAM', 'SKU-PUTIH'])->map(fn (string $sku) => MasterProductVariant::create([
            'master_product_id' => $product->id,
            'user_id' => $user->id,
            'sku' => $sku,
            'stock' => 10,
            'hpp' => 50000,
        ]));

        $this->actingAs($user)
            ->putJson('/api/master-products/variants/supplier', [
                'variant_ids' => $variants->pluck('id')->all(),
                'supplier_id' => $supplier->id,
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('supplier_id', $supplier->id);

        $this->assertSame(2, MasterProductVariant::where('supplier_id', $supplier->id)->count());

        $this->actingAs($user)
            ->putJson('/api/master-products/variants/supplier', [
                'variant_ids' => $variants->pluck('id')->all(),
                'supplier_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('supplier_id', null);

        $this->assertSame(0, MasterProductVariant::whereNotNull('supplier_id')->count());
    }

    public function test_master_supplier_assignment_rejects_another_users_supplier(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherSupplier = Supplier::create([
            'user_id' => $otherUser->id,
            'name' => 'Supplier User Lain',
            'period_length_days' => 14,
        ]);
        $product = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Produk Aman',
            'status' => 'active',
            'source' => 'manual',
        ]);
        $variant = MasterProductVariant::create([
            'master_product_id' => $product->id,
            'user_id' => $user->id,
            'sku' => 'SKU-AMAN',
            'stock' => 10,
            'hpp' => 50000,
        ]);

        $this->actingAs($user)
            ->putJson('/api/master-products/variants/supplier', [
                'variant_ids' => [$variant->id],
                'supplier_id' => $otherSupplier->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_id');
    }

    public function test_multiple_product_and_variant_clusters_can_be_selected_across_pages(): void
    {
        $user = User::factory()->create();
        $product = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Katalog Cluster',
            'status' => 'active',
            'source' => 'manual',
        ]);
        foreach (['jas-w-navy-xxl', 'celana-p-hitam-l', 'kaos-p-putih-m'] as $sku) {
            MasterProductVariant::create([
                'master_product_id' => $product->id,
                'user_id' => $user->id,
                'sku' => $sku,
                'stock' => 5,
                'hpp' => 10000,
            ]);
        }

        $query = http_build_query([
            'product_clusters' => ['jas-w', 'celana-p'],
            'variant_clusters' => ['xxl', 'l'],
            'per_page' => 10,
        ]);

        $this->actingAs($user)
            ->getJson('/api/master-products?'.$query)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $selection = $this->actingAs($user)
            ->getJson('/api/master-products/variants/selection?'.$query)
            ->assertOk()
            ->assertJsonPath('count', 2);

        $selectedSkus = MasterProductVariant::whereIn('id', $selection->json('variant_ids'))
            ->pluck('sku')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['celana-p-hitam-l', 'jas-w-navy-xxl'], $selectedSkus);
    }

    public function test_cluster_selection_supports_bulk_data_update_and_delete_without_deleting_marketplace_products(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Toko Cluster', 'SHOP-CLUSTER');
        $marketplaceProduct = Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 91001,
            'product_name' => 'Produk Marketplace Tetap Ada',
            'product_sku' => 'jas-w-navy-xxl',
            'stock' => 5,
            'price' => 100000,
        ]);
        $masterProduct = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Jas Lama',
            'status' => 'active',
            'source' => 'manual',
        ]);
        $variants = collect(['jas-w-navy-xxl', 'jas-w-hitam-l'])->map(fn (string $sku) => MasterProductVariant::create([
            'master_product_id' => $masterProduct->id,
            'user_id' => $user->id,
            'sku' => $sku,
            'variant_name' => 'Nama Lama',
            'stock' => 5,
            'hpp' => 10000,
        ]));

        $this->actingAs($user)
            ->putJson('/api/master-products/variants/bulk/data', [
                'variant_ids' => $variants->pluck('id')->all(),
                'changes' => [
                    'name' => 'Jas Baru',
                    'brand' => 'Finesheet',
                    'variant_name' => 'Varian Baru',
                    'is_active' => false,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 2);

        $this->assertDatabaseHas('master_products', [
            'id' => $masterProduct->id,
            'name' => 'Jas Baru',
            'brand' => 'Finesheet',
        ]);
        $this->assertSame(2, MasterProductVariant::where('variant_name', 'Varian Baru')->where('is_active', false)->count());

        $this->actingAs($user)
            ->deleteJson('/api/master-products/variants/bulk', [
                'variant_ids' => $variants->pluck('id')->all(),
            ])
            ->assertOk()
            ->assertJsonPath('deleted_count', 2);

        $this->assertDatabaseMissing('master_products', ['id' => $masterProduct->id]);
        $this->assertDatabaseHas('products', ['id' => $marketplaceProduct->id]);
    }

    public function test_marketplace_product_with_variants_can_be_converted_to_master_product(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Toko Konversi', 'SHOP-CONVERT');
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Konversi',
            'period_length_days' => 14,
            'first_period_start' => now()->subMonth(),
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'supplier_id' => $supplier->id,
            'platform' => 'Shopee',
            'product_id' => 81001,
            'product_name' => 'Kemeja Marketplace',
            'product_sku' => 'PARENT-SKU',
            'stock' => 15,
            'price' => 120000,
            'category' => 'Fashion Pria',
            'image' => 'https://example.com/product.jpg',
        ]);
        VariantProduct::create([
            'product_id' => $product->id,
            'model_id' => 810011,
            'model_name' => 'Hitam, M',
            'model_sku' => 'KEMEJA-HTM-M',
            'stock' => 8,
            'price' => 120000,
            'hpp' => 65000,
        ]);
        VariantProduct::create([
            'product_id' => $product->id,
            'model_id' => 810012,
            'model_name' => 'Putih, L',
            'model_sku' => 'KEMEJA-PTH-L',
            'stock' => 7,
            'price' => 125000,
            'hpp' => 67500,
        ]);

        $response = $this->actingAs($user)->postJson('/api/master-products/convert-marketplace', [
            'product_ids' => [$product->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('requested_count', 1)
            ->assertJsonPath('created_product_count', 1)
            ->assertJsonPath('created_variant_count', 2)
            ->assertJsonPath('converted_product_count', 1)
            ->assertJsonPath('skipped_product_count', 0)
            ->assertJsonPath('sync_queued', true);
        $this->assertDatabaseHas('master_products', [
            'user_id' => $user->id,
            'name' => 'Kemeja Marketplace',
            'category' => 'Fashion Pria',
            'image' => 'https://example.com/product.jpg',
            'source' => 'manual',
        ]);
        $this->assertDatabaseHas('master_product_variants', [
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'sku' => 'KEMEJA-HTM-M',
            'variant_name' => 'Hitam, M',
            'stock' => 8,
            'hpp' => 65000,
        ]);
        $this->assertDatabaseHas('master_product_variants', [
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'sku' => 'KEMEJA-PTH-L',
            'stock' => 7,
            'hpp' => 67500,
        ]);
        Bus::assertDispatched(SyncMasterProductVariantsJob::class, fn ($job) => count($job->variantIds) === 2);
        Bus::assertDispatched(SyncPayableHistoryJob::class);
    }

    public function test_marketplace_conversion_is_idempotent_and_reuses_existing_master_sku(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $firstStore = $this->createStore($user, 'Shopee', 'Toko Pertama', 'SHOP-CONVERT-A');
        $secondStore = $this->createStore($user, 'Tiktokshop', 'Toko Kedua', 'SHOP-CONVERT-B');
        $firstProduct = Product::create([
            'store_id' => $firstStore->id,
            'platform' => 'Shopee',
            'product_id' => 82001,
            'product_name' => 'Produk Pertama',
            'product_sku' => 'SKU-KONVERSI-SAMA',
            'stock' => 10,
            'price' => 90000,
        ]);
        $secondProduct = Product::create([
            'store_id' => $secondStore->id,
            'platform' => 'Tiktokshop',
            'product_id' => 82002,
            'product_name' => 'Produk Kedua',
            'product_sku' => 'sku-konversi-sama',
            'stock' => 12,
            'price' => 95000,
        ]);

        $this->actingAs($user)->postJson('/api/master-products/convert-marketplace', [
            'product_ids' => [$firstProduct->id, $secondProduct->id],
        ])->assertCreated()
            ->assertJsonPath('created_product_count', 1)
            ->assertJsonPath('created_variant_count', 1)
            ->assertJsonPath('linked_existing_sku_count', 0);

        $this->actingAs($user)->postJson('/api/master-products/convert-marketplace', [
            'product_ids' => [$firstProduct->id, $secondProduct->id],
        ])->assertOk()
            ->assertJsonPath('created_product_count', 0)
            ->assertJsonPath('created_variant_count', 0)
            ->assertJsonPath('already_converted_product_count', 2)
            ->assertJsonPath('linked_existing_sku_count', 1)
            ->assertJsonPath('sync_queued', true);

        $this->assertDatabaseCount('master_products', 1);
        $this->assertDatabaseCount('master_product_variants', 1);
    }

    public function test_marketplace_conversion_rejects_products_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherStore = $this->createStore($otherUser, 'Shopee', 'Toko Lain', 'SHOP-CONVERT-OTHER');
        $otherProduct = Product::create([
            'store_id' => $otherStore->id,
            'platform' => 'Shopee',
            'product_id' => 83001,
            'product_name' => 'Produk Milik Orang Lain',
            'product_sku' => 'SKU-OTHER',
            'stock' => 3,
            'price' => 50000,
        ]);

        $this->actingAs($user)->postJson('/api/master-products/convert-marketplace', [
            'product_ids' => [$otherProduct->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('product_ids');

        $this->assertDatabaseCount('master_products', 0);
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

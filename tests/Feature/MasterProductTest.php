<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\SkuSyncGroup;
use App\Models\User;
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
            ->assertJsonPath('variants.0.stock', 17)
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
}

<?php

namespace Tests\Feature;

use App\Models\AccessProfile;
use App\Models\MasterProduct;
use App\Models\User;
use App\Services\AccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_profile_and_permission_dependencies_are_added(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/access-profiles', [
            'name' => 'Admin Produk',
            'layout' => 'custom',
            'permissions' => ['products.hpp.edit'],
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('profile.name', 'Admin Produk')
            ->assertJsonPath('profile.permissions.0', 'products.hpp.edit');

        $profile = AccessProfile::firstOrFail();
        $this->assertContains('products.hpp.view', $profile->permissions);
        $this->assertContains('products.view', $profile->permissions);
    }

    public function test_profile_mode_can_only_access_allowed_apis_and_hides_order_finance(): void
    {
        $user = User::factory()->create();
        $profile = $user->accessProfiles()->create([
            'name' => 'Admin Pesanan',
            'layout' => 'order_operations',
            'permissions' => ['orders.view'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('totals', null);

        $this->actingAs($user)
            ->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->getJson('/api/profit-tracker')
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->getJson('/api/access-profiles')
            ->assertForbidden();
    }

    public function test_switching_back_to_owner_requires_the_account_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('owner-secret')]);
        $profile = $user->accessProfiles()->create([
            'name' => 'Admin 1',
            'layout' => 'read_only',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->postJson('/api/access/switch-owner', ['password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->actingAs($user)
            ->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->postJson('/api/access/switch-owner', ['password' => 'owner-secret'])
            ->assertOk();

        $this->getJson('/api/access/context')
            ->assertOk()
            ->assertJsonPath('is_owner_mode', true);
    }

    public function test_selected_profile_is_remembered_for_the_device_and_cleared_for_owner(): void
    {
        $user = User::factory()->create(['password' => bcrypt('owner-secret')]);
        $profile = $user->accessProfiles()->create([
            'name' => 'Admin Gudang',
            'layout' => 'order_operations',
            'permissions' => ['orders.view'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/access/switch-profile', ['profile_id' => $profile->id])
            ->assertOk()
            ->assertCookie(AccessControlService::DEVICE_COOKIE_KEY);

        $this->postJson('/api/access/switch-owner', ['password' => 'owner-secret'])
            ->assertOk()
            ->assertCookieExpired(AccessControlService::DEVICE_COOKIE_KEY);
    }

    public function test_disabled_active_profile_logs_the_device_out_instead_of_promoting_it_to_owner(): void
    {
        $user = User::factory()->create();
        $profile = $user->accessProfiles()->create([
            'name' => 'Admin Lama',
            'layout' => 'read_only',
            'permissions' => ['dashboard.view'],
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->getJson('/api/access/context')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Profil akses pada perangkat ini sudah dinonaktifkan. Silakan masuk kembali.');

        $this->assertGuest();
    }

    public function test_bulk_master_update_uses_the_permission_for_the_requested_field(): void
    {
        $user = User::factory()->create();
        $profile = $user->accessProfiles()->create([
            'name' => 'Admin Stok',
            'layout' => 'product_operations',
            'permissions' => ['products.view', 'products.stock.manage'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->putJson('/api/master-products/variants/bulk', [
                'variant_ids' => [999999],
                'field' => 'hpp',
                'value' => 100000,
            ])
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'products.hpp.edit');

        $this->actingAs($user)
            ->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->putJson('/api/master-products/variants/bulk', [
                'variant_ids' => [999999],
                'field' => 'stock',
                'value' => 10,
            ])
            ->assertStatus(422);
    }

    public function test_general_product_edit_cannot_change_restricted_variant_values(): void
    {
        $user = User::factory()->create();
        $product = MasterProduct::create([
            'user_id' => $user->id,
            'name' => 'Produk Lama',
            'status' => 'active',
            'source' => 'manual',
        ]);
        $variant = $product->variants()->create([
            'user_id' => $user->id,
            'sku' => 'SKU-ACCESS-1',
            'variant_name' => 'Hitam',
            'hpp' => 125000,
            'stock' => 14,
            'is_active' => true,
            'is_bundle' => false,
        ]);
        $profile = $user->accessProfiles()->create([
            'name' => 'Editor Data',
            'layout' => 'custom',
            'permissions' => ['products.view', 'products.edit'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->putJson("/api/master-products/{$product->id}", [
                'name' => 'Produk Baru',
                'brand' => null,
                'category' => null,
                'description' => null,
                'image' => null,
                'status' => 'active',
                'variants' => [[
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'variant_name' => $variant->variant_name,
                    'barcode' => null,
                    'supplier_id' => null,
                    'hpp' => 1,
                    'stock' => 999,
                    'is_active' => true,
                    'is_bundle' => false,
                    'components' => [],
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('master_products', [
            'id' => $product->id,
            'name' => 'Produk Baru',
        ]);
        $this->assertDatabaseHas('master_product_variants', [
            'id' => $variant->id,
            'hpp' => 125000,
            'stock' => 14,
        ]);
    }

    public function test_order_fulfillment_endpoints_require_their_specific_permissions(): void
    {
        $user = User::factory()->create();
        $profile = $user->accessProfiles()->create([
            'name' => 'Admin Baca Pesanan',
            'layout' => 'read_only',
            'permissions' => ['orders.view'],
            'is_active' => true,
        ]);

        $session = [AccessControlService::SESSION_KEY => $profile->id];

        $this->actingAs($user)
            ->withSession($session)
            ->getJson('/api/orders/999999/fulfillment-options')
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession($session)
            ->postJson('/api/orders/fulfillment/arrange', ['order_ids' => [999999]])
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession($session)
            ->get('/api/orders/999999/shipping-label')
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession($session)
            ->postJson('/api/orders/fulfillment/shipping-labels', ['order_ids' => [999998, 999999]])
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession($session)
            ->postJson('/api/orders/fulfillment/shipping-label-jobs', ['order_ids' => [999998, 999999]])
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession($session)
            ->postJson('/api/orders/fulfillment/picking-list', ['order_ids' => [999999]])
            ->assertForbidden();
    }
}

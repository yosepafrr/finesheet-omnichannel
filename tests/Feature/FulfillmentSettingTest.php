<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FulfillmentSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_fulfillment_settings_for_an_owned_store(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Toko Fulfillment',
            'shopee_shop_id' => 'FULFILLMENT-1',
        ]);

        $this->actingAs($user)
            ->putJson("/api/fulfillment-settings/stores/{$store->id}", [
                'fulfillment_handover_method' => 'dropoff',
                'shipping_label_template' => 'finesheet',
            ])
            ->assertOk()
            ->assertJsonPath('store.fulfillment_handover_method', 'dropoff')
            ->assertJsonPath('store.shipping_label_template', 'finesheet');

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'fulfillment_handover_method' => 'dropoff',
            'shipping_label_template' => 'finesheet',
        ]);
    }

    public function test_owner_cannot_update_another_users_store(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $store = Store::create([
            'user_id' => $otherUser->id,
            'platform' => 'Shopee',
            'store_name' => 'Toko Milik Pengguna Lain',
            'shopee_shop_id' => 'FULFILLMENT-2',
        ]);

        $this->actingAs($owner)
            ->putJson("/api/fulfillment-settings/stores/{$store->id}", [
                'fulfillment_handover_method' => 'pickup',
                'shipping_label_template' => 'platform',
            ])
            ->assertNotFound();
    }
}

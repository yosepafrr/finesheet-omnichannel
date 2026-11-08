<?php

namespace Tests\Feature;

use App\Jobs\ArrangeOrderShipmentJob;
use App\Jobs\SyncShopeeOrderJob;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FulfillmentSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipments_are_queued_separately_from_a_large_order_import(): void
    {
        config()->set('broadcasting.default', 'null');
        Queue::fake();
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id, 'platform' => 'Shopee',
            'store_name' => 'Toko Fulfillment', 'shopee_shop_id' => 'FULFILLMENT-QUEUE',
        ]);
        $order = Order::create([
            'store_id' => $store->id, 'platform' => 'Shopee',
            'order_sn' => 'ORDER-QUEUE', 'order_status' => 'READY_TO_SHIP',
        ]);
        SyncShopeeOrderJob::dispatch($store->id, 180)->onQueue('orders');

        $this->actingAs($user)->postJson('/api/orders/fulfillment/arrange', [
            'order_ids' => [$order->id],
        ])->assertAccepted()->assertJsonPath('queued', 1);

        Queue::assertPushedOn('fulfillment', ArrangeOrderShipmentJob::class, fn ($job) => $job->orderId === $order->id);
        Queue::assertPushedOn('orders', SyncShopeeOrderJob::class);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'fulfillment_status' => 'queued']);
    }

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

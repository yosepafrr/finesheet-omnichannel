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
            ->putJson("/api/fulfillment-settings/stores/{$store->id}", $this->settings([
                'fulfillment_handover_method' => 'dropoff',
                'shipping_label_template' => 'finesheet',
                'shopee_thermal_printing' => true,
                'printing_layout' => 'combined',
            ]))
            ->assertOk()
            ->assertJsonPath('store.fulfillment_handover_method', 'dropoff')
            ->assertJsonPath('store.shipping_label_template', 'finesheet')
            ->assertJsonPath('store.shopee_thermal_printing', true)
            ->assertJsonPath('store.printing_layout', 'combined');

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'fulfillment_handover_method' => 'dropoff',
            'shipping_label_template' => 'finesheet',
            'shopee_thermal_printing' => true,
            'printing_layout' => 'combined',
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
            ->putJson("/api/fulfillment-settings/stores/{$store->id}", $this->settings([
                'fulfillment_handover_method' => 'pickup',
                'shipping_label_template' => 'platform',
            ]))
            ->assertNotFound();
    }

    public function test_owner_can_bulk_update_selected_stores(): void
    {
        $user = User::factory()->create();
        $selected = collect(['Toko A', 'Toko B'])->map(fn (string $name) => Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => $name,
            'shopee_shop_id' => $name,
        ]));
        $untouched = Store::create([
            'user_id' => $user->id,
            'platform' => 'Tiktokshop',
            'store_name' => 'Toko C',
            'shopee_shop_id' => 'TOKO-C',
        ]);

        $this->actingAs($user)
            ->putJson('/api/fulfillment-settings/stores', $this->settings([
                'store_ids' => $selected->pluck('id')->all(),
                'shipping_label_template' => 'finesheet',
                'shopee_thermal_printing' => true,
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'stores');

        foreach ($selected as $store) {
            $this->assertDatabaseHas('stores', [
                'id' => $store->id,
                'shipping_label_template' => 'finesheet',
                'shopee_thermal_printing' => true,
            ]);
        }
        $this->assertDatabaseHas('stores', [
            'id' => $untouched->id,
            'shipping_label_template' => 'platform',
            'shopee_thermal_printing' => false,
        ]);
    }

    public function test_at_least_one_printing_document_must_be_selected(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Tiktokshop',
            'store_name' => 'Toko Dokumen',
            'shopee_shop_id' => 'DOCS-1',
        ]);

        $this->actingAs($user)
            ->putJson("/api/fulfillment-settings/stores/{$store->id}", $this->settings([
                'print_shipping_label' => false,
                'print_packing_list' => false,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('print_shipping_label');
    }

    private function settings(array $overrides = []): array
    {
        return array_replace([
            'fulfillment_handover_method' => 'pickup',
            'shipping_label_template' => 'platform',
            'shopee_thermal_printing' => false,
            'print_shipping_label' => true,
            'print_packing_list' => false,
            'shipping_label_size' => 'a6',
            'packing_list_size' => 'a6',
            'printing_layout' => 'separate',
            'printing_file_format' => 'pdf',
        ], $overrides);
    }
}

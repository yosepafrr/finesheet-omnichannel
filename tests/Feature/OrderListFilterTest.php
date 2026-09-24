<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_process_filter_normalizes_shopee_and_tiktok_statuses(): void
    {
        $user = User::factory()->create();
        $shopee = $this->createStore($user, 'Shopee', 'Shopee Utama', 'shop-1');
        $tiktok = $this->createStore($user, 'Tiktokshop', 'TikTok Utama', 'shop-2');

        $this->createOrder($shopee, 'SHOPEE-READY', 'READY_TO_SHIP', now()->subMinutes(4));
        $this->createOrder($shopee, 'SHOPEE-PROCESSED', 'PROCESSED', now()->subMinutes(3));
        $this->createOrder($tiktok, 'TIKTOK-SHIPMENT', 'AWAITING_SHIPMENT', now()->subMinutes(2));
        $this->createOrder($tiktok, 'TIKTOK-COLLECTION', 'AWAITING_COLLECTION', now()->subMinute());

        $response = $this->actingAs($user)->getJson('/api/orders?statuses=READY_TO_SHIP,AWAITING_SHIPMENT,AWAITING_COLLECTION,PROCESSED&shipping_process=needs_processing');

        $response
            ->assertOk()
            ->assertJsonPath('shipping_process_counts.all', 4)
            ->assertJsonPath('shipping_process_counts.needs_processing', 2)
            ->assertJsonPath('shipping_process_counts.processed', 2)
            ->assertJsonCount(2, 'orders')
            ->assertJsonPath('orders.0.order_sn', 'TIKTOK-SHIPMENT')
            ->assertJsonPath('orders.0.platform', 'Tiktokshop')
            ->assertJsonPath('orders.1.order_sn', 'SHOPEE-READY');

        $allResponse = $this->actingAs($user)->getJson('/api/orders?statuses=READY_TO_SHIP,AWAITING_SHIPMENT,AWAITING_COLLECTION,PROCESSED&shipping_process=all');

        $allResponse
            ->assertOk()
            ->assertJsonPath('shipping_process_counts.all', 4)
            ->assertJsonCount(4, 'orders');
    }

    public function test_platform_filter_scopes_orders_and_process_counts(): void
    {
        $user = User::factory()->create();
        $shopee = $this->createStore($user, 'Shopee', 'Shopee Utama', 'shop-1');
        $tiktok = $this->createStore($user, 'Tiktokshop', 'TikTok Utama', 'shop-2');

        $this->createOrder($shopee, 'SHOPEE-READY', 'READY_TO_SHIP', now()->subMinutes(2));
        $this->createOrder($shopee, 'SHOPEE-PROCESSED', 'PROCESSED', now()->subMinute());
        $this->createOrder($tiktok, 'TIKTOK-COLLECTION', 'AWAITING_COLLECTION', now());

        $response = $this->actingAs($user)->getJson('/api/orders?platform=Shopee&statuses=READY_TO_SHIP,AWAITING_SHIPMENT,AWAITING_COLLECTION,PROCESSED&shipping_process=processed');

        $response
            ->assertOk()
            ->assertJsonPath('shipping_process_counts.needs_processing', 1)
            ->assertJsonPath('shipping_process_counts.processed', 1)
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.order_sn', 'SHOPEE-PROCESSED')
            ->assertJsonPath('orders.0.store_name', 'Shopee Utama');
    }

    private function createStore(User $user, string $platform, string $name, string $shopId): Store
    {
        return Store::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'store_name' => $name,
            'shopee_shop_id' => $shopId,
            'platform_shop_id' => $shopId,
        ]);
    }

    private function createOrder(Store $store, string $orderSn, string $status, $orderTime): void
    {
        Order::withoutEvents(function () use ($store, $orderSn, $status, $orderTime) {
            Order::create([
                'store_id' => $store->id,
                'platform' => $store->platform,
                'order_sn' => $orderSn,
                'order_status' => $status,
                'order_time' => $orderTime,
                'raw_data' => [],
            ]);
        });
    }
}

<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_filter_returns_only_unpaid_orders_and_exposes_its_count(): void
    {
        $user = User::factory()->create();
        $shopee = $this->createStore($user, 'Shopee', 'Shopee Utama', 'shop-unpaid-1');
        $tiktok = $this->createStore($user, 'Tiktokshop', 'TikTok Utama', 'shop-unpaid-2');

        $this->createOrder($shopee, 'SHOPEE-UNPAID', 'UNPAID', now()->subMinute());
        $this->createOrder($tiktok, 'TIKTOK-UNPAID', 'UNPAID', now());
        $this->createOrder($shopee, 'SHOPEE-READY', 'READY_TO_SHIP', now()->subMinutes(2));

        $this->actingAs($user)
            ->getJson('/api/orders?statuses=UNPAID')
            ->assertOk()
            ->assertJsonPath('status_counts.UNPAID', 2)
            ->assertJsonCount(2, 'orders')
            ->assertJsonPath('orders.0.order_sn', 'TIKTOK-UNPAID')
            ->assertJsonPath('orders.1.order_sn', 'SHOPEE-UNPAID');

        $this->actingAs($user)
            ->getJson('/api/orders?platform=Shopee&statuses=UNPAID')
            ->assertOk()
            ->assertJsonPath('status_counts.UNPAID', 1)
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.order_sn', 'SHOPEE-UNPAID');
    }

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

    public function test_processed_orders_can_be_filtered_to_labels_not_printed_in_finesheet(): void
    {
        $user = User::factory()->create();
        $shopee = $this->createStore($user, 'Shopee', 'Shopee Utama', 'shop-label-1');
        $tiktok = $this->createStore($user, 'Tiktokshop', 'TikTok Utama', 'shop-label-2');

        $unprintedShopee = $this->createOrder($shopee, 'SHOPEE-UNPRINTED', 'PROCESSED', now()->subMinutes(3));
        $printedShopee = $this->createOrder($shopee, 'SHOPEE-PRINTED', 'PROCESSED', now()->subMinutes(2));
        $unprintedTiktok = $this->createOrder($tiktok, 'TIKTOK-UNPRINTED', 'AWAITING_COLLECTION', now()->subMinute());

        Order::withoutEvents(function () use ($printedShopee) {
            $printedShopee->update(['shipping_label_printed_at' => now()]);
        });

        $this->actingAs($user)
            ->getJson('/api/orders?statuses=READY_TO_SHIP,AWAITING_SHIPMENT,AWAITING_COLLECTION,PROCESSED&shipping_process=processed&shipping_label_print_status=unprinted')
            ->assertOk()
            ->assertJsonPath('shipping_label_print_counts.all', 3)
            ->assertJsonPath('shipping_label_print_counts.unprinted', 2)
            ->assertJsonCount(2, 'orders')
            ->assertJsonPath('orders.0.order_sn', 'TIKTOK-UNPRINTED')
            ->assertJsonPath('orders.1.order_sn', 'SHOPEE-UNPRINTED');
    }

    public function test_store_filter_accepts_multiple_owned_stores(): void
    {
        $user = User::factory()->create();
        $firstStore = $this->createStore($user, 'Shopee', 'Toko Pertama', 'shop-multi-1');
        $secondStore = $this->createStore($user, 'Shopee', 'Toko Kedua', 'shop-multi-2');
        $excludedStore = $this->createStore($user, 'Tiktokshop', 'Toko Lain', 'shop-multi-3');

        $this->createOrder($firstStore, 'MULTI-FIRST', 'PROCESSED', now()->subMinutes(2));
        $this->createOrder($secondStore, 'MULTI-SECOND', 'PROCESSED', now()->subMinute());
        $this->createOrder($excludedStore, 'MULTI-EXCLUDED', 'PROCESSED', now());

        $this->actingAs($user)
            ->getJson("/api/orders?statuses=PROCESSED&store_ids={$firstStore->id},{$secondStore->id}")
            ->assertOk()
            ->assertJsonCount(2, 'orders')
            ->assertJsonPath('orders.0.order_sn', 'MULTI-SECOND')
            ->assertJsonPath('orders.1.order_sn', 'MULTI-FIRST');
    }

    public function test_store_filter_rejects_a_store_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownedStore = $this->createStore($user, 'Shopee', 'Toko Sendiri', 'shop-owned');
        $foreignStore = $this->createStore($otherUser, 'Shopee', 'Toko Asing', 'shop-foreign');

        $this->actingAs($user)
            ->getJson("/api/orders?store_ids={$ownedStore->id},{$foreignStore->id}")
            ->assertNotFound();
    }

    public function test_order_list_and_detail_can_serialize_package_data(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Tiktokshop', 'TikTok Utama', 'shop-1');
        $order = $this->createOrder($store, 'TIKTOK-PACKAGE', 'IN_TRANSIT', now());

        OrderPackage::withoutEvents(function () use ($order) {
            OrderPackage::create([
                'order_id' => $order->id,
                'platform' => 'Tiktokshop',
                'package_id' => 'PACKAGE-1',
                'tracking_number' => 'TRACK-1',
                'logistics_status' => 'Package is in transit',
                'normalized_logistics_status' => 'IN_TRANSIT',
                'raw_data' => [
                    'tracking' => [[
                        'description' => 'Package is in transit',
                        'update_time_millis' => 1_789_000_000_000,
                    ]],
                ],
            ]);
        });

        $this->actingAs($user)
            ->getJson('/api/orders?statuses=IN_TRANSIT')
            ->assertOk()
            ->assertJsonPath('orders.0.packages.0.tracking_number', 'TRACK-1');

        $this->actingAs($user)
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.packages.0.history.0.description', 'Package is in transit');
    }

    public function test_order_list_exposes_affiliate_badge_metadata(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee Utama', 'shop-1');
        $affiliateOrder = $this->createOrder($store, 'SHOPEE-AFFILIATE', 'COMPLETED', now());
        $regularOrder = $this->createOrder($store, 'SHOPEE-REGULAR', 'COMPLETED', now()->subMinute());

        Order::withoutEvents(function () use ($affiliateOrder, $regularOrder) {
            $affiliateOrder->update([
                'order_selling_price' => 100000,
                'escrow_amount' => 90000,
                'fee_details' => ['order_ams_commission_fee' => 10000],
            ]);
            $regularOrder->update([
                'order_selling_price' => 100000,
                'escrow_amount' => 100000,
                'fee_details' => [],
            ]);
        });

        $response = $this->actingAs($user)->getJson('/api/orders?statuses=COMPLETED');

        $response
            ->assertOk()
            ->assertJsonPath('orders.0.order_sn', 'SHOPEE-AFFILIATE')
            ->assertJsonPath('orders.0.is_affiliate', true)
            ->assertJsonPath('orders.0.affiliate_percentage', 10)
            ->assertJsonPath('orders.1.order_sn', 'SHOPEE-REGULAR')
            ->assertJsonPath('orders.1.is_affiliate', false)
            ->assertJsonPath('orders.1.affiliate_percentage', null);
    }

    public function test_order_list_paginates_and_searches_on_the_server(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee Utama', 'shop-page');

        foreach (range(1, 35) as $index) {
            $this->createOrder(
                $store,
                sprintf('ORDER-%02d', $index),
                'COMPLETED',
                now()->subMinutes($index),
            );
        }

        $this->actingAs($user)
            ->getJson('/api/orders?statuses=COMPLETED&per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(10, 'orders')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonPath('pagination.total', 35)
            ->assertJsonPath('pagination.last_page', 4)
            ->assertJsonPath('totals_scope', 'page');

        $this->actingAs($user)
            ->getJson('/api/orders?statuses=COMPLETED&search=ORDER-35')
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.order_sn', 'ORDER-35')
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_sorting_is_server_side_stable_across_pages_and_places_missing_dates_last(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Sorting', 'sort-shop');
        $a = $this->createOrder($store, 'SORT-A', 'PROCESSED', now()->subDays(3));
        $b = $this->createOrder($store, 'SORT-B', 'PROCESSED', now()->subDays(2));
        $c = $this->createOrder($store, 'SORT-C', 'PROCESSED', now()->subDay());
        $a->forceFill(['ready_to_ship_at' => now()->subHour(), 'ship_by_date' => now()->addDay()])->saveQuietly();
        $b->forceFill(['ready_to_ship_at' => now()->subHours(2), 'ship_by_date' => now()->addDays(2)])->saveQuietly();
        $this->actingAs($user);
        foreach ([
            'ready_oldest' => ['SORT-B', 'SORT-A', 'SORT-C'],
            'ready_newest' => ['SORT-A', 'SORT-B', 'SORT-C'],
            'deadline_oldest' => ['SORT-A', 'SORT-B', 'SORT-C'],
            'deadline_newest' => ['SORT-B', 'SORT-A', 'SORT-C'],
            'created_newest' => ['SORT-C', 'SORT-B', 'SORT-A'],
        ] as $sort => $expected) {
            $response = $this->getJson('/api/orders?statuses=PROCESSED&sort_by='.$sort)->assertOk();
            $this->assertSame($expected, array_column($response->json('orders'), 'order_sn'));
            $this->getJson('/api/orders?statuses=PROCESSED&sort_by='.$sort.'&per_page=1&page=2')
                ->assertOk()->assertJsonPath('orders.0.order_sn', $expected[1]);
        }
        $this->getJson('/api/orders?sort_by=invalid')->assertUnprocessable();
    }

    public function test_sort_dates_are_normalized_when_platform_payload_is_saved(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Tiktokshop', 'TikTok', 'sort-tiktok');
        $order = $this->createOrder($store, 'DATES', 'AWAITING_COLLECTION', now());
        $paid = now()->subHours(3)->startOfSecond();
        $deadline = now()->addDays(2)->startOfSecond();
        $order->update(['raw_data' => ['rts_time' => 0, 'paid_time' => $paid->timestamp, 'rts_sla_time' => $deadline->timestamp]]);
        $this->assertSame($paid->timestamp, $order->fresh()->ready_to_ship_at->timestamp);
        $this->assertSame($deadline->timestamp, $order->fresh()->ship_by_date->timestamp);
        $this->assertNull((new \App\Services\OrderListSortService)->dates(['pay_time' => 'invalid'], 'Shopee')['ready_to_ship_at'] ?? null);
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

    private function createOrder(Store $store, string $orderSn, string $status, $orderTime): Order
    {
        return Order::withoutEvents(function () use ($store, $orderSn, $status, $orderTime) {
            return Order::create([
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

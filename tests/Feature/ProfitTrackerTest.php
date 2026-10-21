<?php

namespace Tests\Feature;

use App\Models\MarketplaceAdSpend;
use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\OrderProduct;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfitTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_breakdown_and_total_use_the_same_escrow_values(): void
    {
        $user = User::factory()->create();
        $shopee = $this->createStore($user, 'Shopee', 'Shopee Utama', 'shop-1');
        $tiktok = $this->createStore($user, 'Tiktokshop', 'TikTok Utama', 'shop-2');

        $this->createOrder($shopee, 'SHOPEE-READY', 'READY_TO_SHIP', 100_000, 80_000);
        $this->createOrder($shopee, 'SHOPEE-SHIPPED', 'SHIPPED', 200_000);
        $this->createOrder($shopee, 'SHOPEE-CANCELLED', 'CANCELLED', 300_000);
        $this->createOrder($tiktok, 'TIKTOK-COLLECTION', 'AWAITING_COLLECTION', 400_000);
        $this->createOrder($tiktok, 'TIKTOK-COMPLETED', 'COMPLETED', 500_000);

        $response = $this->actingAs($user)->getJson('/api/profit-tracker');

        $response
            ->assertOk()
            ->assertJsonPath('total_escrow_amount', 700_000)
            ->assertJsonPath('total_hpp_amount', 0)
            ->assertJsonPath('total_order_count', 3)
            ->assertJsonPath('stores.0.id', $tiktok->id)
            ->assertJsonPath('stores.0.escrow', 400_000)
            ->assertJsonPath('stores.0.status_counts.perlu_dikirim', 1)
            ->assertJsonPath('stores.1.id', $shopee->id)
            ->assertJsonPath('stores.1.escrow', 300_000)
            ->assertJsonPath('stores.1.status_counts.perlu_dikirim', 1)
            ->assertJsonPath('stores.1.status_counts.dikirim', 1)
            ->assertJsonPath('stores.1.status_counts.return_cancel', 0);

        $storeTotal = collect($response->json('stores'))->sum('escrow');
        $this->assertSame((float) $response->json('total_escrow_amount'), (float) $storeTotal);
    }

    public function test_realtime_hpp_uses_the_same_orders_as_escrow_and_analytics_includes_completed_sales(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee HPP', 'shop-hpp');
        $product = Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 'product-hpp',
            'product_name' => 'Produk HPP',
            'product_sku' => 'SKU-HPP-PROFIT',
            'stock' => 10,
            'price' => 50_000,
        ]);
        $variant = VariantProduct::create([
            'product_id' => $product->id,
            'model_id' => 'variant-hpp',
            'model_name' => 'Black',
            'model_sku' => 'SKU-HPP-PROFIT',
            'stock' => 10,
            'price' => 50_000,
            'hpp' => 20_000,
        ]);

        $activeOrder = $this->createOrder($store, 'ACTIVE-HPP', 'SHIPPED', 100_000);
        $completedOrder = $this->createOrder($store, 'COMPLETED-HPP', 'COMPLETED', 75_000);

        foreach ([[$activeOrder, 2], [$completedOrder, 1]] as [$order, $quantity]) {
            OrderProduct::withoutEvents(fn () => OrderProduct::create([
                'order_id' => $order->id,
                'product_id' => $product->product_id,
                'product_name' => $product->product_name,
                'model_name' => $variant->model_name,
                'platform_variant_id' => $variant->model_id,
                'sku' => $variant->model_sku,
                'quantity_purchased' => $quantity,
                'price' => 50_000,
            ]));
        }

        $response = $this->actingAs($user)->getJson('/api/profit-tracker?analytics_period=today');

        $response
            ->assertOk()
            ->assertJsonPath('total_order_count', 1)
            ->assertJsonPath('total_escrow_amount', 100_000)
            ->assertJsonPath('total_hpp_amount', 40_000)
            ->assertJsonPath('total_profit_amount', 60_000)
            ->assertJsonPath('analytics.totals.order_count', 2)
            ->assertJsonPath('analytics.totals.hpp_amount', 60_000)
            ->assertJsonPath('analytics.totals.escrow_amount', 175_000)
            ->assertJsonPath('analytics.totals.profit_amount', 115_000);
    }

    public function test_profit_analytics_defaults_to_three_days_and_supports_other_presets(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee Preset', 'shop-preset');

        $this->createOrder($store, 'TODAY-ORDER', 'COMPLETED', 50_000);
        $recentOrder = $this->createOrder($store, 'RECENT-ORDER', 'COMPLETED', 80_000);
        $recentOrder->forceFill(['order_time' => now()->subDays(6)])->saveQuietly();

        $olderOrder = $this->createOrder($store, 'OLDER-ORDER', 'COMPLETED', 120_000);
        $olderOrder->forceFill(['order_time' => now()->subDays(10)])->saveQuietly();

        $thirtyDayOrder = $this->createOrder($store, 'THIRTY-DAY-ORDER', 'COMPLETED', 160_000);
        $thirtyDayOrder->forceFill(['order_time' => now()->subDays(20)])->saveQuietly();

        $this->actingAs($user)
            ->getJson('/api/profit-tracker')
            ->assertOk()
            ->assertJsonPath('analytics.period', 'last_3_days')
            ->assertJsonPath('analytics.totals.order_count', 1)
            ->assertJsonPath('analytics.totals.escrow_amount', 50_000);

        $this->actingAs($user)
            ->getJson('/api/profit-tracker?analytics_period=last_7_days')
            ->assertOk()
            ->assertJsonPath('analytics.totals.order_count', 2)
            ->assertJsonPath('analytics.totals.escrow_amount', 130_000);

        $this->actingAs($user)
            ->getJson('/api/profit-tracker?analytics_period=last_14_days')
            ->assertOk()
            ->assertJsonPath('analytics.totals.order_count', 3)
            ->assertJsonPath('analytics.totals.escrow_amount', 250_000);

        $this->actingAs($user)
            ->getJson('/api/profit-tracker?analytics_period=last_30_days')
            ->assertOk()
            ->assertJsonPath('analytics.period', 'last_30_days')
            ->assertJsonCount(30, 'analytics.points')
            ->assertJsonPath('analytics.totals.order_count', 4)
            ->assertJsonPath('analytics.totals.escrow_amount', 410_000);
    }

    public function test_store_filter_only_applies_to_analytics_and_ads_while_realtime_keeps_all_stores(): void
    {
        $user = User::factory()->create();
        $selectedStore = $this->createStore($user, 'Shopee', 'Shopee Terpilih', 'shop-selected');
        $otherStore = $this->createStore($user, 'Shopee', 'Shopee Lain', 'shop-other');

        $this->createOrder($selectedStore, 'SELECTED-ACTIVE', 'SHIPPED', 100_000);
        $this->createOrder($selectedStore, 'SELECTED-COMPLETED', 'COMPLETED', 200_000);
        $this->createOrder($otherStore, 'OTHER-ACTIVE', 'SHIPPED', 300_000);
        $this->createOrder($otherStore, 'OTHER-COMPLETED', 'COMPLETED', 400_000);

        foreach ([[$selectedStore, 20_000], [$otherStore, 50_000]] as [$store, $amount]) {
            MarketplaceAdSpend::create([
                'user_id' => $user->id,
                'store_id' => $store->id,
                'platform' => 'Shopee',
                'spend_date' => now('Asia/Jakarta')->toDateString(),
                'amount' => $amount,
                'currency' => 'IDR',
                'synced_at' => now(),
            ]);
        }

        $response = $this->actingAs($user)->getJson(
            "/api/profit-tracker?store_id={$selectedStore->id}&analytics_period=today",
        );

        $response
            ->assertOk()
            ->assertJsonPath('selected_store_id', $selectedStore->id)
            ->assertJsonPath('total_escrow_amount', 400_000)
            ->assertJsonPath('total_order_count', 2)
            ->assertJsonCount(2, 'stores')
            ->assertJsonCount(2, 'store_options')
            ->assertJsonPath('analytics.totals.escrow_amount', 300_000)
            ->assertJsonPath('analytics.totals.ad_spend_amount', 20_000)
            ->assertJsonPath('analytics.totals.profit_amount', 280_000);
    }

    public function test_shopee_ads_spend_reduces_daily_profit_but_not_realtime_profit(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee Ads', 'shop-ads');
        $this->createOrder($store, 'SHOPEE-ADS-ORDER', 'COMPLETED', 150_000);

        MarketplaceAdSpend::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'spend_date' => now('Asia/Jakarta')->toDateString(),
            'amount' => 35_000,
            'currency' => 'IDR',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/profit-tracker?analytics_period=today');

        $response
            ->assertOk()
            ->assertJsonPath('total_profit_amount', 0)
            ->assertJsonPath('analytics.totals.gross_profit_amount', 150_000)
            ->assertJsonPath('analytics.totals.ad_spend_amount', 35_000)
            ->assertJsonPath('analytics.totals.profit_amount', 115_000)
            ->assertJsonPath('analytics.points.0.ad_spend_amount', 35_000)
            ->assertJsonPath('analytics.points.0.profit_amount', 115_000)
            ->assertJsonPath('analytics.ad_spend.available', true)
            ->assertJsonPath('analytics.ad_spend.platforms_included.0', 'Shopee')
            ->assertJsonPath('analytics.ad_spend.platforms_pending.0', 'Tiktokshop');
    }

    public function test_return_cancel_is_limited_to_the_order_list_shipped_scope(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee Utama', 'shop-1');

        $this->createOrder($store, 'SHOPEE-SHIPPED', 'SHIPPED', 200_000);
        $returnedOrder = $this->createOrder($store, 'SHOPEE-RETURNED', 'IN_TRANSIT', 300_000);
        $this->createOrder($store, 'SHOPEE-CANCELLED', 'CANCELLED', 900_000);
        OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $returnedOrder->id,
            'platform' => 'Shopee',
            'external_return_id' => 'RETURN-SHIPPED-1',
            'normalized_status' => 'RETURN_PROCESSING',
        ]));

        $profitResponse = $this->actingAs($user)->getJson(
            '/api/profit-tracker?include_perlu_dikirim=0&include_dikirim=1&include_return=1',
        );

        $profitResponse
            ->assertOk()
            ->assertJsonPath('total_escrow_amount', 500_000)
            ->assertJsonPath('stores.0.included_order_count', 2)
            ->assertJsonPath('stores.0.status_counts.dikirim', 2)
            ->assertJsonPath('stores.0.status_counts.return_cancel', 1);

        $ordersResponse = $this->actingAs($user)->getJson(
            "/api/orders?store_id={$store->id}&statuses=SHIPPED,IN_TRANSIT,DELIVERED,TO_CONFIRM_RECEIVE",
        );

        $ordersResponse
            ->assertOk()
            ->assertJsonPath('totals.escrow_amount', 500_000)
            ->assertJsonCount(2, 'orders');

        $withoutReturns = $this->actingAs($user)->getJson(
            '/api/profit-tracker?include_perlu_dikirim=0&include_dikirim=1&include_return=0',
        );
        $withoutReturns
            ->assertOk()
            ->assertJsonPath('total_escrow_amount', 200_000)
            ->assertJsonPath('stores.0.included_order_count', 1);
    }

    public function test_return_correction_reduces_profit_on_the_return_date(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee Return', 'shop-return');
        $order = $this->createOrder($store, 'RETURN-CORRECTION', 'COMPLETED', 150_000);
        $order->forceFill([
            'order_time' => now('Asia/Jakarta')->subDays(2)->setTime(10, 0),
        ])->saveQuietly();
        $this->attachHppItem($order, 60_000);

        OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'platform' => 'Shopee',
            'external_return_id' => 'RETURN-CORRECTION-1',
            'return_status' => 'COMPLETED',
            'platform_status' => 'COMPLETED',
            'normalized_status' => 'REFUND_COMPLETED',
            'refund_amount' => 150_000,
            'created_at_platform' => now('Asia/Jakarta')->setTime(9, 0),
        ]));

        $response = $this->actingAs($user)->getJson(
            '/api/profit-tracker?analytics_period=last_3_days',
        );

        $response
            ->assertOk()
            ->assertJsonPath('analytics.points.0.order_count', 1)
            ->assertJsonPath('analytics.points.0.correction_count', 0)
            ->assertJsonPath('analytics.points.0.hpp_amount', 60_000)
            ->assertJsonPath('analytics.points.0.escrow_amount', 150_000)
            ->assertJsonPath('analytics.points.0.profit_amount', 90_000)
            ->assertJsonPath('analytics.points.2.order_count', 0)
            ->assertJsonPath('analytics.points.2.correction_count', 1)
            ->assertJsonPath('analytics.points.2.hpp_amount', -60_000)
            ->assertJsonPath('analytics.points.2.escrow_amount', -150_000)
            ->assertJsonPath('analytics.points.2.profit_amount', -90_000)
            ->assertJsonPath('analytics.totals.profit_amount', 0);

        $bucket = now('Asia/Jakarta')->toDateString();
        $this->actingAs($user)
            ->getJson("/api/profit-tracker/breakdown?bucket={$bucket}&granularity=day")
            ->assertOk()
            ->assertJsonPath('totals.sale_count', 0)
            ->assertJsonPath('totals.correction_count', 1)
            ->assertJsonPath('events.0.type', 'return')
            ->assertJsonPath('events.0.order_sn', 'RETURN-CORRECTION')
            ->assertJsonPath('events.0.profit_amount', -90_000);
    }

    public function test_failed_delivery_wins_over_return_and_is_only_corrected_once(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Tiktokshop', 'TikTok Gagal', 'shop-failed');
        $order = $this->createOrder($store, 'FAILED-CORRECTION', 'COMPLETED', 200_000);
        $order->forceFill([
            'order_status' => 'CANCELLED',
            'order_time' => now('Asia/Jakarta')->subDays(2)->setTime(8, 0),
            'escrow_amount' => 0,
            'order_selling_price' => 200_000,
        ])->saveQuietly();
        $this->attachHppItem($order, 80_000);

        OrderPackage::withoutEvents(fn () => OrderPackage::create([
            'order_id' => $order->id,
            'platform' => 'Tiktokshop',
            'package_id' => 'FAILED-PACKAGE-1',
            'normalized_logistics_status' => 'DELIVERY_FAILED',
            'failed_at' => now('Asia/Jakarta')->setTime(11, 0),
            'raw_data' => [],
        ]));
        OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'platform' => 'Tiktokshop',
            'external_return_id' => 'FAILED-RETURN-1',
            'return_status' => 'COMPLETED',
            'platform_status' => 'COMPLETED',
            'normalized_status' => 'REFUND_COMPLETED',
            'created_at_platform' => now('Asia/Jakarta')->setTime(12, 0),
        ]));

        $response = $this->actingAs($user)->getJson(
            '/api/profit-tracker?analytics_period=last_3_days',
        );

        $response
            ->assertOk()
            ->assertJsonPath('analytics.points.0.order_count', 1)
            ->assertJsonPath('analytics.points.0.profit_amount', 120_000)
            ->assertJsonPath('analytics.points.2.correction_count', 1)
            ->assertJsonPath('analytics.points.2.profit_amount', -120_000)
            ->assertJsonPath('analytics.totals.correction_count', 1)
            ->assertJsonPath('analytics.totals.profit_amount', 0);

        $bucket = now('Asia/Jakarta')->toDateString();
        $this->actingAs($user)
            ->getJson("/api/profit-tracker/breakdown?bucket={$bucket}&granularity=day")
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.type', 'failed_delivery');
    }

    public function test_cancelled_return_request_does_not_reduce_profit(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Tiktokshop', 'TikTok Return Batal', 'shop-cancelled-return');
        $order = $this->createOrder($store, 'CANCELLED-RETURN-REQUEST', 'COMPLETED', 120_000);
        $this->attachHppItem($order, 50_000);

        OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'platform' => 'Tiktokshop',
            'external_return_id' => 'CANCELLED-RETURN-1',
            'return_status' => 'RETURN_OR_REFUND_REQUEST_CANCEL',
            'platform_status' => 'RETURN_OR_REFUND_REQUEST_CANCEL',
            'normalized_status' => 'CANCELLED',
            'created_at_platform' => now('Asia/Jakarta')->setTime(7, 0),
        ]));

        $this->actingAs($user)
            ->getJson('/api/profit-tracker?analytics_period=today')
            ->assertOk()
            ->assertJsonPath('analytics.totals.order_count', 1)
            ->assertJsonPath('analytics.totals.correction_count', 0)
            ->assertJsonPath('analytics.totals.hpp_amount', 50_000)
            ->assertJsonPath('analytics.totals.escrow_amount', 120_000)
            ->assertJsonPath('analytics.totals.profit_amount', 70_000);
    }

    public function test_return_correction_reconciles_negative_final_escrow_on_the_return_date(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee Refund', 'shop-negative-refund');
        $order = $this->createOrder($store, 'NEGATIVE-RETURN-CORRECTION', 'COMPLETED', -16_300);
        $order->forceFill([
            'order_time' => now('Asia/Jakarta')->subDays(2)->setTime(10, 0),
            'order_selling_price' => 139_000,
            'fee_details' => [
                'order_income' => [
                    'commission_fee' => 6_500,
                    'reverse_shipping_fee' => 9_800,
                    'seller_return_refund' => 139_000,
                ],
            ],
        ])->saveQuietly();
        $this->attachHppItem($order, 90_000);

        OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'platform' => 'Shopee',
            'external_return_id' => 'NEGATIVE-RETURN-CORRECTION-1',
            'return_status' => 'COMPLETED',
            'platform_status' => 'COMPLETED',
            'normalized_status' => 'REFUND_COMPLETED',
            'refund_amount' => 139_000,
            'created_at_platform' => now('Asia/Jakarta')->setTime(9, 0),
        ]));

        $response = $this->actingAs($user)->getJson(
            '/api/profit-tracker?analytics_period=last_3_days',
        );

        $response
            ->assertOk()
            ->assertJsonPath('analytics.points.0.escrow_amount', 132_500)
            ->assertJsonPath('analytics.points.0.profit_amount', 42_500)
            ->assertJsonPath('analytics.points.2.hpp_amount', -90_000)
            ->assertJsonPath('analytics.points.2.escrow_amount', -148_800)
            ->assertJsonPath('analytics.points.2.profit_amount', -58_800)
            ->assertJsonPath('analytics.totals.hpp_amount', 0)
            ->assertJsonPath('analytics.totals.escrow_amount', -16_300)
            ->assertJsonPath('analytics.totals.profit_amount', -16_300);
    }

    public function test_old_order_return_is_loaded_into_the_current_correction_period(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee Return Lama', 'shop-old-return');
        $order = $this->createOrder($store, 'OLD-ORDER-RETURN-TODAY', 'COMPLETED', 150_000);
        $order->forceFill([
            'order_time' => now('Asia/Jakarta')->subDays(45)->setTime(10, 0),
        ])->saveQuietly();
        $this->attachHppItem($order, 60_000);

        OrderReturn::withoutEvents(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'platform' => 'Shopee',
            'external_return_id' => 'OLD-ORDER-RETURN-TODAY-1',
            'return_status' => 'COMPLETED',
            'platform_status' => 'COMPLETED',
            'normalized_status' => 'REFUND_COMPLETED',
            'refund_amount' => 150_000,
            'created_at_platform' => now('Asia/Jakarta')->setTime(14, 0),
        ]));

        $this->actingAs($user)
            ->getJson('/api/profit-tracker?analytics_period=today')
            ->assertOk()
            ->assertJsonPath('analytics.totals.order_count', 0)
            ->assertJsonPath('analytics.totals.correction_count', 1)
            ->assertJsonPath('analytics.totals.hpp_amount', -60_000)
            ->assertJsonPath('analytics.totals.escrow_amount', -150_000)
            ->assertJsonPath('analytics.totals.profit_amount', -90_000);
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

    private function attachHppItem(Order $order, float $hpp): void
    {
        $product = Product::create([
            'store_id' => $order->store_id,
            'platform' => $order->platform,
            'product_id' => 'product-'.$order->order_sn,
            'product_name' => 'Produk '.$order->order_sn,
            'product_sku' => 'SKU-'.$order->order_sn,
            'stock' => 10,
            'price' => $order->order_selling_price,
        ]);
        $variant = VariantProduct::create([
            'product_id' => $product->id,
            'model_id' => 'variant-'.$order->order_sn,
            'model_name' => 'Default',
            'model_sku' => 'SKU-'.$order->order_sn,
            'stock' => 10,
            'price' => $order->order_selling_price,
            'hpp' => $hpp,
        ]);

        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 1,
            'price' => $order->order_selling_price,
        ]));
    }

    private function createOrder(
        Store $store,
        string $orderSn,
        string $status,
        float $escrowAmount,
        ?float $adjustedEscrowAmount = null,
    ): Order {
        return Order::withoutEvents(function () use ($store, $orderSn, $status, $escrowAmount, $adjustedEscrowAmount) {
            return Order::create([
                'store_id' => $store->id,
                'platform' => $store->platform,
                'order_sn' => $orderSn,
                'order_status' => $status,
                'order_time' => now(),
                'order_selling_price' => $escrowAmount,
                'escrow_amount' => $escrowAmount,
                'escrow_amount_after_adjustment' => $adjustedEscrowAmount,
                'raw_data' => [],
            ]);
        });
    }
}

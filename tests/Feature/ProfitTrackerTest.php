<?php

namespace Tests\Feature;

use App\Models\MarketplaceAdSpend;
use App\Models\Order;
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

    public function test_profit_analytics_supports_seven_and_fourteen_day_presets(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee Preset', 'shop-preset');

        $recentOrder = $this->createOrder($store, 'RECENT-ORDER', 'COMPLETED', 80_000);
        $recentOrder->forceFill(['order_time' => now()->subDays(6)])->saveQuietly();

        $olderOrder = $this->createOrder($store, 'OLDER-ORDER', 'COMPLETED', 120_000);
        $olderOrder->forceFill(['order_time' => now()->subDays(10)])->saveQuietly();

        $this->actingAs($user)
            ->getJson('/api/profit-tracker?analytics_period=last_7_days')
            ->assertOk()
            ->assertJsonPath('analytics.totals.order_count', 1)
            ->assertJsonPath('analytics.totals.escrow_amount', 80_000);

        $this->actingAs($user)
            ->getJson('/api/profit-tracker?analytics_period=last_14_days')
            ->assertOk()
            ->assertJsonPath('analytics.totals.order_count', 2)
            ->assertJsonPath('analytics.totals.escrow_amount', 200_000);
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

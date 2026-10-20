<?php

namespace Tests\Feature;

use App\Models\MarketplaceAdSpend;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantProduct;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_profit_tracker_calculation_for_today_and_profit_trend(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 20, 12, 0, 0, 'Asia/Jakarta'));

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Shopee Dashboard',
            'shopee_shop_id' => 'dashboard-shop',
            'platform_shop_id' => 'dashboard-shop',
            'shop_expired_at' => now()->addMonth(),
            'refresh_token' => 'refresh-token',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 'dashboard-product',
            'product_name' => 'Produk Dashboard',
            'product_sku' => 'DASHBOARD-SKU',
            'stock' => 10,
            'price' => 120_000,
        ]);
        $variant = VariantProduct::create([
            'product_id' => $product->id,
            'model_id' => 'dashboard-variant',
            'model_name' => 'Hitam',
            'model_sku' => 'DASHBOARD-SKU',
            'stock' => 10,
            'price' => 120_000,
            'hpp' => 40_000,
        ]);
        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'order_sn' => 'DASHBOARD-TODAY',
            'order_status' => 'READY_TO_SHIP',
            'order_time' => now(),
            'order_selling_price' => 120_000,
            'escrow_amount' => 100_000,
            'raw_data' => [],
        ]));
        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => $variant->model_name,
            'platform_variant_id' => $variant->model_id,
            'sku' => $variant->model_sku,
            'quantity_purchased' => 1,
            'price' => 120_000,
        ]));
        Order::withoutEvents(fn () => Order::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'order_sn' => 'DASHBOARD-CANCELLED',
            'order_status' => 'CANCELLED',
            'order_time' => now(),
            'order_selling_price' => 500_000,
            'escrow_amount' => 0,
            'raw_data' => [],
        ]));
        MarketplaceAdSpend::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'spend_date' => now('Asia/Jakarta')->toDateString(),
            'amount' => 10_000,
            'currency' => 'IDR',
            'synced_at' => now(),
        ]);

        $dashboardResponse = $this->actingAs($user)->getJson('/dashboard/stats?days=7');
        $profitResponse = $this->actingAs($user)->getJson('/api/profit-tracker?analytics_period=today');

        $dashboardResponse
            ->assertOk()
            ->assertJsonPath('perlu_dikirim_count', 1)
            ->assertJsonPath('gmv_today', 120_000)
            ->assertJsonPath('profit_today', 50_000)
            ->assertJsonCount(7, 'profit_trend')
            ->assertJsonPath('profit_trend.6.profit_amount', 50_000);

        $profitResponse
            ->assertOk()
            ->assertJsonPath('analytics.totals.profit_amount', 50_000);

        $this->assertSame(
            (float) $profitResponse->json('analytics.totals.profit_amount'),
            (float) $dashboardResponse->json('profit_today'),
        );
    }
}

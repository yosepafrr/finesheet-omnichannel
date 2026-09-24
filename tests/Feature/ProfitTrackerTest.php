<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
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
            ->assertJsonPath('stores.0.id', $tiktok->id)
            ->assertJsonPath('stores.0.escrow', 400_000)
            ->assertJsonPath('stores.0.status_counts.perlu_dikirim', 1)
            ->assertJsonPath('stores.1.id', $shopee->id)
            ->assertJsonPath('stores.1.escrow', 300_000)
            ->assertJsonPath('stores.1.status_counts.perlu_dikirim', 1)
            ->assertJsonPath('stores.1.status_counts.dikirim', 1)
            ->assertJsonPath('stores.1.status_counts.return_cancel', 1);

        $storeTotal = collect($response->json('stores'))->sum('escrow');
        $this->assertSame((float) $response->json('total_escrow_amount'), (float) $storeTotal);
    }

    public function test_return_cancel_filter_and_order_list_use_the_canonical_escrow_amount(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user, 'Shopee', 'Shopee Utama', 'shop-1');

        $this->createOrder($store, 'SHOPEE-READY', 'READY_TO_SHIP', 100_000, 80_000);
        $this->createOrder($store, 'SHOPEE-CANCELLED', 'CANCELLED', 300_000);

        $profitResponse = $this->actingAs($user)->getJson(
            '/api/profit-tracker?include_perlu_dikirim=1&include_dikirim=0&include_return=1',
        );

        $profitResponse
            ->assertOk()
            ->assertJsonPath('total_escrow_amount', 400_000)
            ->assertJsonPath('stores.0.included_order_count', 2);

        $ordersResponse = $this->actingAs($user)->getJson(
            "/api/orders?store_id={$store->id}&statuses=READY_TO_SHIP",
        );

        $ordersResponse
            ->assertOk()
            ->assertJsonPath('totals.escrow_amount', 100_000)
            ->assertJsonPath('orders.0.escrow_amount', 100_000);
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
    ): void {
        Order::withoutEvents(function () use ($store, $orderSn, $status, $escrowAmount, $adjustedEscrowAmount) {
            Order::create([
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

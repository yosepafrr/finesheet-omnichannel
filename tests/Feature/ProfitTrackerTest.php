<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
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
            ->assertJsonPath('stores.1.status_counts.return_cancel', 0);

        $storeTotal = collect($response->json('stores'))->sum('escrow');
        $this->assertSame((float) $response->json('total_escrow_amount'), (float) $storeTotal);
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

<?php

namespace Tests\Feature;

use App\Models\MarketplaceAdSpend;
use App\Models\Store;
use App\Models\User;
use App\Services\ShopeeAdsSpendSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeAdsSpendSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shopee.partner_id', '123456');
        config()->set('services.shopee.partner_key', 'test-secret');
        config()->set('shopee.base_url', 'https://partner.test');
    }

    public function test_it_persists_the_sum_of_hourly_shopee_ads_expense(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => '',
                'response' => [
                    ['hour' => 10, 'expense' => 12_500.5],
                    ['hour' => 11, 'expense' => 7_500],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Shopee Ads',
            'shopee_shop_id' => '987654',
            'platform_shop_id' => '987654',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expired_at' => now()->addHours(2),
        ]);

        $spend = app(ShopeeAdsSpendSyncService::class)->syncDay(
            $store,
            now('Asia/Jakarta')->setDate(2026, 10, 18),
        );

        $this->assertSame(20_000.5, (float) $spend->amount);
        $this->assertDatabaseHas('marketplace_ad_spends', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'spend_date' => '2026-10-18',
            'amount' => 20_000.5,
        ]);
    }

    public function test_a_successful_empty_response_is_stored_as_zero_spend(): void
    {
        Http::fake(['*' => Http::response(['error' => '', 'response' => []])]);

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Shopee No Spend',
            'shopee_shop_id' => '987655',
            'platform_shop_id' => '987655',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expired_at' => now()->addHours(2),
        ]);

        app(ShopeeAdsSpendSyncService::class)->syncDay(
            $store,
            now('Asia/Jakarta')->setDate(2026, 10, 18),
        );

        $this->assertDatabaseHas('marketplace_ad_spends', [
            'store_id' => $store->id,
            'spend_date' => '2026-10-18',
            'amount' => 0,
        ]);
        $this->assertSame(1, MarketplaceAdSpend::query()->count());
    }
}

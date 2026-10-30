<?php

namespace Tests\Feature;

use App\Jobs\SyncTiktokAdsSpendJob;
use App\Models\MarketplaceAdSpend;
use App\Models\Store;
use App\Models\TiktokAdAccount;
use App\Models\TiktokAdsConnection;
use App\Models\User;
use App\Services\TiktokAdsSpendSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TiktokAdsSpendSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.tiktok_ads', [
            'app_id' => '123456789',
            'app_secret' => 'secret',
            'authorization_url' => 'https://business-api.tiktok.com/portal/auth',
            'redirect_uri' => 'https://finesheet.test/tiktok-ads/callback',
            'api_url' => 'https://business-api.tiktok.com/open_api',
            'api_version' => 'v2.0',
        ]);
    }

    public function test_it_syncs_advertiser_daily_spend_and_campaign_breakdown(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/advertiser/info/')) {
                return Http::response([
                    'code' => 0,
                    'message' => 'OK',
                    'data' => ['list' => [[
                        'advertiser_id' => '7001',
                        'name' => 'Vilion Ads',
                        'currency' => 'IDR',
                        'timezone' => 'Asia/Jakarta',
                    ]]],
                ]);
            }

            if (str_contains($request->url(), '/gmv_max/report/get/')) {
                return Http::response([
                    'code' => 0,
                    'message' => 'OK',
                    'data' => [
                        'list' => [[
                            'dimensions' => ['campaign_id' => 'gmv-1', 'stat_time_day' => '2026-10-29'],
                            'metrics' => [
                                'campaign_name' => 'GMV Max Toko',
                                'cost' => '25000',
                            ],
                        ]],
                        'page_info' => ['total_page' => 1],
                    ],
                ]);
            }

            $dimensions = json_decode((string) $request['dimensions'], true);
            if (! in_array('campaign_id', $dimensions, true)) {
                return Http::response([
                    'code' => 0,
                    'message' => 'OK',
                    'data' => [
                        'list' => [
                            ['dimensions' => ['stat_time_day' => '2026-10-28'], 'metrics' => ['spend' => '120000.50']],
                            ['dimensions' => ['stat_time_day' => '2026-10-29'], 'metrics' => ['spend' => '80000']],
                        ],
                        'page_info' => ['total_page' => 1],
                    ],
                ]);
            }

            return Http::response([
                'code' => 0,
                'message' => 'OK',
                'data' => [
                    'list' => [
                        [
                            'dimensions' => ['campaign_id' => 'campaign-1', 'stat_time_day' => '2026-10-28'],
                            'metrics' => ['campaign_name' => 'GMV Oktober', 'spend' => '70000', 'impressions' => '1000', 'clicks' => '70'],
                        ],
                        [
                            'dimensions' => ['campaign_id' => 'campaign-2', 'stat_time_day' => '2026-10-28'],
                            'metrics' => ['campaign_name' => 'Retargeting', 'spend' => '50000.50', 'impressions' => '800', 'clicks' => '40'],
                        ],
                        [
                            'dimensions' => ['campaign_id' => 'campaign-1', 'stat_time_day' => '2026-10-29'],
                            'metrics' => ['campaign_name' => 'GMV Oktober', 'spend' => '80000', 'impressions' => '1200', 'clicks' => '85'],
                        ],
                    ],
                    'page_info' => ['total_page' => 1],
                ],
            ]);
        });

        [$user, $store, $account] = $this->connectedAccount();
        $summary = app(TiktokAdsSpendSyncService::class)->sync(
            $account,
            Carbon::parse('2026-10-28', 'Asia/Jakarta'),
            Carbon::parse('2026-10-29', 'Asia/Jakarta'),
        );

        $this->assertSame(2, $summary['days']);
        $this->assertSame(4, $summary['campaigns']);
        $this->assertSame(225000.5, $summary['amount']);
        $this->assertDatabaseHas('marketplace_ad_spends', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'platform' => 'Tiktokshop',
            'amount' => 120000.5,
        ]);
        $this->assertSame(
            '2026-10-28',
            MarketplaceAdSpend::query()->where('amount', 120000.5)->firstOrFail()->spend_date->toDateString(),
        );
        $this->assertDatabaseHas('tiktok_ad_campaign_spends', [
            'tiktok_ad_account_id' => $account->id,
            'campaign_id' => 'campaign-2',
            'campaign_name' => 'Retargeting',
            'amount' => 50000.5,
            'impressions' => 800,
            'clicks' => 40,
        ]);
        $this->assertDatabaseHas('tiktok_ad_campaign_spends', [
            'tiktok_ad_account_id' => $account->id,
            'campaign_id' => 'gmv-1',
            'campaign_name' => 'GMV Max Toko',
            'amount' => 25000,
            'impressions' => 0,
            'clicks' => 0,
        ]);

        $account->refresh();
        $this->assertSame('Vilion Ads', $account->advertiser_name);
        $this->assertSame('IDR', $account->currency);
        $this->assertNotNull($account->last_synced_at);
        $this->assertSame(2, MarketplaceAdSpend::query()->count());

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/report/integrated/get/')) {
                return false;
            }

            $data = $request->data();

            return json_decode((string) $data['advertiser_ids'], true) === ['7001']
                && ! array_key_exists('advertiser_id', $data)
                && ! array_key_exists('service_type', $data)
                && ! array_key_exists('report_type', $data)
                && ! array_key_exists('data_level', $data);
        });
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/open_api/v1.3/gmv_max/report/get/')) {
                return false;
            }

            $data = $request->data();

            return $data['advertiser_id'] === '7001'
                && json_decode((string) $data['store_ids'], true) === ['tiktok-store-id-1']
                && json_decode((string) $data['dimensions'], true) === ['campaign_id', 'stat_time_day']
                && json_decode((string) $data['metrics'], true) === ['campaign_name', 'cost']
                && ! array_key_exists('advertiser_ids', $data);
        });
    }

    public function test_manual_sync_queues_mapped_accounts_and_campaign_endpoint_aggregates_data(): void
    {
        Queue::fake();
        [$user, $store, $account] = $this->connectedAccount();

        $spend = MarketplaceAdSpend::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'platform' => 'Tiktokshop',
            'spend_date' => '2026-10-29',
            'amount' => 120000,
            'currency' => 'IDR',
            'synced_at' => now(),
        ]);
        $spend->campaigns()->create([
            'tiktok_ad_account_id' => $account->id,
            'campaign_id' => 'campaign-1',
            'campaign_name' => 'Produk Utama',
            'amount' => 120000,
            'impressions' => 500,
            'clicks' => 25,
        ]);

        $this->actingAs($user)
            ->postJson('/api/tiktok-ads/sync', ['days' => 30])
            ->assertAccepted()
            ->assertJsonPath('queued_accounts', 1);
        Queue::assertPushed(SyncTiktokAdsSpendJob::class, 1);

        $this->actingAs($user)
            ->postJson('/api/tiktok-ads/sync', ['days' => 7, 'account_id' => $account->id])
            ->assertAccepted()
            ->assertJsonPath('queued_accounts', 1)
            ->assertJsonPath('account_ids.0', $account->id);
        Queue::assertPushed(SyncTiktokAdsSpendJob::class, 2);

        $this->actingAs($user)
            ->getJson('/api/tiktok-ads/campaigns?start=2026-10-01&end=2026-10-31')
            ->assertOk()
            ->assertJsonPath('campaigns.0.campaign_id', 'campaign-1')
            ->assertJsonPath('campaigns.0.campaign_name', 'Produk Utama')
            ->assertJsonPath('campaigns.0.amount', '120000.00');
    }

    public function test_user_can_remove_an_unused_advertiser_account_without_revoking_connection(): void
    {
        [$user, , $account] = $this->connectedAccount();

        $this->actingAs($user)
            ->deleteJson("/api/tiktok-ads/accounts/{$account->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Advertiser account dihapus dari Finesheet. Otorisasi di TikTok tidak dicabut.');

        $account->refresh();
        $this->assertFalse($account->is_active);
        $this->assertNull($account->store_id);
        $this->assertDatabaseCount('tiktok_ads_connections', 1);
    }

    /** @return array{User, Store, TiktokAdAccount} */
    private function connectedAccount(): array
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Tiktokshop',
            'store_name' => 'Vilion TikTok',
            'shopee_shop_id' => 'tiktok-store-1',
            'platform_shop_id' => 'tiktok-store-id-1',
        ]);
        $connection = TiktokAdsConnection::create([
            'user_id' => $user->id,
            'access_token' => 'encrypted-access-token',
            'advertiser_ids' => ['7001'],
            'scopes' => ['reporting'],
            'status' => 'active',
        ]);
        $account = TiktokAdAccount::create([
            'tiktok_ads_connection_id' => $connection->id,
            'user_id' => $user->id,
            'store_id' => $store->id,
            'advertiser_id' => '7001',
            'is_active' => true,
        ]);

        return [$user, $store, $account];
    }
}

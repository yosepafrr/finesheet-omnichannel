<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\TiktokAdAccount;
use App\Models\TiktokAdsConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TiktokAdsOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.tiktok_ads', [
            'app_id' => '123456789',
            'app_secret' => 'marketing-api-secret',
            'authorization_url' => 'https://business-api.tiktok.com/portal/auth',
            'redirect_uri' => 'https://finesheet.test/tiktok-ads/callback',
            'api_url' => 'https://business-api.tiktok.com/open_api',
            'api_version' => 'v2.0',
        ]);
    }

    public function test_connect_redirect_adds_a_single_use_state_to_the_generated_authorization_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/connect/tiktok-ads');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://business-api.tiktok.com/portal/auth?', $location);
        $this->assertSame('123456789', $query['app_id'] ?? null);
        $this->assertSame('https://finesheet.test/tiktok-ads/callback', $query['redirect_uri'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);
        $this->assertSame(
            hash('sha256', (string) $query['state']),
            session('tiktok_ads.oauth.state_hash'),
        );
        $this->assertGreaterThan(now()->timestamp, session('tiktok_ads.oauth.expires_at'));
    }

    public function test_callback_exchanges_the_code_encrypts_the_token_and_maps_an_unambiguous_store(): void
    {
        Http::fake([
            'https://business-api.tiktok.com/open_api/v2.0/oauth2/access_token/' => Http::response([
                'code' => 0,
                'message' => 'OK',
                'data' => [
                    'access_token' => 'long-lived-advertiser-token',
                    'advertiser_ids' => ['700000000000000001'],
                    'scope' => ['reporting'],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Tiktokshop',
            'store_name' => 'TikTok Shop Utama',
            'shopee_shop_id' => 'shop-cipher',
        ]);
        $state = 'valid-oauth-state';

        $response = $this->actingAs($user)
            ->withSession([
                'tiktok_ads.oauth' => [
                    'state_hash' => hash('sha256', $state),
                    'expires_at' => now()->addMinutes(5)->timestamp,
                ],
            ])
            ->get('/tiktok-ads/callback?'.http_build_query([
                'auth_code' => 'one-time-code',
                'state' => $state,
            ]));

        $response->assertRedirect('/#/profit-tracker');

        $connection = TiktokAdsConnection::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('long-lived-advertiser-token', $connection->access_token);
        $this->assertNotSame(
            'long-lived-advertiser-token',
            DB::table('tiktok_ads_connections')->where('id', $connection->id)->value('access_token'),
        );

        $this->assertDatabaseHas('tiktok_ad_accounts', [
            'user_id' => $user->id,
            'tiktok_ads_connection_id' => $connection->id,
            'store_id' => $store->id,
            'advertiser_id' => '700000000000000001',
            'is_active' => true,
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://business-api.tiktok.com/open_api/v2.0/oauth2/access_token/'
            && $request['app_id'] === '123456789'
            && $request['secret'] === 'marketing-api-secret'
            && $request['auth_code'] === 'one-time-code');
    }

    public function test_callback_rejects_an_invalid_state_without_calling_tiktok(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession([
                'tiktok_ads.oauth' => [
                    'state_hash' => hash('sha256', 'expected-state'),
                    'expires_at' => now()->addMinutes(5)->timestamp,
                ],
            ])
            ->get('/tiktok-ads/callback?auth_code=code&state=forged-state');

        $response->assertRedirect('/#/profit-tracker');
        $this->assertDatabaseCount('tiktok_ads_connections', 0);
        Http::assertNothingSent();
    }

    public function test_authorizing_another_login_keeps_previous_connections_and_advertisers(): void
    {
        Http::fake([
            'https://business-api.tiktok.com/open_api/v2.0/oauth2/access_token/' => Http::sequence()
                ->push([
                    'code' => 0,
                    'message' => 'OK',
                    'data' => [
                        'access_token' => 'first-login-token',
                        'advertiser_ids' => ['7001', '7002'],
                        'scope' => ['reporting'],
                    ],
                ])
                ->push([
                    'code' => 0,
                    'message' => 'OK',
                    'data' => [
                        'access_token' => 'second-login-token',
                        'advertiser_ids' => ['8001'],
                        'scope' => ['reporting'],
                    ],
                ]),
        ]);

        $user = User::factory()->create();

        foreach ([
            ['state' => 'first-state', 'code' => 'first-code'],
            ['state' => 'second-state', 'code' => 'second-code'],
        ] as $authorization) {
            $this->actingAs($user)
                ->withSession([
                    'tiktok_ads.oauth' => [
                        'state_hash' => hash('sha256', $authorization['state']),
                        'expires_at' => now()->addMinutes(5)->timestamp,
                    ],
                ])
                ->get('/tiktok-ads/callback?'.http_build_query([
                    'auth_code' => $authorization['code'],
                    'state' => $authorization['state'],
                ]))
                ->assertRedirect('/#/profit-tracker');
        }

        $this->assertDatabaseCount('tiktok_ads_connections', 2);
        $this->assertDatabaseCount('tiktok_ad_accounts', 3);
        $this->assertSame(
            ['7001', '7002', '8001'],
            TiktokAdAccount::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->orderBy('advertiser_id')
                ->pluck('advertiser_id')
                ->all(),
        );
        $firstConnectionId = TiktokAdAccount::query()
            ->where('advertiser_id', '7001')
            ->value('tiktok_ads_connection_id');
        $this->assertSame(
            $firstConnectionId,
            TiktokAdAccount::query()
                ->where('advertiser_id', '7002')
                ->value('tiktok_ads_connection_id'),
        );
        $this->assertNotSame(
            $firstConnectionId,
            TiktokAdAccount::query()
                ->where('advertiser_id', '8001')
                ->value('tiktok_ads_connection_id'),
        );

        $this->actingAs($user)
            ->getJson('/api/tiktok-ads/status')
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('connection_count', 2)
            ->assertJsonCount(3, 'accounts');
    }

    public function test_an_ad_account_cannot_be_mapped_to_another_users_store(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherStore = Store::create([
            'user_id' => $otherUser->id,
            'platform' => 'Tiktokshop',
            'store_name' => 'Toko Milik User Lain',
            'shopee_shop_id' => 'other-shop',
        ]);
        $connection = TiktokAdsConnection::create([
            'user_id' => $user->id,
            'access_token' => 'token',
            'advertiser_ids' => ['7001'],
            'status' => 'active',
        ]);
        $account = TiktokAdAccount::create([
            'tiktok_ads_connection_id' => $connection->id,
            'user_id' => $user->id,
            'advertiser_id' => '7001',
        ]);

        $response = $this->actingAs($user)->putJson(
            "/api/tiktok-ads/accounts/{$account->id}/store",
            ['store_id' => $otherStore->id],
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('store_id');
        $this->assertNull($account->fresh()->store_id);
    }
}

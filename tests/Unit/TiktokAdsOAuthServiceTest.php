<?php

namespace Tests\Unit;

use App\Services\TiktokAdsOAuthService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TiktokAdsOAuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.tiktok_ads', [
            'app_id' => '123456789',
            'app_secret' => 'marketing-api-secret',
            'authorization_url' => 'https://business-api.tiktok.com/portal/auth?redirect_uri=https%3A%2F%2Ffinesheet.test%2Ftiktok-ads%2Fcallback&state=old',
            'redirect_uri' => 'https://finesheet.test/tiktok-ads/callback',
            'api_url' => 'https://business-api.tiktok.com/open_api',
            'api_version' => 'v2.0',
        ]);
    }

    public function test_it_replaces_the_state_on_the_generated_authorization_url(): void
    {
        $url = app(TiktokAdsOAuthService::class)->authorizationUrl('fresh state');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('fresh state', $query['state']);
        $this->assertSame(
            'https://finesheet.test/tiktok-ads/callback',
            $query['redirect_uri'],
        );
    }

    public function test_it_exchanges_an_authorization_code_and_normalizes_accounts_and_scopes(): void
    {
        Http::fake([
            '*' => Http::response([
                'code' => 0,
                'message' => 'OK',
                'data' => [
                    'access_token' => 'advertiser-token',
                    'advertiser_ids' => ['7001', 7002, '7001'],
                    'scope' => 'reporting, advertiser.read',
                ],
            ]),
        ]);

        $result = app(TiktokAdsOAuthService::class)->exchangeAuthorizationCode('auth-code');

        $this->assertSame('advertiser-token', $result['access_token']);
        $this->assertSame(['7001', '7002'], $result['advertiser_ids']);
        $this->assertSame(['reporting', 'advertiser.read'], $result['scopes']);
    }

    public function test_it_rejects_a_successful_http_response_with_a_tiktok_api_error(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 40002,
            'message' => 'Invalid authorization code',
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid authorization code');

        app(TiktokAdsOAuthService::class)->exchangeAuthorizationCode('expired-code');
    }
}

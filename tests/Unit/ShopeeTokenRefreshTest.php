<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\ShopeeService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeTokenRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('services.shopee.partner_id', '123456');
        config()->set('services.shopee.partner_key', 'test-secret');
        config()->set('shopee.base_url', 'https://partner.test');
    }

    public function test_authorization_code_is_sent_as_a_json_request_body(): void
    {
        Http::fake([
            '*' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expire_in' => 14400,
                'shop_id' => 987654,
                'error' => '',
            ]),
        ]);

        $result = app(ShopeeService::class)->exchangeAuthorizationCode('authorization-code', 987654);

        $this->assertSame('new-access-token', $result['access_token']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->hasHeader('Content-Type', 'application/json')
                && ($body['code'] ?? null) === 'authorization-code'
                && ($body['shop_id'] ?? null) === 987654;
        });
    }

    public function test_refresh_token_is_sent_as_a_json_request_body(): void
    {
        Http::fake([
            '*' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expire_in' => 14400,
                'shop_id' => 987654,
                'partner_id' => 123456,
                'error' => '',
            ]),
        ]);

        $store = new class extends Store
        {
            public function refresh()
            {
                return $this;
            }

            public function update(array $attributes = [], array $options = [])
            {
                $this->forceFill($attributes);

                return true;
            }
        };
        $store->forceFill([
            'id' => 10,
            'shopee_shop_id' => '987654',
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
            'token_expired_at' => now()->addMinutes(10),
        ]);
        $store->exists = true;

        $result = app(ShopeeService::class)->refreshAccessToken($store);

        $this->assertTrue($result);
        $this->assertSame('new-access-token', $store->access_token);
        $this->assertSame('new-refresh-token', $store->refresh_token);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->hasHeader('Content-Type', 'application/json')
                && ($body['partner_id'] ?? null) === 123456
                && ($body['shop_id'] ?? null) === 987654
                && ($body['refresh_token'] ?? null) === 'old-refresh-token';
        });
    }
}

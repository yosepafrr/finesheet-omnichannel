<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\ShopeeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeOrderApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shopee.partner_id', '123456');
        config()->set('services.shopee.partner_key', 'test-secret');
        config()->set('shopee.base_url', 'https://partner.test');
    }

    public function test_order_detail_requests_fields_used_by_the_order_ui(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => '',
                'response' => ['order_list' => []],
            ]),
        ]);

        app(ShopeeService::class)->getOrderDetails($this->store(), ['ORDER-1']);

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $fields = explode(',', $query['response_optional_fields'] ?? '');

            return ($query['order_sn_list'] ?? null) === 'ORDER-1'
                && in_array('item_list', $fields, true)
                && in_array('recipient_address', $fields, true)
                && in_array('package_list', $fields, true)
                && in_array('shipping_carrier', $fields, true)
                && in_array('total_amount', $fields, true);
        });
    }

    public function test_order_list_follows_shopee_cursor_pagination(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (($query['cursor'] ?? '') === 'next-page') {
                return Http::response([
                    'error' => '',
                    'response' => [
                        'order_list' => [['order_sn' => 'ORDER-2', 'order_status' => 'SHIPPED']],
                        'more' => false,
                        'next_cursor' => '',
                    ],
                ]);
            }

            return Http::response([
                'error' => '',
                'response' => [
                    'order_list' => [['order_sn' => 'ORDER-1', 'order_status' => 'READY_TO_SHIP']],
                    'more' => true,
                    'next_cursor' => 'next-page',
                ],
            ]);
        });

        $result = app(ShopeeService::class)->getOrderList(
            'access-token',
            '987654',
            1_700_000_000,
            1_700_086_400
        );

        $this->assertSame(['ORDER-1', 'ORDER-2'], array_column($result['response']['order_list'], 'order_sn'));
        Http::assertSentCount(2);
    }

    public function test_return_list_sends_one_based_page_and_requested_time_range(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => '',
                'response' => [
                    'return' => [],
                    'more' => false,
                ],
            ]),
        ]);

        app(ShopeeService::class)->getReturnList(
            'access-token',
            '987654',
            1,
            100,
            1_700_000_000,
            1_700_086_400,
        );

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['page_no'] ?? null) === '1'
                && ($query['page_size'] ?? null) === '100'
                && ($query['create_time_from'] ?? null) === '1700000000'
                && ($query['create_time_to'] ?? null) === '1700086400';
        });
    }

    private function store(): Store
    {
        return new Store([
            'shopee_shop_id' => '987654',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expired_at' => now()->addHours(2),
        ]);
    }
}

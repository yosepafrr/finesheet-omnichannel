<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\TiktokService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TiktokServiceUnsettledPaginationTest extends TestCase
{
    public function test_it_follows_pages_until_the_requested_order_is_found(): void
    {
        config()->set('services.tiktok.app_key', 'test-key');
        config()->set('services.tiktok.app_secret', 'test-secret');
        config()->set('services.tiktok.api_url', 'https://open-api.test');

        Http::fakeSequence()
            ->push([
                'code' => 0,
                'data' => [
                    'next_page_token' => 'PAGE-2',
                    'total_count' => 101,
                    'sum_est_settlement_amount' => '16977387',
                    'transactions' => [
                        ['order_id' => 'NEWER-ORDER', 'est_settlement_amount' => '100000'],
                    ],
                ],
            ])
            ->push([
                'code' => 0,
                'data' => [
                    'next_page_token' => '',
                    'total_count' => 101,
                    'sum_est_settlement_amount' => '16977387',
                    'transactions' => [
                        ['order_id' => 'TARGET-ORDER', 'est_settlement_amount' => '125078'],
                        ['order_id' => 'OTHER-ORDER', 'est_settlement_amount' => '999999'],
                    ],
                ],
            ]);

        $service = new class extends TiktokService
        {
            public function ensureValidToken(Store $store)
            {
                return 'test-token';
            }
        };

        $store = new Store(['shopee_shop_id' => 'SHOP-CIPHER']);
        $result = $service->getUnsettledTransaction($store, 'TARGET-ORDER');

        $this->assertSame('TARGET-ORDER', $result['data']['transactions'][0]['order_id']);
        $this->assertSame('125078', $result['data']['transactions'][0]['est_settlement_amount']);
        $this->assertCount(1, $result['data']['transactions']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['page_size'] ?? null) === '100'
                && ($query['page_token'] ?? null) === 'PAGE-2';
        });
    }
}

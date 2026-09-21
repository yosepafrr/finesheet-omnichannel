<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductCatalogPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shopee.partner_id', '123456');
        config()->set('services.shopee.partner_key', 'shopee-secret');
        config()->set('shopee.base_url', 'https://partner.test');
        config()->set('services.tiktok.app_key', 'tiktok-key');
        config()->set('services.tiktok.app_secret', 'tiktok-secret');
        config()->set('services.tiktok.api_url', 'https://open-api.test');
    }

    public function test_shopee_item_list_follows_every_offset_page(): void
    {
        Http::fakeSequence()
            ->push([
                'error' => '',
                'response' => [
                    'item' => [['item_id' => 1]],
                    'has_next_page' => true,
                    'next_offset' => 100,
                ],
            ])
            ->push([
                'error' => '',
                'response' => [
                    'item' => [['item_id' => 2]],
                    'has_next_page' => false,
                ],
            ]);

        $service = new class extends ShopeeService
        {
            public function ensureValidToken(Store $store)
            {
                return 'access-token';
            }
        };

        $items = $service->getItemList(new Store([
            'id' => 10,
            'shopee_shop_id' => 'SHOP-1',
        ]));

        $this->assertSame([1, 2], array_column($items, 'item_id'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'offset=100'));
    }

    public function test_tiktok_product_list_sends_the_next_page_token(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['products' => []]])]);

        $service = new class extends TiktokService
        {
            public function ensureValidToken(Store $store)
            {
                return 'access-token';
            }
        };

        $service->getProductList(new Store(['shopee_shop_id' => 'SHOP-CIPHER']), 'NEXT-TOKEN');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'page_token=NEXT-TOKEN'));
    }
}

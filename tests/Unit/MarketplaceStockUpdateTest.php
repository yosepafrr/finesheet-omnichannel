<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MarketplaceStockUpdateTest extends TestCase
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

    public function test_shopee_stock_update_sends_the_model_and_stock(): void
    {
        Http::fake([
            '*get_model_list*' => Http::response([
                'error' => '',
                'response' => [
                    'model' => [[
                        'model_id' => 789,
                        'stock_info_v2' => [
                            'seller_stock' => [
                                ['location_id' => 'IDZ', 'stock' => 6],
                                ['location_id' => 'IDJ', 'stock' => 3],
                            ],
                        ],
                    ]],
                ],
            ]),
            '*update_stock*' => Http::response([
                'error' => '',
                'message' => '',
                'response' => [
                    'failure_list' => [],
                    'success_list' => [['model_id' => 789, 'normal_stock' => 12]],
                ],
            ]),
        ]);

        $service = new class extends ShopeeService
        {
            public function ensureValidToken(Store $store)
            {
                return 'access-token';
            }
        };

        $store = new Store(['shopee_shop_id' => '987654']);
        $service->updateStock($store, '456', '789', 12);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_starts_with($request->url(), 'https://partner.test/api/v2/product/update_stock?')
                && ($body['item_id'] ?? null) === 456
                && data_get($body, 'stock_list.0.model_id') === 789
                && data_get($body, 'stock_list.0.normal_stock') === 12
                && data_get($body, 'stock_list.0.seller_stock') === [
                    ['location_id' => 'IDZ', 'stock' => 8],
                    ['location_id' => 'IDJ', 'stock' => 4],
                ];
        });
    }

    public function test_shopee_stock_update_throws_when_api_reports_a_failure(): void
    {
        Http::fake([
            '*get_model_list*' => Http::response([
                'error' => '',
                'response' => [
                    'model' => [[
                        'model_id' => 789,
                        'stock_info_v2' => ['seller_stock' => []],
                    ]],
                ],
            ]),
            '*update_stock*' => Http::response([
                'error' => '',
                'message' => '',
                'response' => [
                    'failure_list' => [[
                        'model_id' => 789,
                        'failed_reason' => 'invalid model id',
                    ]],
                ],
            ]),
        ]);

        $service = new class extends ShopeeService
        {
            public function ensureValidToken(Store $store)
            {
                return 'access-token';
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid model id');

        $service->updateStock(new Store(['shopee_shop_id' => '987654']), '456', '789', 12);
    }

    public function test_tiktok_stock_update_sends_the_sku_inventory(): void
    {
        Http::fake([
            '*inventory/update*' => Http::response(['code' => 0, 'message' => 'Success']),
            '*products/PRODUCT-1*' => Http::response([
                'code' => 0,
                'data' => [
                    'skus' => [[
                        'id' => 'SKU-1',
                        'inventory' => [
                            ['warehouse_id' => 'WH-1', 'quantity' => 3],
                            ['warehouse_id' => 'WH-2', 'quantity' => 1],
                        ],
                    ]],
                ],
            ]),
        ]);

        $service = new class extends TiktokService
        {
            public function ensureValidToken(Store $store)
            {
                return 'access-token';
            }
        };

        $store = new Store(['shopee_shop_id' => 'SHOP-CIPHER']);
        $service->updateInventory($store, 'PRODUCT-1', 'SKU-1', 17);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_contains($request->url(), '/product/202309/products/PRODUCT-1/inventory/update')
                && data_get($body, 'skus.0.id') === 'SKU-1'
                && data_get($body, 'skus.0.inventory') === [
                    ['warehouse_id' => 'WH-1', 'quantity' => 13],
                    ['warehouse_id' => 'WH-2', 'quantity' => 4],
                ];
        });
    }

    public function test_tiktok_stock_update_throws_when_api_reports_an_error(): void
    {
        Http::fake([
            '*inventory/update*' => Http::response([
                'code' => 12052037,
                'message' => 'Missing warehouse IDs',
            ]),
            '*products/PRODUCT-1*' => Http::response([
                'code' => 0,
                'data' => [
                    'skus' => [[
                        'id' => 'SKU-1',
                        'inventory' => [],
                    ]],
                ],
            ]),
        ]);

        $service = new class extends TiktokService
        {
            public function ensureValidToken(Store $store)
            {
                return 'access-token';
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing warehouse IDs');

        $service->updateInventory(
            new Store(['shopee_shop_id' => 'SHOP-CIPHER']),
            'PRODUCT-1',
            'SKU-1',
            17
        );
    }
}

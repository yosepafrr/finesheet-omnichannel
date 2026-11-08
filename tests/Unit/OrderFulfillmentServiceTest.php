<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\Store;
use App\Services\OrderFulfillmentService;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrderFulfillmentServiceTest extends TestCase
{
    private OrderFulfillmentService $service;

    public static function shopeePackageStates(): array
    {
        return [
            'unsplit without thermal mode' => [false, '', false, 'NORMAL_AIR_WAYBILL'],
            'split order with thermal mode' => [true, 'PACKAGE-SHOPEE-1', true, 'THERMAL_AIR_WAYBILL'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OrderFulfillmentService(
            Mockery::mock(ShopeeService::class),
            Mockery::mock(TiktokService::class),
        );
    }

    public function test_shopee_ready_to_ship_can_be_arranged_but_not_printed(): void
    {
        $capabilities = $this->service->capabilities(new Order([
            'platform' => 'Shopee',
            'order_status' => 'READY_TO_SHIP',
        ]));

        $this->assertTrue($capabilities['can_arrange']);
        $this->assertFalse($capabilities['can_print']);
    }

    public function test_shopee_processed_order_can_be_printed_but_not_arranged_again(): void
    {
        $capabilities = $this->service->capabilities(new Order([
            'platform' => 'Shopee',
            'order_status' => 'PROCESSED',
        ]));

        $this->assertFalse($capabilities['can_arrange']);
        $this->assertTrue($capabilities['can_print']);
    }

    public function test_shipped_orders_do_not_offer_fulfillment_actions(): void
    {
        foreach ([
            ['platform' => 'Shopee', 'order_status' => 'SHIPPED'],
            ['platform' => 'Tiktokshop', 'order_status' => 'IN_TRANSIT'],
            ['platform' => 'Tiktokshop', 'order_status' => 'SHIPPED'],
        ] as $attributes) {
            $capabilities = $this->service->capabilities(new Order($attributes));

            $this->assertFalse($capabilities['can_arrange']);
            $this->assertFalse($capabilities['can_print']);
        }
    }

    public function test_tiktok_awaiting_shipment_can_be_arranged_but_not_printed(): void
    {
        $capabilities = $this->service->capabilities(new Order([
            'platform' => 'Tiktokshop',
            'order_status' => 'AWAITING_SHIPMENT',
        ]));

        $this->assertTrue($capabilities['can_arrange']);
        $this->assertFalse($capabilities['can_print']);
    }

    public function test_order_cannot_be_arranged_again_while_fulfillment_is_processing(): void
    {
        $capabilities = $this->service->capabilities(new Order([
            'platform' => 'Tiktokshop',
            'order_status' => 'AWAITING_SHIPMENT',
            'fulfillment_status' => 'processing',
        ]));

        $this->assertFalse($capabilities['can_arrange']);
        $this->assertSame('processing', $capabilities['status']);
        $this->assertSame('Pengaturan pengiriman sedang diproses.', $capabilities['arrange_reason']);
    }

    public function test_tiktok_awaiting_collection_can_be_printed_but_not_arranged_again(): void
    {
        $capabilities = $this->service->capabilities(new Order([
            'platform' => 'Tiktokshop',
            'order_status' => 'AWAITING_COLLECTION',
        ]));

        $this->assertFalse($capabilities['can_arrange']);
        $this->assertTrue($capabilities['can_print']);
    }

    public function test_tiktok_arrange_uses_the_store_handover_setting(): void
    {
        $tiktok = Mockery::mock(TiktokService::class);
        $service = new OrderFulfillmentService(
            Mockery::mock(ShopeeService::class),
            $tiktok,
        );
        $store = new Store(['fulfillment_handover_method' => 'dropoff']);
        $order = new Order([
            'platform' => 'Tiktokshop',
            'order_status' => 'AWAITING_SHIPMENT',
            'order_sn' => 'ORDER-1',
        ]);
        $order->setRelation('store', $store);
        $order->setRelation('packages', collect([
            new OrderPackage(['package_id' => 'PACKAGE-1']),
        ]));

        $tiktok->shouldReceive('shipPackage')
            ->once()
            ->with($store, 'PACKAGE-1', ['handover_method' => 'DROP_OFF'])
            ->andReturn(['code' => 0]);

        $service->arrange($order);
        $this->addToAssertionCount(1);
    }

    public function test_finesheet_label_does_not_request_a_platform_document(): void
    {
        $service = new OrderFulfillmentService(
            Mockery::mock(ShopeeService::class),
            Mockery::mock(TiktokService::class),
        );
        $store = new Store([
            'store_name' => 'Toko Label',
            'shipping_label_template' => 'finesheet',
        ]);
        $order = new Order([
            'platform' => 'Shopee',
            'order_status' => 'PROCESSED',
            'order_sn' => 'ORDER-LABEL-1',
            'raw_data' => [
                'shipping_carrier' => 'SPX Standard',
                'tracking_no' => 'TRACK-1',
                'recipient_address' => [
                    'name' => 'Penerima',
                    'phone' => '08123456789',
                    'full_address' => 'Tasikmalaya, Jawa Barat',
                ],
            ],
        ]);
        $order->setRelation('store', $store);
        $order->setRelation('packages', collect());
        $order->setRelation('orderProducts', collect());

        $label = $service->label($order);

        $this->assertSame('finesheet', $label['type']);
        $this->assertSame('TRACK-1', $label['shipping']['tracking_number']);
        $this->assertSame('Penerima', $label['shipping']['recipient_name']);
        $this->assertTrue($label['printing']['print_shipping_label']);
        $this->assertSame('a6', $label['printing']['shipping_label_size']);
    }

    public function test_tiktok_platform_label_requests_the_packing_slip_when_enabled(): void
    {
        $tiktok = Mockery::mock(TiktokService::class);
        $service = new OrderFulfillmentService(
            Mockery::mock(ShopeeService::class),
            $tiktok,
        );
        $store = new Store([
            'shipping_label_template' => 'platform',
            'print_shipping_label' => true,
            'print_packing_list' => true,
            'shipping_label_size' => 'a6',
        ]);
        $order = new Order([
            'platform' => 'Tiktokshop',
            'order_status' => 'AWAITING_COLLECTION',
            'order_sn' => 'ORDER-LABEL-2',
        ]);
        $order->setRelation('store', $store);
        $order->setRelation('packages', collect([
            new OrderPackage(['package_id' => 'PACKAGE-2']),
        ]));
        $order->setRelation('orderProducts', collect());

        $tiktok->shouldReceive('getPackageShippingDocument')
            ->once()
            ->with($store, 'PACKAGE-2', 'A6', 'SHIPPING_LABEL_AND_PACKING_SLIP')
            ->andReturn([
                'code' => 0,
                'data' => ['doc_url' => 'https://example.test/label.pdf'],
            ]);

        $label = $service->label($order);

        $this->assertSame('redirect', $label['type']);
        $this->assertSame('https://example.test/label.pdf', $label['url']);
    }

    #[DataProvider('shopeePackageStates')]
    public function test_shopee_platform_label_uses_the_document_parameters_and_tracking_number(
        bool $split,
        string $packageNumber,
        bool $thermal,
        string $expectedDocumentType
    ): void {
        $shopee = Mockery::mock(ShopeeService::class);
        $service = new OrderFulfillmentService(
            $shopee,
            Mockery::mock(TiktokService::class),
        );
        $store = new Store([
            'shipping_label_template' => 'platform',
            'shopee_thermal_printing' => $thermal,
        ]);
        $order = new Order([
            'platform' => 'Shopee',
            'order_status' => 'PROCESSED',
            'order_sn' => 'ORDER-SHOPEE-1',
            'raw_data' => ['split_up' => $split],
        ]);
        $order->setRelation('store', $store);
        $order->setRelation('packages', collect([
            new OrderPackage(['package_id' => 'PACKAGE-SHOPEE-1']),
        ]));
        $order->setRelation('orderProducts', collect());

        $baseOrder = [
            'order_sn' => 'ORDER-SHOPEE-1',
        ];
        if ($packageNumber !== '') {
            $baseOrder['package_number'] = $packageNumber;
        }
        $documentOrder = $baseOrder + [
            'shipping_document_type' => $expectedDocumentType,
        ];
        $createOrder = $documentOrder + [
            'tracking_number' => 'TRACK-SHOPEE-1',
        ];

        $shopee->shouldReceive('getTrackingNumber')
            ->once()
            ->with($store, 'ORDER-SHOPEE-1', $packageNumber)
            ->andReturn(['error' => '', 'response' => ['tracking_number' => 'TRACK-SHOPEE-1']]);
        $shopee->shouldReceive('getShippingDocumentParameter')
            ->once()
            ->with($store, [$baseOrder])
            ->andReturn([
                'error' => '',
                'response' => ['result_list' => [[
                    'suggest_shipping_document_type' => 'NORMAL_AIR_WAYBILL',
                    'selectable_shipping_document_type' => [
                        'NORMAL_AIR_WAYBILL',
                        'THERMAL_AIR_WAYBILL',
                    ],
                ]]],
            ]);
        $shopee->shouldReceive('createShippingDocument')
            ->once()
            ->with($store, [$createOrder])
            ->andReturn(['error' => '']);
        $shopee->shouldReceive('getShippingDocumentResult')
            ->once()
            ->with($store, [$documentOrder])
            ->andReturn([
                'error' => '',
                'response' => ['result_list' => [['status' => 'READY']]],
            ]);
        $shopee->shouldReceive('downloadShippingDocument')
            ->once()
            ->with($store, [$documentOrder])
            ->andReturn(new Response(new Psr7Response(200, [
                'Content-Type' => 'application/pdf',
            ], 'PDF-CONTENT')));

        $label = $service->label($order);

        $this->assertSame('binary', $label['type']);
        $this->assertSame('PDF-CONTENT', $label['body']);
        $this->assertSame('application/pdf', $label['content_type']);
    }

    public function test_shopee_platform_label_surfaces_the_per_order_failure(): void
    {
        $shopee = Mockery::mock(ShopeeService::class);
        $service = new OrderFulfillmentService(
            $shopee,
            Mockery::mock(TiktokService::class),
        );
        $store = new Store(['shipping_label_template' => 'platform']);
        $order = new Order([
            'platform' => 'Shopee',
            'order_status' => 'PROCESSED',
            'order_sn' => 'ORDER-SHOPEE-FAILED',
            'raw_data' => ['split_up' => false],
        ]);
        $order->setRelation('store', $store);
        $order->setRelation('packages', collect());
        $order->setRelation('orderProducts', collect());

        $baseOrder = ['order_sn' => 'ORDER-SHOPEE-FAILED'];
        $documentOrder = $baseOrder + ['shipping_document_type' => 'NORMAL_AIR_WAYBILL'];
        $createOrder = $documentOrder + ['tracking_number' => 'TRACK-FAILED'];

        $shopee->shouldReceive('getTrackingNumber')
            ->once()
            ->andReturn(['error' => '', 'response' => ['tracking_number' => 'TRACK-FAILED']]);
        $shopee->shouldReceive('getShippingDocumentParameter')
            ->once()
            ->with($store, [$baseOrder])
            ->andReturn([
                'error' => '',
                'response' => ['result_list' => [[
                    'suggest_shipping_document_type' => 'NORMAL_AIR_WAYBILL',
                ]]],
            ]);
        $shopee->shouldReceive('createShippingDocument')
            ->once()
            ->with($store, [$createOrder])
            ->andReturn([
                'error' => 'common.batch_api_all_failed',
                'message' => 'All failed, please check result_list for detail',
                'request_id' => 'REQUEST-1',
                'response' => ['result_list' => [[
                    'fail_error' => 'logistics.invalid_shipping_document_type',
                    'fail_message' => 'Tipe dokumen tidak didukung oleh channel logistik ini.',
                ]]],
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tipe dokumen tidak didukung oleh channel logistik ini. [logistics.invalid_shipping_document_type] (request REQUEST-1)');

        $service->label($order);
    }
}

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
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;
use Tests\TestCase;

class OrderFulfillmentServiceTest extends TestCase
{
    private OrderFulfillmentService $service;

    public static function shopeePackageStates(): array
    {
        return [
            'unsplit job label without thermal mode' => [false, '', false, 'NORMAL_JOB_AIR_WAYBILL', 'THERMAL_JOB_AIR_WAYBILL'],
            'split standard label with thermal mode' => [true, 'PACKAGE-SHOPEE-1', true, 'THERMAL_AIR_WAYBILL', 'NORMAL_AIR_WAYBILL'],
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
        string $expectedDocumentType,
        string $suggestedDocumentType
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
                    'suggest_shipping_document_type' => $suggestedDocumentType,
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
        $this->assertSame($expectedDocumentType, $label['document_type']);
        $this->assertSame(
            'resi-shopee-'.($thermal ? 'thermal' : 'normal').'-ORDER-SHOPEE-1.pdf',
            $label['filename'],
        );
    }

    public function test_shopee_platform_label_surfaces_the_per_order_failure(): void
    {
        $shopee = Mockery::mock(ShopeeService::class);
        $service = new OrderFulfillmentService(
            $shopee,
            Mockery::mock(TiktokService::class),
        );
        $store = new Store([
            'shipping_label_template' => 'platform',
            'shopee_thermal_printing' => true,
        ]);
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
        $thermalCreateOrder = $baseOrder + [
            'shipping_document_type' => 'THERMAL_AIR_WAYBILL',
            'tracking_number' => 'TRACK-FAILED',
        ];

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
            ->with($store, [$thermalCreateOrder])
            ->ordered()
            ->andReturn([
                'error' => 'common.batch_api_all_failed',
                'message' => 'All failed, please check result_list for detail',
                'response' => ['result_list' => [[
                    'fail_error' => 'logistics.invalid_shipping_document_type',
                    'fail_message' => 'Tipe dokumen thermal tidak didukung.',
                ]]],
            ]);
        $shopee->shouldReceive('createShippingDocument')
            ->once()
            ->with($store, [$createOrder])
            ->ordered()
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

    public function test_shopee_bulk_labels_are_downloaded_as_one_document(): void
    {
        $shopee = Mockery::mock(ShopeeService::class);
        $service = new OrderFulfillmentService(
            $shopee,
            Mockery::mock(TiktokService::class),
        );
        $store = new Store([
            'shipping_label_template' => 'platform',
            'shopee_thermal_printing' => false,
        ]);
        $store->id = 10;

        $orders = collect(['ORDER-BULK-1', 'ORDER-BULK-2'])->map(function (string $orderSn) use ($store) {
            $order = new Order([
                'store_id' => $store->id,
                'platform' => 'Shopee',
                'order_status' => 'PROCESSED',
                'order_sn' => $orderSn,
                'raw_data' => ['split_up' => false],
            ]);
            $order->setRelation('store', $store);
            $order->setRelation('packages', collect());
            $order->setRelation('orderProducts', collect());

            return $order;
        });

        $baseOrders = [
            ['order_sn' => 'ORDER-BULK-1'],
            ['order_sn' => 'ORDER-BULK-2'],
        ];
        $documentOrders = [
            ['order_sn' => 'ORDER-BULK-1', 'shipping_document_type' => 'NORMAL_AIR_WAYBILL'],
            ['order_sn' => 'ORDER-BULK-2', 'shipping_document_type' => 'NORMAL_AIR_WAYBILL'],
        ];
        $createOrders = [
            $documentOrders[0] + ['tracking_number' => 'TRACK-BULK-1'],
            $documentOrders[1] + ['tracking_number' => 'TRACK-BULK-2'],
        ];

        $shopee->shouldReceive('getTrackingNumber')
            ->once()
            ->with($store, 'ORDER-BULK-1', '')
            ->andReturn(['error' => '', 'response' => ['tracking_number' => 'TRACK-BULK-1']]);
        $shopee->shouldReceive('getTrackingNumber')
            ->once()
            ->with($store, 'ORDER-BULK-2', '')
            ->andReturn(['error' => '', 'response' => ['tracking_number' => 'TRACK-BULK-2']]);
        $shopee->shouldReceive('getShippingDocumentParameter')
            ->once()
            ->with($store, $baseOrders)
            ->andReturn([
                'error' => '',
                'response' => ['result_list' => [
                    ['order_sn' => 'ORDER-BULK-1', 'suggest_shipping_document_type' => 'NORMAL_AIR_WAYBILL'],
                    ['order_sn' => 'ORDER-BULK-2', 'suggest_shipping_document_type' => 'NORMAL_AIR_WAYBILL'],
                ]],
            ]);
        $shopee->shouldReceive('createShippingDocument')
            ->once()
            ->with($store, $createOrders)
            ->andReturn(['error' => '']);
        $shopee->shouldReceive('getShippingDocumentResult')
            ->once()
            ->with($store, $documentOrders)
            ->andReturn([
                'error' => '',
                'response' => ['result_list' => [
                    ['order_sn' => 'ORDER-BULK-1', 'status' => 'READY'],
                    ['order_sn' => 'ORDER-BULK-2', 'status' => 'READY'],
                ]],
            ]);
        $shopee->shouldReceive('downloadShippingDocument')
            ->once()
            ->with($store, $documentOrders)
            ->andReturn(new Response(new Psr7Response(200, [
                'Content-Type' => 'application/pdf',
            ], 'PDF-BULK-CONTENT')));

        $label = $service->labels($orders);

        $this->assertSame('binary', $label['type']);
        $this->assertSame('PDF-BULK-CONTENT', $label['body']);
        $this->assertSame('resi-shopee-2-pesanan.pdf', $label['filename']);
    }

    public function test_shopee_bulk_labels_from_multiple_stores_are_merged_into_one_pdf(): void
    {
        $shopee = Mockery::mock(ShopeeService::class);
        $service = new OrderFulfillmentService(
            $shopee,
            Mockery::mock(TiktokService::class),
        );
        $stores = collect([
            new Store(['store_name' => 'Toko Pertama', 'shipping_label_template' => 'platform']),
            new Store(['store_name' => 'Toko Kedua', 'shipping_label_template' => 'platform']),
        ]);
        $stores[0]->id = 21;
        $stores[1]->id = 22;

        $orders = $stores->values()->map(function (Store $store, int $index) {
            $number = $index + 1;
            $order = new Order([
                'store_id' => $store->id,
                'platform' => 'Shopee',
                'order_status' => 'PROCESSED',
                'order_sn' => "ORDER-MULTI-{$number}",
                'raw_data' => ['split_up' => false],
            ]);
            $order->setRelation('store', $store);
            $order->setRelation('packages', collect());
            $order->setRelation('orderProducts', collect());

            return $order;
        });

        foreach ($orders as $index => $order) {
            $number = $index + 1;
            $store = $order->store;
            $baseOrder = ['order_sn' => "ORDER-MULTI-{$number}"];
            $documentOrder = $baseOrder + ['shipping_document_type' => 'NORMAL_AIR_WAYBILL'];
            $createOrder = $documentOrder + ['tracking_number' => "TRACK-MULTI-{$number}"];
            $sourcePdf = $this->makeTestPdf(
                "LABEL STORE {$number}",
                $index === 0 ? [105, 148] : [210, 297],
            );

            $shopee->shouldReceive('getTrackingNumber')
                ->once()
                ->with($store, "ORDER-MULTI-{$number}", '')
                ->andReturn(['error' => '', 'response' => ['tracking_number' => "TRACK-MULTI-{$number}"]]);
            $shopee->shouldReceive('getShippingDocumentParameter')
                ->once()
                ->with($store, [$baseOrder])
                ->andReturn([
                    'error' => '',
                    'response' => ['result_list' => [[
                        'order_sn' => "ORDER-MULTI-{$number}",
                        'suggest_shipping_document_type' => 'NORMAL_AIR_WAYBILL',
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
                    'response' => ['result_list' => [[
                        'order_sn' => "ORDER-MULTI-{$number}",
                        'status' => 'READY',
                    ]]],
                ]);
            $shopee->shouldReceive('downloadShippingDocument')
                ->once()
                ->with($store, [$documentOrder])
                ->andReturn(new Response(new Psr7Response(200, [
                    'Content-Type' => 'application/pdf',
                ], $sourcePdf)));
        }

        $label = $service->labels($orders);

        $this->assertSame('application/pdf', $label['content_type']);
        $this->assertSame('resi-massal-2-toko-2-pesanan.pdf', $label['filename']);

        $mergedPdf = new Fpdi;
        $pageCount = $mergedPdf->setSourceFile(StreamReader::createByString($label['body']));
        $this->assertSame(2, $pageCount);

        $firstPage = $mergedPdf->getTemplateSize($mergedPdf->importPage(1));
        $secondPage = $mergedPdf->getTemplateSize($mergedPdf->importPage(2));
        $this->assertEqualsWithDelta(105, $firstPage['width'], 0.5);
        $this->assertEqualsWithDelta(148, $firstPage['height'], 0.5);
        $this->assertEqualsWithDelta(210, $secondPage['width'], 0.5);
        $this->assertEqualsWithDelta(297, $secondPage['height'], 0.5);
    }

    private function makeTestPdf(string $label, array $size): string
    {
        $pdf = new \TCPDF('P', 'mm', $size, true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(5, 5, 5);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Text(10, 15, $label);

        return $pdf->Output('test-label.pdf', 'S');
    }
}

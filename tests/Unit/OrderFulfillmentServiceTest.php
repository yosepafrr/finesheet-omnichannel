<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Store;
use App\Services\OrderFulfillmentService;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use Mockery;
use Tests\TestCase;

class OrderFulfillmentServiceTest extends TestCase
{
    private OrderFulfillmentService $service;

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

    public function test_tiktok_awaiting_shipment_can_be_arranged_but_not_printed(): void
    {
        $capabilities = $this->service->capabilities(new Order([
            'platform' => 'Tiktokshop',
            'order_status' => 'AWAITING_SHIPMENT',
        ]));

        $this->assertTrue($capabilities['can_arrange']);
        $this->assertFalse($capabilities['can_print']);
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
            new \App\Models\OrderPackage(['package_id' => 'PACKAGE-1']),
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
    }
}

<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\Store;
use App\Services\OrderFulfillmentService;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ShopeeFulfillmentTest extends TestCase
{
    public static function shipmentCases(): array
    {
        return [
            'empty dropoff requirements fall back from pickup' => [
                'pickup', ['info_needed' => ['dropoff' => []], 'dropoff' => []], 'dropoff', '{}',
            ],
            'empty pickup requirements fall back from dropoff' => [
                'dropoff', ['info_needed' => ['pickup' => []], 'pickup' => []], 'pickup', '{}',
            ],
            'configured method wins when both are supported' => [
                'dropoff', ['info_needed' => ['pickup' => [], 'dropoff' => []]], 'dropoff', '{}',
            ],
            'pickup without a required time slot' => [
                'pickup', [
                    'info_needed' => ['pickup' => ['address_id']],
                    'pickup' => ['address_list' => [['address_id' => 123, 'time_slot_list' => []]]],
                ], 'pickup', '{"address_id":123}',
            ],
            'pickup with an address and a required time slot' => [
                'pickup', [
                    'info_needed' => ['pickup' => ['address_id', 'pickup_time_id']],
                    'pickup' => ['address_list' => [[
                        'address_id' => 123,
                        'time_slot_list' => [['pickup_time_id' => 'SLOT-1']],
                    ]]],
                ], 'pickup', '{"address_id":123,"pickup_time_id":"SLOT-1"}',
            ],
            'dropoff with a required branch' => [
                'pickup', [
                    'info_needed' => ['dropoff' => ['branch_id']],
                    'dropoff' => ['branch_list' => [['branch_id' => 456]]],
                ], 'dropoff', '{"branch_id":456}',
            ],
        ];
    }

    #[DataProvider('shipmentCases')]
    public function test_unsplit_orders_use_available_methods_and_required_parameters(
        string $configured, array $parameters, string $expectedMethod, string $expectedPayload,
    ): void {
        $order = $this->order($configured);
        $shopee = Mockery::mock(ShopeeService::class);
        $shopee->shouldReceive('getShippingParameter')->once()
            ->with($order->store, 'ORDER-1', '')
            ->andReturn(['error' => '', 'response' => $parameters]);
        $shopee->shouldReceive('shipOrder')->once()
            ->with($order->store, 'ORDER-1', Mockery::on(function ($payload) use ($expectedMethod, $expectedPayload) {
                return array_keys($payload) === [$expectedMethod]
                    && json_encode($payload[$expectedMethod]) === $expectedPayload;
            }), '')
            ->andReturn(['error' => '']);

        $this->service($shopee)->arrange($order);
        $this->addToAssertionCount(1);
    }

    public function test_split_order_keeps_its_package_number(): void
    {
        $order = $this->order();
        $order->raw_data = ['split_up' => true];
        $shopee = Mockery::mock(ShopeeService::class);
        $shopee->shouldReceive('getShippingParameter')->once()
            ->with($order->store, 'ORDER-1', 'PACKAGE-1')
            ->andReturn(['error' => '', 'response' => ['info_needed' => ['dropoff' => []]]]);
        $shopee->shouldReceive('shipOrder')->once()
            ->with($order->store, 'ORDER-1', Mockery::any(), 'PACKAGE-1')
            ->andReturn(['error' => '']);

        $this->service($shopee)->arrange($order);
        $this->addToAssertionCount(1);
    }

    public static function incompleteOrderStates(): array
    {
        return ['unsplit' => [false, ''], 'split' => [true, 'PACKAGE-FRESH']];
    }

    #[DataProvider('incompleteOrderStates')]
    public function test_incomplete_order_resolves_split_status_without_waiting_for_background_sync(bool $split, string $packageNumber): void
    {
        $order = $this->order();
        $order->raw_data = [];
        $shopee = Mockery::mock(ShopeeService::class);
        $shopee->shouldReceive('getOrderDetails')->once()
            ->with($order->store, ['ORDER-1'])
            ->andReturn(['error' => '', 'response' => ['order_list' => [[
                'order_sn' => 'ORDER-1', 'split_up' => $split,
                'package_list' => [['package_number' => 'PACKAGE-FRESH']],
            ]]]]);
        $shopee->shouldReceive('getShippingParameter')->once()
            ->with($order->store, 'ORDER-1', $packageNumber)
            ->andReturn(['error' => '', 'response' => ['info_needed' => ['dropoff' => []]]]);
        $shopee->shouldReceive('shipOrder')->once()
            ->with($order->store, 'ORDER-1', Mockery::any(), $packageNumber)
            ->andReturn(['error' => '']);

        $this->service($shopee)->arrange($order);
        $this->assertSame($split, $order->raw_data['split_up']);
    }

    public function test_missing_shipping_methods_do_not_trigger_a_blind_shipment(): void
    {
        $shopee = Mockery::mock(ShopeeService::class);
        $shopee->shouldReceive('getShippingParameter')->once()->andReturn([
            'error' => '', 'response' => ['info_needed' => []],
        ]);
        $shopee->shouldNotReceive('shipOrder');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Metode pengiriman tidak tersedia untuk pesanan ini.');
        $this->service($shopee)->arrange($this->order());
    }

    public function test_missing_split_metadata_is_not_guessed_when_the_detail_response_is_incomplete(): void
    {
        $order = $this->order();
        $order->raw_data = [];
        $shopee = Mockery::mock(ShopeeService::class);
        $shopee->shouldReceive('getOrderDetails')->once()->andReturn([
            'error' => '', 'response' => ['order_list' => [['order_sn' => 'ORDER-1']]],
        ]);
        $shopee->shouldNotReceive('shipOrder');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Status paket Shopee belum tersedia.');
        $this->service($shopee)->arrange($order);
    }

    public function test_pickup_still_requires_a_time_slot_when_shopee_requests_one(): void
    {
        $order = $this->order();
        $shopee = Mockery::mock(ShopeeService::class);
        $shopee->shouldReceive('getShippingParameter')->once()->andReturn([
            'error' => '', 'response' => [
                'info_needed' => ['pickup' => ['address_id', 'pickup_time_id']],
                'pickup' => ['address_list' => [['address_id' => 123]]],
            ],
        ]);
        $shopee->shouldNotReceive('shipOrder');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Alamat atau jadwal pickup Shopee tidak tersedia.');
        $this->service($shopee)->arrange($order);
    }

    public function test_platform_errors_are_not_replaced_with_an_arbitrary_shipping_method(): void
    {
        $shopee = Mockery::mock(ShopeeService::class);
        $shopee->shouldReceive('getShippingParameter')->once()->andReturn([
            'error' => 'error_auth', 'message' => 'Authorization expired',
        ]);
        $shopee->shouldNotReceive('shipOrder');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authorization expired');
        $this->service($shopee)->arrange($this->order());
    }

    private function order(string $configured = 'pickup'): Order
    {
        $order = new Order([
            'platform' => 'Shopee', 'order_status' => 'READY_TO_SHIP', 'order_sn' => 'ORDER-1',
            'raw_data' => ['split_up' => false],
        ]);
        $order->setRelation('store', new Store(['fulfillment_handover_method' => $configured]));
        // Unsplit Shopee orders can also have a real package number in storage.
        $order->setRelation('packages', collect([new OrderPackage(['package_id' => 'PACKAGE-1'])]));

        return $order;
    }

    private function service(ShopeeService $shopee): OrderFulfillmentService
    {
        return new OrderFulfillmentService($shopee, Mockery::mock(TiktokService::class));
    }
}

<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\OrderReturn;
use App\Services\OrderEscrowService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class OrderEscrowServiceTest extends TestCase
{
    private OrderEscrowService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OrderEscrowService;
    }

    public function test_original_escrow_is_used_before_the_adjusted_fallback(): void
    {
        $order = $this->order('READY_TO_SHIP', 100_000, 82_500);

        $this->assertSame(100_000.0, $this->service->amount($order));
    }

    public function test_negative_shopee_escrow_does_not_request_another_finance_sync(): void
    {
        $order = new Order([
            'platform' => 'Shopee',
            'order_status' => 'COMPLETED',
            'escrow_amount' => -16300,
            'fee_details' => ['return_order_sn_list' => ['261004ONKUV94K5']],
        ]);

        $this->assertFalse($this->service->needsShopeeFinanceRefresh($order));
    }

    public function test_zero_shopee_escrow_on_an_active_order_still_requests_a_refresh(): void
    {
        $order = new Order([
            'platform' => 'Shopee',
            'order_status' => 'SHIPPED',
            'escrow_amount' => 0,
            'fee_details' => ['escrow_amount' => 0],
        ]);

        $this->assertTrue($this->service->needsShopeeFinanceRefresh($order));
    }

    public function test_adjusted_escrow_is_used_when_the_original_amount_is_missing(): void
    {
        $order = $this->order('READY_TO_SHIP', null, 82_500);

        $this->assertSame(82_500.0, $this->service->amount($order));
    }

    public function test_positive_adjusted_escrow_is_used_when_original_amount_is_zero(): void
    {
        $order = $this->order('IN_TRANSIT', 0, 82_500);

        $this->assertSame(82_500.0, $this->service->amount($order));
    }

    public function test_orders_are_grouped_into_the_profit_tracker_categories(): void
    {
        $returnedOrder = $this->order('IN_TRANSIT', 300_000);
        $returnedOrder->setRelation('returns', new Collection([
            new OrderReturn(['normalized_status' => 'RETURN_PROCESSING']),
        ]));

        $orders = new Collection([
            $this->order('READY_TO_SHIP', 100_000),
            $this->order('IN_TRANSIT', 200_000),
            $returnedOrder,
            $this->order('CANCELLED', 400_000),
        ]);

        $summary = $this->service->summarize($orders, [
            OrderEscrowService::CATEGORY_NEEDS_SHIPPING,
            OrderEscrowService::CATEGORY_SHIPPED,
        ]);

        $this->assertSame(300_000.0, $summary['total_escrow']);
        $this->assertSame(2, $summary['total_orders']);
        $this->assertSame([
            'perlu_dikirim' => 1,
            'dikirim' => 2,
            'return_cancel' => 1,
        ], $summary['status_counts']);
    }

    public function test_a_rejected_return_keeps_the_order_in_its_normal_status(): void
    {
        $order = $this->order('SHIPPED', 150_000);
        $return = new OrderReturn(['normalized_status' => 'REJECTED']);
        $order->setRelation('returns', new Collection([$return]));

        $this->assertSame(OrderEscrowService::CATEGORY_SHIPPED, $this->service->category($order));
    }

    public function test_an_active_return_moves_the_order_to_return_cancel(): void
    {
        $order = $this->order('SHIPPED', 150_000);
        $return = new OrderReturn(['normalized_status' => 'RETURN_PROCESSING']);
        $order->setRelation('returns', new Collection([$return]));

        $this->assertSame(OrderEscrowService::CATEGORY_RETURN_CANCEL, $this->service->category($order));
    }

    public function test_a_pre_shipment_cancellation_is_not_part_of_profit_tracker(): void
    {
        $this->assertNull($this->service->category($this->order('CANCELLED', 150_000)));
    }

    public function test_failed_delivery_with_a_shipped_status_is_a_return_cancel_subset(): void
    {
        $order = $this->order('IN_TRANSIT', 150_000);
        $order->setRelation('packages', new Collection([
            new OrderPackage(['normalized_logistics_status' => 'DELIVERY_FAILED']),
        ]));

        $this->assertSame(OrderEscrowService::CATEGORY_RETURN_CANCEL, $this->service->category($order));
    }

    public function test_failed_delivery_outside_the_current_shipped_scope_is_ignored(): void
    {
        $order = $this->order('CANCELLED', 150_000);
        $order->setRelation('packages', new Collection([
            new OrderPackage(['normalized_logistics_status' => 'DELIVERY_FAILED']),
        ]));

        $this->assertNull($this->service->category($order));
    }

    public function test_completed_order_is_included_in_profit_analytics(): void
    {
        $this->assertTrue($this->service->isProfitAnalyticsEligible(
            $this->order('COMPLETED', 150_000),
        ));
    }

    public function test_tracking_number_alone_does_not_prove_a_cancelled_order_was_shipped(): void
    {
        $order = $this->order('CANCELLED', 150_000);
        $order->setRelation('packages', new Collection([
            new OrderPackage([
                'tracking_number' => 'TRACKING-CREATED-BEFORE-HANDOVER',
                'normalized_logistics_status' => null,
            ]),
        ]));

        $this->assertTrue($this->service->isPreShipmentCancellation($order));
        $this->assertFalse($this->service->isProfitAnalyticsSaleEligible($order));
    }

    public function test_confirmed_failed_delivery_proves_a_cancelled_order_was_shipped(): void
    {
        $order = $this->order('CANCELLED', 150_000);
        $order->setRelation('packages', new Collection([
            new OrderPackage([
                'normalized_logistics_status' => 'DELIVERY_FAILED',
                'raw_data' => [
                    'tracking' => [[
                        'action_code' => 70201,
                        'description' => 'Your package is being returned to the seller.',
                        'update_time_millis' => 1_789_026_473_000,
                    ]],
                ],
            ]),
        ]));

        $this->assertFalse($this->service->isPreShipmentCancellation($order));
        $this->assertTrue($this->service->isProfitAnalyticsSaleEligible($order));
    }

    public function test_stale_failed_delivery_status_without_tracking_evidence_is_not_treated_as_shipped(): void
    {
        $order = $this->order('CANCELLED', 150_000);
        $order->setRelation('packages', new Collection([
            new OrderPackage([
                'normalized_logistics_status' => 'DELIVERY_FAILED',
                'logistics_status' => 'Pengiriman paket gagal',
                'raw_data' => [
                    'status' => 'CANCELLED',
                    'cancel_reason' => 'Buyer cancelled before shipment',
                ],
            ]),
        ]));

        $this->assertTrue($this->service->isPreShipmentCancellation($order));
        $this->assertFalse($this->service->isProfitAnalyticsSaleEligible($order));
    }

    public function test_seller_late_cancellation_overrides_stale_failed_delivery_data(): void
    {
        $order = $this->order('CANCELLED', 150_000);
        $order->normalized_cancel_category = 'SELLER_LATE_SHIPMENT';
        $order->setRelation('packages', new Collection([
            new OrderPackage(['normalized_logistics_status' => 'DELIVERY_FAILED']),
        ]));

        $this->assertTrue($this->service->isPreShipmentCancellation($order));
        $this->assertFalse($this->service->isProfitAnalyticsSaleEligible($order));
    }

    public function test_payment_timeout_overrides_incorrect_shipment_evidence(): void
    {
        $order = $this->order('CANCELLED', 179_000);
        $order->forceFill([
            'platform' => 'Tiktokshop',
            'cancel_source' => 'SYSTEM',
            'cancel_reason' => 'Pesanan dibatalkan karena pembeli terlambat membayar',
            'normalized_cancel_category' => 'UNKNOWN',
        ]);
        $order->setRelation('packages', new Collection([
            new OrderPackage(['normalized_logistics_status' => 'DELIVERED']),
        ]));

        $this->assertTrue($this->service->isPreShipmentCancellation($order));
        $this->assertFalse($this->service->isProfitAnalyticsSaleEligible($order));
    }

    public function test_returned_order_is_excluded_from_profit_analytics(): void
    {
        $order = $this->order('COMPLETED', 150_000);
        $order->setRelation('returns', new Collection([
            new OrderReturn(['normalized_status' => 'RETURN_PROCESSING']),
        ]));

        $this->assertFalse($this->service->isProfitAnalyticsEligible($order));
    }

    private function order(string $status, ?float $escrow, ?float $adjusted = null): Order
    {
        $order = new Order([
            'order_status' => $status,
            'escrow_amount' => $escrow,
            'escrow_amount_after_adjustment' => $adjusted,
        ]);
        $order->setRelation('returns', new Collection);
        $order->setRelation('packages', new Collection);

        return $order;
    }
}

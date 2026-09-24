<?php

namespace Tests\Unit;

use App\Models\Order;
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

    public function test_adjusted_escrow_is_used_when_the_original_amount_is_missing(): void
    {
        $order = $this->order('READY_TO_SHIP', null, 82_500);

        $this->assertSame(82_500.0, $this->service->amount($order));
    }

    public function test_orders_are_grouped_into_the_profit_tracker_categories(): void
    {
        $orders = new Collection([
            $this->order('READY_TO_SHIP', 100_000),
            $this->order('IN_TRANSIT', 200_000),
            $this->order('CANCELLED', 300_000),
            $this->order('COMPLETED', 400_000),
        ]);

        $summary = $this->service->summarize($orders, [
            OrderEscrowService::CATEGORY_NEEDS_SHIPPING,
            OrderEscrowService::CATEGORY_SHIPPED,
        ]);

        $this->assertSame(300_000.0, $summary['total_escrow']);
        $this->assertSame(2, $summary['total_orders']);
        $this->assertSame([
            'perlu_dikirim' => 1,
            'dikirim' => 1,
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

    private function order(string $status, ?float $escrow, ?float $adjusted = null): Order
    {
        $order = new Order([
            'order_status' => $status,
            'escrow_amount' => $escrow,
            'escrow_amount_after_adjustment' => $adjusted,
        ]);
        $order->setRelation('returns', new Collection);

        return $order;
    }
}

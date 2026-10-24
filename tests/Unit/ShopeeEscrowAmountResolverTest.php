<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\ShopeeEscrowAmountResolver;
use PHPUnit\Framework\TestCase;

class ShopeeEscrowAmountResolverTest extends TestCase
{
    public function test_active_order_does_not_replace_positive_escrow_with_provisional_zero(): void
    {
        $order = new Order([
            'order_status' => 'IN_TRANSIT',
            'order_selling_price' => 169_000,
            'escrow_amount' => 141_132,
            'escrow_amount_after_adjustment' => 140_000,
        ]);

        $updates = (new ShopeeEscrowAmountResolver)->updates($order, [
            'order_selling_price' => 0,
            'escrow_amount' => 0,
            'escrow_amount_after_adjustment' => 0,
        ]);

        $this->assertSame(169_000.0, $updates['order_selling_price']);
        $this->assertSame(141_132.0, $updates['escrow_amount']);
        $this->assertSame(140_000.0, $updates['escrow_amount_after_adjustment']);
    }

    public function test_active_order_accepts_positive_escrow_update(): void
    {
        $order = new Order([
            'order_status' => 'SHIPPED',
            'escrow_amount' => 0,
        ]);

        $updates = (new ShopeeEscrowAmountResolver)->updates($order, [
            'escrow_amount' => '141132',
        ]);

        $this->assertSame('141132', $updates['escrow_amount']);
        $this->assertSame(141_132.0, $updates['sale_escrow_amount']);
    }

    public function test_terminal_order_can_accept_zero_from_platform(): void
    {
        $order = new Order([
            'order_status' => 'COMPLETED',
            'escrow_amount' => 141_132,
        ]);

        $updates = (new ShopeeEscrowAmountResolver)->updates($order, [
            'escrow_amount' => 0,
        ]);

        $this->assertSame(0, $updates['escrow_amount']);
    }

    public function test_return_order_numbers_are_preserved_with_fee_details(): void
    {
        $order = new Order([
            'order_status' => 'COMPLETED',
            'fee_details' => [],
        ]);

        $updates = (new ShopeeEscrowAmountResolver)->updates(
            $order,
            ['escrow_amount' => -16300],
            ['return_order_sn_list' => ['261004ONKUV94K5']],
        );

        $this->assertSame(
            ['261004ONKUV94K5'],
            $updates['fee_details']['return_order_sn_list'],
        );
    }

    public function test_positive_sale_escrow_is_snapshotted_before_a_negative_return_balance(): void
    {
        $order = new Order([
            'order_status' => 'COMPLETED',
            'escrow_amount' => 244_562,
        ]);

        $updates = (new ShopeeEscrowAmountResolver)->updates($order, [
            'escrow_amount' => -13_200,
        ]);

        $this->assertSame(-13_200, $updates['escrow_amount']);
        $this->assertSame(244_562.0, $updates['sale_escrow_amount']);
    }

    public function test_existing_sale_escrow_snapshot_is_never_overwritten(): void
    {
        $order = new Order([
            'order_status' => 'COMPLETED',
            'escrow_amount' => -13_200,
            'sale_escrow_amount' => 244_562,
        ]);

        $updates = (new ShopeeEscrowAmountResolver)->updates($order, [
            'escrow_amount' => 285_000,
        ]);

        $this->assertArrayNotHasKey('sale_escrow_amount', $updates);
    }
}

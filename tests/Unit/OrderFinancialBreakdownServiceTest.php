<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\OrderEscrowService;
use App\Services\OrderFinancialBreakdownService;
use PHPUnit\Framework\TestCase;

class OrderFinancialBreakdownServiceTest extends TestCase
{
    public function test_it_builds_shopee_fee_breakdown_and_affiliate_percentage(): void
    {
        $order = new Order([
            'platform' => 'Shopee',
            'order_selling_price' => 169000,
            'escrow_amount' => 144500,
            'fee_details' => [
                'commission_fee' => 1250,
                'service_fee' => 5000,
                'order_ams_commission_fee' => 16900,
                'buyer_paid_shipping_fee' => 1000,
            ],
        ]);

        $breakdown = $this->service()->forOrder($order);
        $components = collect($breakdown['components'])->keyBy('key');

        $this->assertTrue($breakdown['is_affiliate']);
        $this->assertSame(10.0, $breakdown['affiliate_percentage']);
        $this->assertSame(16900.0, $components['order_ams_commission_fee']['amount']);
        $this->assertSame('-', $components['order_ams_commission_fee']['operator']);
        $this->assertSame(10.0, $components['order_ams_commission_fee']['percentage']);
        $this->assertTrue($components->has('platform_adjustment'));
    }

    public function test_it_uses_tiktok_transaction_components_without_double_counting_nested_values(): void
    {
        $order = new Order([
            'platform' => 'Tiktokshop',
            'order_selling_price' => 160000,
            'escrow_amount' => 142650,
            'fee_details' => [
                'source' => 'settled',
                'summary' => [
                    'revenue_amount' => 169000,
                    'fee_and_tax_amount' => -26350,
                    'settlement_amount' => 142650,
                ],
                'transactions' => [[
                    'affiliate_commission_amount' => -16900,
                    'platform_commission_amount' => -8450,
                    'transaction_fee_amount' => -1000,
                    'sku_transactions' => [[
                        'affiliate_commission_amount' => -16900,
                    ]],
                ]],
            ],
        ]);

        $breakdown = $this->service()->forOrder($order);
        $components = collect($breakdown['components'])->keyBy('key');

        $this->assertSame(169000.0, $breakdown['gross_amount']);
        $this->assertSame(10.0, $breakdown['affiliate_percentage']);
        $this->assertSame(16900.0, $components['affiliate_commission_amount']['amount']);
        $this->assertFalse($components->has('fee_and_tax_amount'));
        $this->assertFalse($components->has('platform_adjustment'));
    }

    private function service(): OrderFinancialBreakdownService
    {
        return new OrderFinancialBreakdownService(new OrderEscrowService);
    }
}

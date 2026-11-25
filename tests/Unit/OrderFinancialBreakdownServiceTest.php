<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderProduct;
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
        $this->assertFalse($components->has('platform_adjustment'));
        $this->assertNotSame(0.0, $breakdown['reconciliation_difference']);
    }

    public function test_shopee_processing_fee_is_read_from_api_without_deducting_card_aggregate(): void
    {
        $order = new Order([
            'platform' => 'Shopee',
            'order_selling_price' => 169000,
            'escrow_amount' => 140287,
            'fee_details' => ['order_income' => [
                'commission_fee' => 13943,
                'service_fee' => 12675,
                'withholding_tax' => 845,
                'seller_order_processing_fee' => 1250,
                'seller_transaction_fee' => 0,
                'buyer_transaction_fee' => 1000,
                'credit_card_transaction_fee' => 1000,
                'items' => [['seller_order_processing_fee' => 1250]],
            ]],
        ]);

        $breakdown = $this->service()->forOrder($order);
        $components = collect($breakdown['components'])->keyBy('key');

        $this->assertSame('Biaya proses pesanan', $components['seller_order_processing_fee']['label']);
        $this->assertSame('PPh 22 0,5%', $components['withholding_tax']['label']);
        $this->assertSame(1250.0, $components['seller_order_processing_fee']['amount']);
        $this->assertFalse($components->has('credit_card_transaction_fee'));
        $this->assertFalse($components->has('seller_transaction_fee'));
        $this->assertFalse($components->has('platform_adjustment'));
        $this->assertSame(140287.0, $breakdown['escrow_amount']);
    }

    public function test_shopee_processing_fee_uses_item_totals_and_does_not_assume_a_fixed_rate(): void
    {
        $order = new Order([
            'platform' => 'Shopee',
            'order_selling_price' => 10000,
            'escrow_amount' => 8800,
            'fee_details' => [
                'seller_transaction_fee' => 200,
                'credit_card_transaction_fee' => 1200,
                'items' => [
                    ['seller_order_processing_fee' => 400],
                    ['seller_order_processing_fee' => 600],
                ],
            ],
        ]);

        $components = collect($this->service()->forOrder($order)['components'])->keyBy('key');
        $this->assertSame(1000.0, $components['seller_order_processing_fee']['amount']);
        $this->assertSame(200.0, $components['seller_transaction_fee']['amount']);
        $this->assertFalse($components->has('credit_card_transaction_fee'));
        $this->assertFalse($components->has('platform_adjustment'));

        $order->fee_details = ['seller_order_processing_fee' => 0,
            'items' => [['seller_order_processing_fee' => 1250]]];
        $components = collect($this->service()->forOrder($order)['components'])->keyBy('key');
        $this->assertFalse($components->has('seller_order_processing_fee'));

        $order->fee_details = ['commission_fee' => 100];
        $components = collect($this->service()->forOrder($order)['components'])->keyBy('key');
        $this->assertFalse($components->has('seller_order_processing_fee'));
    }

    public function test_shopee_net_subtotal_does_not_deduct_discount_twice_or_add_shipping_rebate_twice(): void
    {
        $order = new Order([
            'platform' => 'Shopee', 'order_selling_price' => 169000, 'escrow_amount' => 140287,
            'fee_details' => [
                'commission_fee' => 13943, 'service_fee' => 12675,
                'withholding_tax' => 845, 'seller_order_processing_fee' => 1250,
                'seller_discount' => 66000, 'shopee_shipping_rebate' => 6500,
                'buyer_paid_shipping_fee' => 0, 'actual_shipping_fee' => 6500,
                'final_shipping_fee' => 0,
                'net_service_fee_info_list' => [[
                    'rule_display_name' => 'Biaya Gratis Ongkir XTRA', 'fee_amount' => 12675,
                ]],
            ],
        ]);
        $result = $this->service()->forOrder($order);
        $components = collect($result['components'])->keyBy('key');
        $this->assertSame(0.0, $result['reconciliation_difference']);
        $this->assertSame('Biaya administrasi', $components['commission_fee']['label']);
        $this->assertSame('Biaya Gratis Ongkir XTRA', $components['service_fee_rule_0']['label']);
        foreach (['seller_discount', 'buyer_paid_shipping_fee', 'shopee_shipping_rebate', 'platform_adjustment', 'service_fee'] as $key) {
            $this->assertFalse($components->has($key), $key);
        }
    }

    public function test_shopee_shipping_uses_final_net_balance_or_actual_cost_but_never_estimates(): void
    {
        $order = new Order([
            'platform' => 'Shopee', 'order_selling_price' => 100000, 'escrow_amount' => 97500,
            'fee_details' => [
                'buyer_paid_shipping_fee' => 2500, 'shopee_shipping_rebate' => 60000,
                'actual_shipping_fee' => 65000, 'final_shipping_fee' => -2500,
            ],
        ]);
        $result = $this->service()->forOrder($order);
        $this->assertCount(1, $result['components']);
        $this->assertSame(2500.0, $result['components'][0]['amount']);
        $this->assertSame('-', $result['components'][0]['operator']);
        $this->assertSame(0.0, $result['reconciliation_difference']);

        $details = $order->fee_details;
        unset($details['final_shipping_fee']);
        $order->fee_details = $details;
        $this->assertSame($result['components'], $this->service()->forOrder($order)['components']);

        unset($details['actual_shipping_fee']);
        $details['estimated_shipping_fee'] = 65000;
        $order->fee_details = $details;
        $result = $this->service()->forOrder($order);
        $this->assertSame([], $result['components']);
        $this->assertSame(-2500.0, $result['reconciliation_difference']);
    }

    public function test_shopee_unmatched_service_rules_do_not_replace_api_total(): void
    {
        $order = new Order([
            'platform' => 'Shopee', 'order_selling_price' => 100000, 'escrow_amount' => 94000,
            'fee_details' => [
                'service_fee' => 5000, 'seller_coin_cash_back' => 1000,
                'net_service_fee_info_list' => [['rule_display_name' => 'Biaya Gratis Ongkir XTRA', 'fee_amount' => 4000]],
            ],
        ]);
        $result = $this->service()->forOrder($order);
        $components = collect($result['components'])->keyBy('key');
        $this->assertSame(5000.0, $components['service_fee']['amount']);
        $this->assertFalse($components->has('service_fee_rule_0'));
        $this->assertSame('-', $components['seller_coin_cash_back']['operator']);
        $this->assertSame(0.0, $result['reconciliation_difference']);
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

    public function test_it_reads_shopee_fee_components_from_item_income_when_order_totals_are_absent(): void
    {
        $order = new Order([
            'platform' => 'Shopee',
            'order_selling_price' => 169000,
            'escrow_amount' => 158000,
            'fee_details' => [
                'items' => [[
                    'item_income' => [
                        'commission_fee' => 5000,
                        'service_fee' => 6000,
                    ],
                ]],
            ],
        ]);

        $breakdown = $this->service()->forOrder($order);
        $components = collect($breakdown['components'])->keyBy('key');

        $this->assertSame(5000.0, $components['commission_fee']['amount']);
        $this->assertSame(6000.0, $components['service_fee']['amount']);
        $this->assertFalse($components->has('platform_adjustment'));
    }

    public function test_it_labels_unsettled_tiktok_difference_as_an_estimate(): void
    {
        $order = new Order([
            'platform' => 'Tiktokshop',
            'order_selling_price' => 229775,
            'escrow_amount' => 140966,
            'fee_details' => [
                'source' => 'unsettled',
                'transactions' => [[
                    'order_id' => 'ORDER-1',
                    'est_settlement_amount' => '140966',
                ]],
            ],
        ]);

        $breakdown = $this->service()->forOrder($order);
        $adjustment = collect($breakdown['components'])->firstWhere('key', 'platform_adjustment');

        $this->assertTrue($breakdown['is_estimated']);
        $this->assertSame('unsettled', $breakdown['source']);
        $this->assertSame('Estimasi total biaya platform', $adjustment['label']);
        $this->assertSame(88809.0, $adjustment['amount']);
    }

    public function test_it_uses_order_items_when_the_stored_gross_amount_is_missing(): void
    {
        $order = new Order([
            'platform' => 'Shopee',
            'order_selling_price' => 0,
            'escrow_amount' => 210000,
        ]);
        $order->setRelation('orderProducts', collect([
            new OrderProduct(['price' => 135000, 'quantity_purchased' => 2]),
        ]));

        $breakdown = $this->service()->forOrder($order);

        $this->assertSame(270000.0, $breakdown['gross_amount']);
    }

    public function test_tiktok_settled_breaks_down_dynamic_commission_processing_and_income_tax(): void
    {
        $order = new Order([
            'platform' => 'Tiktokshop', 'order_selling_price' => 294659, 'escrow_amount' => 240500,
            'fee_details' => [
                'source' => 'settled',
                'summary' => ['revenue_amount' => '295000', 'settlement_amount' => '240500'],
                'transactions' => [[
                    'revenue_amount' => '295000', 'fee_tax_amount' => '-53510', 'shipping_cost_amount' => '-990',
                    'fee_tax_breakdown' => [
                        'fee' => [
                            'affiliate_commission_amount' => '-11786',
                            'platform_commission_amount' => '-15399',
                            'dynamic_commission_amount' => '-23600',
                            'fee_per_item_sold_amount' => '-1250',
                            'affiliate_commission_amount_before_pit' => '-11786',
                        ],
                        'tax' => ['pit_amount' => '-1475'],
                    ],
                    'supplementary_component' => ['platform_commission_amount' => '-15399'],
                    'sku_transactions' => [['fee_tax_breakdown' => ['fee' => ['dynamic_commission_amount' => '-23600']]]],
                ]],
            ],
        ]);
        $result = $this->service()->forOrder($order);
        $components = collect($result['components'])->keyBy('key');
        $this->assertFalse($result['is_estimated']);
        $this->assertSame(240500.0, $result['escrow_amount']);
        $this->assertSame(23600.0, $components['dynamic_commission_amount']['amount']);
        $this->assertSame('Komisi dinamis', $components['dynamic_commission_amount']['label']);
        $this->assertSame(1250.0, $components['fee_per_item_sold_amount']['amount']);
        $this->assertSame(1475.0, $components['pit_amount']['amount']);
        $this->assertFalse($components->has('platform_adjustment'));
        $this->assertFalse($components->has('unitemized_fee_tax'));
        $this->assertFalse($components->has('affiliate_commission_amount_before_pit'));
        $this->assertSame(4.0, $result['affiliate_percentage']);
    }

    public function test_tiktok_unsettled_uses_order_revenue_and_the_same_itemized_schema(): void
    {
        $order = new Order([
            'platform' => 'Tiktokshop', 'order_selling_price' => 300000, 'escrow_amount' => 244648,
            'fee_details' => [
                'source' => 'unsettled',
                'summary' => ['revenue_amount' => '9000000', 'sum_est_settlement_amount' => '8000000'],
                'transactions' => [[
                    'est_revenue_amount' => '287951', 'est_settlement_amount' => '244648',
                    'est_fee_tax_amount' => '-43303', 'est_shipping_cost_amount' => '0',
                    'fee_tax_breakdown' => [
                        'fee' => ['platform_commission_amount' => '-20000', 'dynamic_commission_amount' => '-20614', 'fee_per_item_sold_amount' => '-1250'],
                        'tax' => ['pit_amount' => '-1439'],
                    ],
                ]],
            ],
        ]);
        $result = $this->service()->forOrder($order);
        $components = collect($result['components'])->keyBy('key');
        $this->assertTrue($result['is_estimated']);
        $this->assertSame(287951.0, $result['gross_amount']);
        $this->assertSame(244648.0, $result['escrow_amount']);
        $this->assertCount(4, $components);
        $this->assertFalse($components->has('platform_adjustment'));
    }

    public function test_tiktok_canonical_sku_rows_keep_fee_refunds_and_do_not_duplicate_totals(): void
    {
        $order = new Order([
            'platform' => 'Tiktokshop', 'order_selling_price' => 10000, 'escrow_amount' => 9400,
            'fee_details' => [
                'source' => 'settled', 'summary' => ['revenue_amount' => 10000],
                'transactions' => [[
                    'sku_transactions' => [
                        ['fee_tax_amount' => -1000, 'fee_tax_breakdown' => ['fee' => ['dynamic_commission_amount' => -1000]]],
                        ['fee_tax_amount' => 400, 'fee_tax_breakdown' => ['fee' => ['dynamic_commission_amount' => 400]]],
                    ],
                ]],
            ],
        ]);
        $result = $this->service()->forOrder($order);
        $this->assertCount(1, $result['components']);
        $this->assertSame(600.0, $result['components'][0]['amount']);
        $this->assertSame('-', $result['components'][0]['operator']);
    }

    public function test_tiktok_partial_breakdown_does_not_invent_missing_fees(): void
    {
        $order = new Order([
            'platform' => 'Tiktokshop', 'order_selling_price' => 10000, 'escrow_amount' => 8500,
            'fee_details' => [
                'source' => 'settled', 'transactions' => [[
                    'fee_tax_amount' => -1500,
                    'fee_tax_breakdown' => ['fee' => ['platform_commission_amount' => -1000]],
                ]],
            ],
        ]);
        $components = collect($this->service()->forOrder($order)['components'])->keyBy('key');
        $this->assertSame(500.0, $components['unitemized_fee_tax']['amount']);
        $this->assertFalse($components->has('dynamic_commission_amount'));
        $this->assertFalse($components->has('fee_per_item_sold_amount'));
        $this->assertFalse($components->has('platform_adjustment'));
    }

    private function service(): OrderFinancialBreakdownService
    {
        return new OrderFinancialBreakdownService(new OrderEscrowService);
    }
}

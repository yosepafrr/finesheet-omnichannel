<?php

namespace Tests\Unit;

use App\Services\TiktokEscrowAmountResolver;
use PHPUnit\Framework\TestCase;

class TiktokEscrowAmountResolverTest extends TestCase
{
    private TiktokEscrowAmountResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TiktokEscrowAmountResolver;
    }

    public function test_fallback_uses_the_sum_of_line_item_sale_prices(): void
    {
        $amount = $this->resolver->fallbackSalePrice([
            'payment' => ['original_total_product_price' => '250000'],
            'line_items' => [
                ['sale_price' => '98000'],
                ['sale_price' => '125000'],
            ],
        ]);

        $this->assertSame(223000.0, $amount);
    }

    public function test_unsettled_ignores_the_shop_aggregate_and_uses_matching_order_transactions(): void
    {
        $result = $this->resolver->unsettled([
            'code' => 0,
            'data' => [
                'sum_est_settlement_amount' => '16977387',
                'transactions' => [
                    ['order_id' => 'ORDER-1', 'est_settlement_amount' => '100000'],
                    ['order_id' => 'ORDER-1', 'est_settlement_amount' => '25078'],
                    ['order_id' => 'ORDER-2', 'est_settlement_amount' => '999999'],
                ],
            ],
        ], 'ORDER-1');

        $this->assertSame(125078.0, $result['amount']);
        $this->assertSame('unsettled', $result['details']['source']);
    }

    public function test_unsettled_rejects_an_aggregate_when_the_order_is_not_in_the_page(): void
    {
        $result = $this->resolver->unsettled([
            'code' => 0,
            'data' => [
                'sum_est_settlement_amount' => '16977387',
                'transactions' => [
                    ['order_id' => 'ORDER-2', 'est_settlement_amount' => '999999'],
                ],
            ],
        ], 'ORDER-1');

        $this->assertNull($result);
    }

    public function test_unsettled_can_sum_transactions_when_the_aggregate_is_absent(): void
    {
        $result = $this->resolver->unsettled([
            'code' => 0,
            'data' => [
                'transactions' => [
                    ['order_id' => 'ORDER-1', 'est_settlement_amount' => '100000'],
                    ['order_id' => 'ORDER-1', 'est_settlement_amount' => '25078'],
                    ['order_id' => 'ORDER-2', 'est_settlement_amount' => '999999'],
                ],
            ],
        ], 'ORDER-1');

        $this->assertSame(125078.0, $result['amount']);
        $this->assertCount(2, $result['details']['transactions']);
    }

    public function test_settled_reads_the_current_202501_response_shape(): void
    {
        $result = $this->resolver->settled([
            'code' => 0,
            'data' => [
                'order_id' => 'ORDER-1',
                'settlement_amount' => '120500',
                'sku_transactions' => [['sku_id' => 'SKU-1']],
            ],
        ]);

        $this->assertSame(120500.0, $result['amount']);
        $this->assertSame('settled', $result['details']['source']);
    }

    public function test_zero_is_a_valid_finance_amount(): void
    {
        $result = $this->resolver->unsettled([
            'code' => 0,
            'data' => [
                'sum_est_settlement_amount' => '0',
                'transactions' => [
                    ['order_id' => 'ORDER-1', 'est_settlement_amount' => '0'],
                ],
            ],
        ], 'ORDER-1');

        $this->assertSame(0.0, $result['amount']);
    }

    public function test_completed_order_keeps_refreshing_until_settled_data_is_available(): void
    {
        $unsettled = [
            'source' => 'unsettled',
            'transactions' => [['est_settlement_amount' => '125078']],
        ];
        $settled = [
            'source' => 'settled',
            'summary' => ['settlement_amount' => '120500'],
        ];

        $this->assertTrue($this->resolver->needsRefresh(null, 'IN_TRANSIT', 125078));
        $this->assertFalse($this->resolver->needsRefresh($unsettled, 'IN_TRANSIT', 125078));
        $this->assertTrue($this->resolver->needsRefresh($unsettled, 'COMPLETED', 125078));
        $this->assertFalse($this->resolver->needsRefresh($settled, 'COMPLETED', 120500));
    }

    public function test_delivered_and_completed_orders_can_use_statement_data(): void
    {
        $this->assertTrue($this->resolver->shouldTryStatement('DELIVERED'));
        $this->assertTrue($this->resolver->shouldTryStatement('completed'));
        $this->assertFalse($this->resolver->shouldTryStatement('IN_TRANSIT'));
    }

    public function test_shop_aggregate_saved_as_order_escrow_is_invalid(): void
    {
        $corruptedDetails = [
            'source' => 'unsettled',
            'summary' => ['sum_est_settlement_amount' => '16977387'],
            'transactions' => [['est_settlement_amount' => '125078']],
        ];

        $this->assertFalse(
            $this->resolver->hasValidStoredAmount($corruptedDetails, 16977387)
        );
        $this->assertTrue(
            $this->resolver->needsRefresh($corruptedDetails, 'IN_TRANSIT', 16977387)
        );
    }
}

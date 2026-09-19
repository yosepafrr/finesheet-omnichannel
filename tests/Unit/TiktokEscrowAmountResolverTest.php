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
        $this->resolver = new TiktokEscrowAmountResolver();
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

    public function test_unsettled_prefers_the_official_aggregate_amount(): void
    {
        $result = $this->resolver->unsettled([
            'code' => 0,
            'data' => [
                'sum_est_settlement_amount' => '125078',
                'transactions' => [
                    ['order_id' => 'ORDER-1', 'est_settlement_amount' => '100000'],
                ],
            ],
        ], 'ORDER-1');

        $this->assertSame(125078.0, $result['amount']);
        $this->assertSame('unsettled', $result['details']['source']);
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
                'transactions' => [],
            ],
        ], 'ORDER-1');

        $this->assertSame(0.0, $result['amount']);
    }

    public function test_completed_order_keeps_refreshing_until_settled_data_is_available(): void
    {
        $this->assertTrue($this->resolver->needsRefresh(null, 'IN_TRANSIT'));
        $this->assertFalse($this->resolver->needsRefresh(['source' => 'unsettled'], 'IN_TRANSIT'));
        $this->assertTrue($this->resolver->needsRefresh(['source' => 'unsettled'], 'COMPLETED'));
        $this->assertFalse($this->resolver->needsRefresh(['source' => 'settled'], 'COMPLETED'));
    }
}

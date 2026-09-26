<?php

namespace App\Services;

use App\Models\Order;

class OrderFinancialBreakdownService
{
    private const SHOPEE_DEDUCTIONS = [
        'commission_fee' => 'Biaya komisi',
        'service_fee' => 'Biaya layanan',
        'seller_transaction_fee' => 'Biaya transaksi',
        'escrow_tax' => 'Pajak escrow',
        'cross_border_tax' => 'Pajak lintas batas',
        'withholding_tax' => 'Pajak pemotongan',
        'order_ams_commission_fee' => 'Komisi affiliate',
        'campaign_fee' => 'Biaya kampanye',
        'reverse_shipping_fee' => 'Biaya pengiriman balik',
        'reverse_shipping_fee_sst' => 'Pajak pengiriman balik',
        'credit_card_transaction_fee' => 'Biaya transaksi kartu',
        'fbs_fee' => 'Biaya fulfillment Shopee',
        'seller_discount' => 'Diskon penjual',
        'voucher_from_seller' => 'Voucher penjual',
        'seller_return_refund' => 'Refund pengembalian',
        'refund_amount_to_buyer' => 'Refund ke pembeli',
    ];

    private const SHOPEE_ADDITIONS = [
        'buyer_paid_shipping_fee' => 'Ongkir dibayar pembeli',
        'shopee_shipping_rebate' => 'Subsidi ongkir Shopee',
        'shipping_fee_discount_from_3pl' => 'Diskon ongkir kurir',
        'seller_lost_compensation' => 'Kompensasi penjual',
        'seller_coin_cash_back' => 'Cashback koin penjual',
        'sip_subsidy' => 'Subsidi SIP',
    ];

    public function __construct(private readonly OrderEscrowService $escrowService) {}

    /**
     * @return array{
     *     gross_amount: float,
     *     escrow_amount: float,
     *     components: array<int, array{
     *         key: string,
     *         label: string,
     *         amount: float,
     *         operator: string,
     *         is_affiliate: bool,
     *         percentage: float|null
     *     }>,
     *     is_affiliate: bool,
     *     affiliate_percentage: float|null,
     *     has_platform_details: bool
     * }
     */
    public function forOrder(Order $order): array
    {
        $details = is_array($order->fee_details) ? $order->fee_details : [];
        $grossAmount = (float) ($order->order_selling_price ?? 0);
        $escrowAmount = $this->escrowService->amount($order);

        if (strtolower((string) $order->platform) === 'shopee') {
            $components = $this->shopeeComponents($details);
        } else {
            $grossAmount = $this->tiktokGrossAmount($details, $grossAmount);
            $components = $this->tiktokComponents($details);
        }

        $affiliateAmount = collect($components)
            ->where('is_affiliate', true)
            ->sum('amount');
        $affiliatePercentage = $grossAmount > 0 && $affiliateAmount > 0
            ? round(($affiliateAmount / $grossAmount) * 100, 2)
            : null;

        $components = array_map(function (array $component) use ($affiliatePercentage) {
            $component['percentage'] = $component['is_affiliate'] ? $affiliatePercentage : null;

            return $component;
        }, $components);

        $components = $this->reconcile($components, $grossAmount, $escrowAmount, $details !== []);

        return [
            'gross_amount' => $grossAmount,
            'escrow_amount' => $escrowAmount,
            'components' => array_values($components),
            'is_affiliate' => $affiliateAmount > 0,
            'affiliate_percentage' => $affiliatePercentage,
            'has_platform_details' => $details !== [],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function shopeeComponents(array $details): array
    {
        $income = is_array($details['order_income'] ?? null)
            ? $details['order_income']
            : $details;
        $components = [];

        foreach (self::SHOPEE_DEDUCTIONS as $key => $label) {
            $this->appendComponent(
                $components,
                $key,
                $label,
                $income[$key] ?? null,
                '-',
                $key === 'order_ams_commission_fee'
            );
        }

        foreach (self::SHOPEE_ADDITIONS as $key => $label) {
            $this->appendComponent($components, $key, $label, $income[$key] ?? null, '+');
        }

        if (! $this->hasComponent($components, 'order_ams_commission_fee')) {
            $affiliate = array_sum($this->numericValuesForKey($income['items'] ?? [], 'ams_commission_fee'));
            $this->appendComponent(
                $components,
                'order_ams_commission_fee',
                'Komisi affiliate',
                $affiliate,
                '-',
                true
            );
        }

        $finalShippingFee = $income['final_shipping_fee'] ?? null;
        if (is_numeric($finalShippingFee) && abs((float) $finalShippingFee) > 0.0001) {
            $operator = (float) $finalShippingFee < 0 ? '-' : '+';
            $this->appendComponent(
                $components,
                'final_shipping_fee',
                $operator === '-' ? 'Biaya pengiriman' : 'Pendapatan pengiriman',
                $finalShippingFee,
                $operator
            );
        }

        return $components;
    }

    /** @return array<int, array<string, mixed>> */
    private function tiktokComponents(array $details): array
    {
        $transactions = $details['transactions'] ?? null;
        $source = is_array($transactions) && $transactions !== []
            ? $transactions
            : ($details['summary'] ?? $details);
        $components = [];

        $this->appendFirstAvailable($components, $source, ['affiliate_commission_amount'], 'Komisi affiliate', '-', true);
        $this->appendFirstAvailable($components, $source, ['affiliate_partner_commission_amount'], 'Komisi partner affiliate', '-', true);
        $this->appendFirstAvailable($components, $source, ['platform_commission_amount', 'commission_amount'], 'Komisi platform', '-');
        $this->appendFirstAvailable($components, $source, ['transaction_fee_amount', 'transaction_fee'], 'Biaya transaksi', '-');
        $this->appendFirstAvailable($components, $source, ['service_fee_amount', 'service_fee'], 'Biaya layanan', '-');
        $this->appendFirstAvailable($components, $source, ['campaign_service_fee_amount'], 'Biaya layanan kampanye', '-');
        $this->appendFirstAvailable($components, $source, ['logistics_service_fee_amount'], 'Biaya layanan logistik', '-');
        $this->appendFirstAvailable($components, $source, ['shipping_cost_amount', 'shipping_fee_amount'], 'Biaya pengiriman', '-');
        $this->appendFirstAvailable($components, $source, ['tax_amount', 'withholding_tax_amount'], 'Pajak', '-');
        $this->appendFirstAvailable($components, $source, ['seller_discount_amount'], 'Diskon penjual', '-');
        $this->appendFirstAvailable($components, $source, ['refund_amount'], 'Refund pembeli', '-');

        $hasDetailedFee = collect($components)->contains(
            fn (array $component) => str_contains($component['key'], 'fee')
                || str_contains($component['key'], 'commission')
                || str_contains($component['key'], 'tax')
        );

        if (! $hasDetailedFee) {
            $this->appendFirstAvailable($components, $source, ['fee_and_tax_amount'], 'Biaya dan pajak platform', '-');
        }

        return $components;
    }

    private function tiktokGrossAmount(array $details, float $fallback): float
    {
        $summary = is_array($details['summary'] ?? null) ? $details['summary'] : [];

        return is_numeric($summary['revenue_amount'] ?? null)
            ? abs((float) $summary['revenue_amount'])
            : $fallback;
    }

    /** @param array<int, array<string, mixed>> $components */
    private function appendFirstAvailable(
        array &$components,
        array $source,
        array $keys,
        string $label,
        string $operator,
        bool $isAffiliate = false
    ): void {
        foreach ($keys as $key) {
            $amount = array_sum($this->numericValuesForKey($source, $key));
            if (abs($amount) <= 0.0001) {
                continue;
            }

            $this->appendComponent($components, $key, $label, $amount, $operator, $isAffiliate);

            return;
        }
    }

    /** @param array<int, array<string, mixed>> $components */
    private function appendComponent(
        array &$components,
        string $key,
        string $label,
        mixed $value,
        string $operator,
        bool $isAffiliate = false
    ): void {
        if (! is_numeric($value) || abs((float) $value) <= 0.0001) {
            return;
        }

        $components[] = [
            'key' => $key,
            'label' => $label,
            'amount' => abs((float) $value),
            'operator' => $operator,
            'is_affiliate' => $isAffiliate,
            'percentage' => null,
        ];
    }

    /** @param array<int, array<string, mixed>> $components */
    private function reconcile(array $components, float $gross, float $escrow, bool $hasDetails): array
    {
        $computed = $gross;
        foreach ($components as $component) {
            $computed += $component['operator'] === '+'
                ? $component['amount']
                : -$component['amount'];
        }

        $difference = $escrow - $computed;
        if (abs($difference) <= 0.5) {
            return $components;
        }

        $components[] = [
            'key' => 'platform_adjustment',
            'label' => $hasDetails
                ? 'Penyesuaian lainnya dari platform'
                : 'Potongan platform (rincian belum tersedia)',
            'amount' => abs($difference),
            'operator' => $difference > 0 ? '+' : '-',
            'is_affiliate' => false,
            'percentage' => null,
        ];

        return $components;
    }

    /** @return array<int, float> */
    private function numericValuesForKey(array $value, string $key): array
    {
        if (array_key_exists($key, $value) && is_numeric($value[$key])) {
            return [(float) $value[$key]];
        }

        $values = [];
        foreach ($value as $child) {
            if (is_array($child)) {
                array_push($values, ...$this->numericValuesForKey($child, $key));
            }
        }

        return $values;
    }

    /** @param array<int, array<string, mixed>> $components */
    private function hasComponent(array $components, string $key): bool
    {
        return collect($components)->contains(fn (array $component) => $component['key'] === $key);
    }
}

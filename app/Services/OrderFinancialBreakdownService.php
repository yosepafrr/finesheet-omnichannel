<?php

namespace App\Services;

use App\Models\Order;

class OrderFinancialBreakdownService
{
    private const TIKTOK_FEES = [
        'platform_commission_amount' => 'Komisi platform',
        'dynamic_commission_amount' => 'Komisi dinamis',
        'fee_per_item_sold_amount' => 'Biaya pemrosesan pesanan',
        'order_processing_fee_amount' => 'Biaya pemrosesan pesanan',
        'transaction_fee_amount' => 'Biaya transaksi',
        'affiliate_commission_amount' => 'Komisi affiliate',
        'affiliate_partner_commission_amount' => 'Komisi partner affiliate',
        'affiliate_ads_commission_amount' => 'Komisi iklan affiliate',
        'credit_card_handling_fee_amount' => 'Biaya penanganan kartu',
        'sfp_service_fee_amount' => 'Biaya layanan gratis ongkir',
        'live_specials_fee_amount' => 'Biaya LIVE Specials',
        'bonus_cashback_service_fee_amount' => 'Biaya layanan cashback',
        'mall_service_fee_amount' => 'Biaya layanan Mall',
        'voucher_xtra_service_fee_amount' => 'Biaya Voucher XTRA',
        'flash_sales_service_fee_amount' => 'Biaya Flash Sale',
        'cofunded_promotion_service_fee_amount' => 'Biaya promosi bersama',
        'pre_order_service_fee_amount' => 'Biaya layanan pre-order',
        'referral_fee_amount' => 'Biaya referral',
        'refund_administration_fee_amount' => 'Biaya administrasi refund',
        'smart_promotion_fee_amount' => 'Biaya Smart Promotion',
        'gmv_max_ad_fee_amount' => 'Biaya iklan GMV Max',
    ];

    private const TIKTOK_TAXES = [
        'pit_amount' => 'Pajak penghasilan (PPh)',
        'pph_22_amount' => 'PPh 22 0,5%',
        'withholding_tax_amount' => 'Pajak pemotongan',
        'vat_amount' => 'PPN',
        'import_vat_amount' => 'PPN impor',
        'gst_amount' => 'Pajak GST',
        'sst_amount' => 'Pajak SST',
    ];

    private const SHOPEE_DEDUCTIONS = [
        'commission_fee' => 'Biaya administrasi',
        'service_fee' => 'Biaya layanan Shopee',
        'seller_transaction_fee' => 'Biaya transaksi',
        'seller_order_processing_fee' => 'Biaya proses pesanan',
        'escrow_tax' => 'Pajak escrow',
        'cross_border_tax' => 'Pajak lintas batas',
        'withholding_tax' => 'PPh 22 0,5%',
        'order_ams_commission_fee' => 'Komisi affiliate',
        'campaign_fee' => 'Biaya kampanye',
        'reverse_shipping_fee' => 'Biaya pengiriman balik',
        'reverse_shipping_fee_sst' => 'Pajak pengiriman balik',
        'fbs_fee' => 'Biaya fulfillment Shopee',
        'voucher_from_seller' => 'Voucher penjual',
        'seller_coin_cash_back' => 'Cashback koin ditanggung penjual',
        'seller_return_refund' => 'Refund pengembalian',
    ];

    private const SHOPEE_ADDITIONS = [
        'seller_lost_compensation' => 'Kompensasi penjual',
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
     *     has_platform_details: bool,
     *     source: string|null,
     *     is_estimated: bool
     * }
     */
    public function forOrder(Order $order): array
    {
        if ($order->platform === 'Manual') {
            $revenue = ManualOrderService::revenueAmount($order);

            return [
                'gross_amount' => $revenue, 'revenue_amount' => $revenue, 'escrow_amount' => 0,
                'components' => [], 'is_affiliate' => false, 'affiliate_percentage' => null,
                'has_platform_details' => true, 'source' => 'manual', 'is_estimated' => false,
                'reconciliation_difference' => 0,
            ];
        }
        $details = is_array($order->fee_details) ? $order->fee_details : [];
        $grossAmount = (float) ($order->order_selling_price ?? 0);
        if ($grossAmount <= 0 && $order->relationLoaded('orderProducts')) {
            $grossAmount = (float) $order->orderProducts->sum(
                fn ($item) => (float) ($item->price ?? 0)
                    * max(1, (int) ($item->quantity_purchased ?? 0))
            );
        }
        $escrowAmount = $this->escrowService->amount($order);
        $isShopee = strtolower((string) $order->platform) === 'shopee';
        $source = $isShopee
            ? ($details !== [] ? 'shopee_escrow' : null)
            : ($details['source'] ?? null);

        if ($isShopee) {
            $components = $this->shopeeComponents($details);
        } else {
            $grossAmount = $this->tiktokGrossAmount($details, $grossAmount);
            $components = $this->tiktokComponents($details);
        }

        $affiliateAmount = collect($components)
            ->where('is_affiliate', true)
            ->sum(fn (array $component) => $component['operator'] === '-' ? $component['amount'] : -$component['amount']);
        $affiliatePercentage = $grossAmount > 0 && $affiliateAmount > 0
            ? round(($affiliateAmount / $grossAmount) * 100, 2)
            : null;

        $components = array_map(function (array $component) use ($affiliatePercentage) {
            $component['percentage'] = $component['is_affiliate'] ? $affiliatePercentage : null;

            return $component;
        }, $components);

        $reconciliationDifference = 0.0;
        if ($isShopee) {
            $computed = $grossAmount + collect($components)->sum(
                fn (array $component) => $component['operator'] === '+' ? $component['amount'] : -$component['amount']
            );
            $reconciliationDifference = round($escrowAmount - $computed, 2);
        } else {
            $components = $this->reconcile($components, $grossAmount, $escrowAmount, $details !== [], $source);
        }

        return [
            'gross_amount' => $grossAmount,
            'escrow_amount' => $escrowAmount,
            'components' => array_values($components),
            'is_affiliate' => $affiliateAmount > 0,
            'affiliate_percentage' => $affiliatePercentage,
            'has_platform_details' => $details !== [],
            'source' => $source,
            'is_estimated' => ! $isShopee && $source !== 'settled',
            'reconciliation_difference' => $reconciliationDifference,
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
            $value = $income[$key]
                ?? array_sum($this->numericValuesForKey($income['items'] ?? [], $key));
            if ($key === 'service_fee' && $this->appendShopeeServiceFees($components, $income, $value)) {
                continue;
            }
            $this->appendComponent(
                $components,
                $key,
                $label,
                $value,
                '-',
                $key === 'order_ams_commission_fee'
            );
        }

        if (! array_key_exists('seller_return_refund', $income)) {
            $this->appendComponent($components, 'refund_amount_to_buyer', 'Refund pengembalian', $income['refund_amount_to_buyer'] ?? null, '-');
        }

        foreach (self::SHOPEE_ADDITIONS as $key => $label) {
            $value = $income[$key]
                ?? array_sum($this->numericValuesForKey($income['items'] ?? [], $key));
            $this->appendComponent($components, $key, $label, $value, '+');
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

        // A final shipping balance already includes shipping credits and costs.
        // Only derive it from actual costs when the platform has not supplied it.
        $finalShippingFee = $income['final_shipping_fee'] ?? null;
        if (! is_numeric($finalShippingFee) && is_numeric($income['actual_shipping_fee'] ?? null)) {
            $finalShippingFee = (float) ($income['buyer_paid_shipping_fee'] ?? 0)
                + (float) ($income['shopee_shipping_rebate'] ?? 0)
                + (float) ($income['shipping_fee_discount_from_3pl'] ?? 0)
                - (float) $income['actual_shipping_fee'];
        }
        if (is_numeric($finalShippingFee) && abs((float) $finalShippingFee) > 0.0001) {
            $operator = (float) $finalShippingFee < 0 ? '-' : '+';
            $this->appendComponent(
                $components,
                'final_shipping_fee',
                'Subtotal ongkos kirim',
                $finalShippingFee,
                $operator
            );
        }

        return $components;
    }

    private function appendShopeeServiceFees(array &$components, array $income, mixed $total): bool
    {
        $rules = $income['net_service_fee_info_list'] ?? [];
        if (! is_array($rules) || ! is_numeric($total) || (float) $total <= 0) {
            return false;
        }
        if (isset($rules['fee_amount'])) {
            $rules = [$rules];
        }
        $ruleCount = count($rules);
        $rules = array_values(array_filter($rules, fn ($rule) => is_array($rule)
            && is_numeric($rule['fee_amount'] ?? null)
            && (float) $rule['fee_amount'] >= 0
            && is_string($rule['rule_display_name'] ?? null)
            && trim($rule['rule_display_name']) !== ''
            && trim($rule['rule_display_name']) !== '-'));
        if ($rules === [] || count($rules) !== $ruleCount
            || abs(array_sum(array_column($rules, 'fee_amount')) - (float) $total) > 0.01) {
            return false;
        }
        foreach ($rules as $index => $rule) {
            $this->appendComponent($components, 'service_fee_rule_'.$index, trim($rule['rule_display_name']), $rule['fee_amount'], '-');
        }

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    private function tiktokComponents(array $details): array
    {
        $transactions = $details['transactions'] ?? null;
        $source = is_array($transactions) && $transactions !== []
            ? $transactions
            : ($details['summary'] ?? $details);
        $rows = array_is_list($source) ? $source : [$source];
        $components = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                array_push($components, ...$this->tiktokRowComponents($row));
            }
        }

        // Multiple SKU/statement rows may contain the same fee. Sum their signed
        // contributions rather than counting a summary and its children twice.
        return collect($components)->groupBy('key')->map(function ($items) {
            $component = $items->first();
            $amount = $items->sum(fn ($item) => $item['operator'] === '+' ? $item['amount'] : -$item['amount']);
            $component['amount'] = abs($amount);
            $component['operator'] = $amount > 0 ? '+' : '-';

            return $component;
        })->filter(fn ($item) => $item['amount'] > 0.0001)->values()->all();
    }

    private function tiktokRowComponents(array $row): array
    {
        if (is_array($row['fee_tax_breakdown'] ?? null)) {
            $components = [];
            foreach (['fee' => self::TIKTOK_FEES, 'tax' => self::TIKTOK_TAXES] as $group => $labels) {
                $values = $row['fee_tax_breakdown'][$group] ?? [];
                foreach (is_array($values) ? $values : [] as $key => $value) {
                    // Before-tax creator amounts are supplementary to the final
                    // affiliate commission, not an additional seller deduction.
                    if (str_contains($key, 'before_pit') || str_contains($key, 'pit_withheld_from')) {
                        continue;
                    }
                    $label = $labels[$key] ?? ($group === 'fee' ? 'Biaya platform' : 'Pajak').' ('.$key.')';
                    $this->appendComponent($components, $key, $label, $value,
                        (float) (is_numeric($value) ? $value : 0) > 0 ? '+' : '-',
                        in_array($key, ['affiliate_commission_amount', 'affiliate_partner_commission_amount', 'affiliate_ads_commission_amount'], true));
                }
            }
            $feeTotal = $row['fee_tax_amount'] ?? $row['est_fee_tax_amount'] ?? $row['fee_and_tax_amount'] ?? null;
            if (is_numeric($feeTotal)) {
                $known = collect($components)->sum(fn ($item) => $item['operator'] === '+' ? $item['amount'] : -$item['amount']);
                $remaining = (float) $feeTotal - $known;
                $this->appendComponent($components, 'unitemized_fee_tax', 'Biaya dan pajak belum dirinci', $remaining, $remaining > 0 ? '+' : '-');
            }
            $shipping = $row['shipping_cost_amount'] ?? $row['est_shipping_cost_amount'] ?? null;
            $this->appendComponent($components, 'shipping_cost_amount', 'Biaya pengiriman bersih', $shipping,
                is_numeric($shipping) && (float) $shipping > 0 ? '+' : '-');
            $adjustment = $row['adjustment_amount'] ?? $row['est_adjustment_amount'] ?? null;
            $this->appendComponent($components, 'adjustment_amount', 'Penyesuaian transaksi', $adjustment,
                is_numeric($adjustment) && (float) $adjustment > 0 ? '+' : '-');

            return $components;
        }

        if (is_array($row['sku_transactions'] ?? null) && collect($row['sku_transactions'])->contains(
            fn ($sku) => is_array($sku) && is_array($sku['fee_tax_breakdown'] ?? null)
        )) {
            return array_merge(...array_map(fn ($sku) => is_array($sku) ? $this->tiktokRowComponents($sku) : [], $row['sku_transactions']));
        }

        $source = $row;
        $components = [];

        $this->appendFirstAvailable($components, $source, ['affiliate_commission_amount'], 'Komisi affiliate', '-', true);
        $this->appendFirstAvailable($components, $source, ['affiliate_partner_commission_amount'], 'Komisi partner affiliate', '-', true);
        $this->appendFirstAvailable($components, $source, ['platform_commission_amount', 'commission_amount'], 'Komisi platform', '-');
        $this->appendFirstAvailable($components, $source, ['dynamic_commission_amount'], 'Komisi dinamis', '-');
        $this->appendFirstAvailable($components, $source, ['order_processing_fee_amount', 'fee_per_item_sold_amount'], 'Biaya pemrosesan pesanan', '-');
        $this->appendFirstAvailable($components, $source, ['transaction_fee_amount', 'transaction_fee'], 'Biaya transaksi', '-');
        $this->appendFirstAvailable($components, $source, ['service_fee_amount', 'service_fee'], 'Biaya layanan', '-');
        $this->appendFirstAvailable($components, $source, ['campaign_service_fee_amount'], 'Biaya layanan kampanye', '-');
        $this->appendFirstAvailable($components, $source, ['logistics_service_fee_amount'], 'Biaya layanan logistik', '-');
        $this->appendFirstAvailable($components, $source, ['shipping_cost_amount', 'est_shipping_cost_amount', 'shipping_fee_amount'], 'Biaya pengiriman', '-');
        $this->appendFirstAvailable($components, $source, ['pph_22_amount'], 'PPh 22 0,5%', '-');
        $this->appendFirstAvailable($components, $source, ['tax_amount', 'withholding_tax_amount', 'pit_amount'], 'Pajak penghasilan (PPh)', '-');
        $this->appendFirstAvailable($components, $source, ['seller_discount_amount'], 'Diskon penjual', '-');
        $this->appendFirstAvailable($components, $source, ['refund_amount'], 'Refund pembeli', '-');

        $hasDetailedFee = collect($components)->contains(
            fn (array $component) => str_contains($component['key'], 'fee')
                || str_contains($component['key'], 'commission')
                || str_contains($component['key'], 'tax')
        );

        if (! $hasDetailedFee) {
            $this->appendFirstAvailable($components, $source, ['fee_and_tax_amount', 'fee_tax_amount', 'est_fee_tax_amount'], 'Biaya dan pajak platform', '-');
        }

        return $components;
    }

    private function tiktokGrossAmount(array $details, float $fallback): float
    {
        $summary = is_array($details['summary'] ?? null) ? $details['summary'] : [];
        if (($details['source'] ?? null) !== 'unsettled' && is_numeric($summary['revenue_amount'] ?? null)) {
            return (float) $summary['revenue_amount'];
        }
        $transactions = $details['transactions'] ?? [];
        $revenueKey = ($details['source'] ?? null) === 'unsettled' ? 'est_revenue_amount' : 'revenue_amount';
        $amounts = $this->numericValuesForKey(is_array($transactions) ? $transactions : [], $revenueKey);
        if ($amounts !== []) {
            return array_sum($amounts);
        }
        // The unsettled summary is shop-wide; only its filtered order rows may
        // supply revenue for an individual order.
        if (($details['source'] ?? null) === 'unsettled') {
            return $fallback;
        }

        return $fallback;
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
    private function reconcile(
        array $components,
        float $gross,
        float $escrow,
        bool $hasDetails,
        ?string $source
    ): array {
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
            'label' => match ($source) {
                'unsettled' => $components === [] ? 'Estimasi total biaya platform' : 'Estimasi biaya lainnya (belum dirinci)',
                null => 'Potongan platform (menunggu rincian API)',
                default => $hasDetails
                    ? 'Selisih rincian transaksi (belum dirinci)'
                    : 'Potongan platform (rincian belum tersedia)',
            },
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

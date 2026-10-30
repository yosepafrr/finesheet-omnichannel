<?php

namespace App\Services;

use App\Models\Order;

class ShopeeEscrowAmountResolver
{
    private const ACTIVE_STATUSES = [
        'READY_TO_SHIP',
        'PROCESSED',
        'AWAITING_SHIPMENT',
        'AWAITING_COLLECTION',
        'SHIPPED',
        'IN_TRANSIT',
        'TO_CONFIRM_RECEIVE',
        'DELIVERED',
    ];

    /**
     * Build escrow updates without allowing a provisional zero from Shopee to
     * erase a positive value that was already recorded for an active order.
     *
     * @return array<string, mixed>
     */
    public function updates(
        Order $order,
        array $income,
        array $escrow = [],
        bool $allowTerminalZero = false,
    ): array {
        $preservePositive = in_array(
            strtoupper((string) $order->order_status),
            self::ACTIVE_STATUSES,
            true
        ) || ! $allowTerminalZero;
        $feeDetails = $income;
        $returnOrderSns = $this->returnOrderSns($escrow);
        if ($returnOrderSns === []) {
            $returnOrderSns = $this->returnOrderSns(
                is_array($order->fee_details) ? $order->fee_details : []
            );
        }
        if ($returnOrderSns !== []) {
            $feeDetails['return_order_sn_list'] = $returnOrderSns;
        }

        $resolvedEscrow = $this->resolveAmount(
            $income['escrow_amount'] ?? null,
            $order->escrow_amount,
            $preservePositive
        );
        $resolvedAdjustedEscrow = $this->resolveAmount(
            $income['escrow_amount_after_adjustment'] ?? null,
            $order->escrow_amount_after_adjustment,
            $preservePositive
        );
        $updates = [
            'order_selling_price' => $this->resolveAmount(
                $income['order_selling_price'] ?? null,
                $order->order_selling_price,
                $preservePositive
            ),
            'escrow_amount' => $resolvedEscrow,
            'escrow_amount_after_adjustment' => $resolvedAdjustedEscrow,
            'fee_details' => $feeDetails,
        ];

        $saleEscrowSnapshot = $this->saleEscrowSnapshot(
            $order,
            $resolvedEscrow,
            $resolvedAdjustedEscrow,
        );
        if ($saleEscrowSnapshot !== null) {
            $updates['sale_escrow_amount'] = $saleEscrowSnapshot;
            $updates['sale_escrow_source'] = 'platform_api';
            $updates['sale_escrow_is_estimated'] = false;
            $updates['sale_escrow_sample_size'] = 0;
            $updates['sale_escrow_ratio'] = null;
        }

        return $updates;
    }

    /** @return array<int, string> */
    public function returnOrderSns(array $escrow): array
    {
        $value = $escrow['return_order_sn_list'] ?? [];
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $value);
        } elseif (! is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->map(function ($return) {
                if (is_array($return)) {
                    return $return['return_sn'] ?? $return['return_order_sn'] ?? null;
                }

                return $return;
            })
            ->filter(fn ($returnSn) => is_scalar($returnSn) && trim((string) $returnSn) !== '')
            ->map(fn ($returnSn) => trim((string) $returnSn))
            ->unique()
            ->values()
            ->all();
    }

    private function resolveAmount($incoming, $existing, bool $preservePositive): mixed
    {
        if (! is_numeric($incoming)) {
            return $existing;
        }

        if ($preservePositive
            && abs((float) $incoming) < 0.0001
            && (float) $existing > 0) {
            return $existing;
        }

        return $incoming;
    }

    private function saleEscrowSnapshot(
        Order $order,
        mixed $resolvedEscrow,
        mixed $resolvedAdjustedEscrow,
    ): ?float {
        if (is_numeric($order->sale_escrow_amount)
            && (float) $order->sale_escrow_amount > 0) {
            return null;
        }

        foreach ([
            $order->escrow_amount,
            $resolvedEscrow,
            $order->escrow_amount_after_adjustment,
            $resolvedAdjustedEscrow,
        ] as $amount) {
            if (is_numeric($amount) && (float) $amount > 0) {
                return (float) $amount;
            }
        }

        return null;
    }
}

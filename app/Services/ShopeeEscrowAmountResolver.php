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
    public function updates(Order $order, array $income): array
    {
        $preservePositive = in_array(
            strtoupper((string) $order->order_status),
            self::ACTIVE_STATUSES,
            true
        );

        return [
            'order_selling_price' => $this->resolveAmount(
                $income['order_selling_price'] ?? null,
                $order->order_selling_price,
                $preservePositive
            ),
            'escrow_amount' => $this->resolveAmount(
                $income['escrow_amount'] ?? null,
                $order->escrow_amount,
                $preservePositive
            ),
            'escrow_amount_after_adjustment' => $this->resolveAmount(
                $income['escrow_amount_after_adjustment'] ?? null,
                $order->escrow_amount_after_adjustment,
                $preservePositive
            ),
            'fee_details' => $income,
        ];
    }

    private function resolveAmount($incoming, $existing, bool $preservePositive): mixed
    {
        if (! is_numeric($incoming)) {
            return $existing;
        }

        if ($preservePositive && (float) $incoming <= 0 && (float) $existing > 0) {
            return $existing;
        }

        return $incoming;
    }
}

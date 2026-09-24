<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Collection;

class OrderEscrowService
{
    public const CATEGORY_NEEDS_SHIPPING = 'perlu_dikirim';

    public const CATEGORY_SHIPPED = 'dikirim';

    public const CATEGORY_RETURN_CANCEL = 'return_cancel';

    private const NEEDS_SHIPPING_STATUSES = [
        'READY_TO_SHIP',
        'PROCESSED',
        'AWAITING_SHIPMENT',
        'AWAITING_COLLECTION',
    ];

    private const SHIPPED_STATUSES = [
        'SHIPPED',
        'IN_TRANSIT',
        'DELIVERED',
        'TO_CONFIRM_RECEIVE',
    ];

    private const RETURN_CANCEL_STATUSES = [
        'CANCEL',
        'CANCELLED',
        'IN_CANCEL',
        'RETURNED',
        'TO_RETURN',
    ];

    private const INACTIVE_RETURN_STATUSES = [
        'REJECTED',
        'CANCELLED',
    ];

    public function amount(Order $order): float
    {
        $amount = $order->escrow_amount;

        if ($amount === null) {
            $amount = $order->escrow_amount_after_adjustment;
        }

        return (float) ($amount ?? 0);
    }

    public function category(Order $order): ?string
    {
        $status = strtoupper(trim((string) $order->order_status));

        if (
            in_array($status, self::RETURN_CANCEL_STATUSES, true)
            || $this->hasRelevantReturn($order)
        ) {
            return self::CATEGORY_RETURN_CANCEL;
        }

        if (in_array($status, self::SHIPPED_STATUSES, true)) {
            return self::CATEGORY_SHIPPED;
        }

        if (in_array($status, self::NEEDS_SHIPPING_STATUSES, true)) {
            return self::CATEGORY_NEEDS_SHIPPING;
        }

        return null;
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  array<int, string>  $includedCategories
     * @return array{
     *     total_escrow: float,
     *     total_orders: int,
     *     status_counts: array<string, int>,
     *     status_escrow: array<string, float>
     * }
     */
    public function summarize(Collection $orders, array $includedCategories): array
    {
        $statusCounts = $this->emptyCategoryValues(0);
        $statusEscrow = $this->emptyCategoryValues(0.0);
        $totalEscrow = 0.0;
        $totalOrders = 0;

        foreach ($orders as $order) {
            $category = $this->category($order);

            if ($category === null) {
                continue;
            }

            $amount = $this->amount($order);
            $statusCounts[$category]++;
            $statusEscrow[$category] += $amount;

            if (in_array($category, $includedCategories, true)) {
                $totalEscrow += $amount;
                $totalOrders++;
            }
        }

        return [
            'total_escrow' => $totalEscrow,
            'total_orders' => $totalOrders,
            'status_counts' => $statusCounts,
            'status_escrow' => $statusEscrow,
        ];
    }

    private function hasRelevantReturn(Order $order): bool
    {
        if (! $order->relationLoaded('returns')) {
            $order->load('returns');
        }

        return $order->returns->contains(function ($return) {
            $status = strtoupper(trim((string) $return->normalized_status));

            return ! in_array($status, self::INACTIVE_RETURN_STATUSES, true);
        });
    }

    /**
     * @template TValue of int|float
     * @param  TValue  $value
     * @return array<string, TValue>
     */
    private function emptyCategoryValues(int|float $value): array
    {
        return [
            self::CATEGORY_NEEDS_SHIPPING => $value,
            self::CATEGORY_SHIPPED => $value,
            self::CATEGORY_RETURN_CANCEL => $value,
        ];
    }
}

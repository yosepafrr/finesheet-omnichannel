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

        if (in_array($status, self::SHIPPED_STATUSES, true)) {
            return $this->hasReturnOrFailedDelivery($order)
                ? self::CATEGORY_RETURN_CANCEL
                : self::CATEGORY_SHIPPED;
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

            // Return/batal is a subset of the Order List "Dikirim" statuses.
            // Keep the shipped metric inclusive while the selected totals remain
            // mutually exclusive and therefore cannot count an order twice.
            if ($category === self::CATEGORY_RETURN_CANCEL) {
                $statusCounts[self::CATEGORY_SHIPPED]++;
                $statusEscrow[self::CATEGORY_SHIPPED] += $amount;
            }

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

    private function hasReturnOrFailedDelivery(Order $order): bool
    {
        if (! $order->relationLoaded('returns')) {
            $order->load('returns');
        }

        if ($order->returns->contains(function ($return) {
            $status = strtoupper(trim((string) $return->normalized_status));

            return ! in_array($status, self::INACTIVE_RETURN_STATUSES, true);
        })) {
            return true;
        }

        if (! $order->relationLoaded('packages')) {
            $order->load('packages');
        }

        return $order->packages->contains(
            fn ($package) => strtoupper(trim((string) $package->normalized_logistics_status)) === 'DELIVERY_FAILED'
        );
    }

    /**
     * @template TValue of int|float
     *
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

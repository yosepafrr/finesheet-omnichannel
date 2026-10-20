<?php

namespace App\Services;

use App\Models\MarketplaceAdSpend;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProfitAnalyticsService
{
    public function __construct(private readonly OrderEscrowService $escrowService) {}

    /**
     * @param  Collection<int, Order>  $orders
     * @param  Collection<int, array{hpp_amount: float, escrow_amount: float, profit_amount: float, has_missing_hpp: bool}>  $financialSummaries
     * @param  Collection<int, MarketplaceAdSpend>  $adSpends
     * @param  array{key: string, start: Carbon, end: Carbon, granularity: string}  $period
     */
    public function build(
        Collection $orders,
        Collection $financialSummaries,
        array $period,
        Collection $adSpends,
        int $shopeeStoreCount,
    ): array {
        $eligibleOrders = $orders->filter(
            fn (Order $order) => $this->escrowService->isProfitAnalyticsEligible($order),
        );
        $groupedOrders = $eligibleOrders->groupBy(function (Order $order) use ($period) {
            $date = $order->order_time->copy()->setTimezone('Asia/Jakarta');

            return $period['granularity'] === 'month'
                ? $date->format('Y-m')
                : $date->format('Y-m-d');
        });
        $groupedAdSpends = $adSpends->groupBy(function (MarketplaceAdSpend $spend) use ($period) {
            return $period['granularity'] === 'month'
                ? $spend->spend_date->format('Y-m')
                : $spend->spend_date->format('Y-m-d');
        });

        $points = collect();
        $cursor = $period['start']->copy()->startOfDay();
        $last = $period['end']->copy();

        while ($cursor->lessThanOrEqualTo($last)) {
            $bucketKey = $period['granularity'] === 'month'
                ? $cursor->format('Y-m')
                : $cursor->format('Y-m-d');
            $bucketOrders = $groupedOrders->get($bucketKey, collect());
            $bucketFinance = $bucketOrders->map(
                fn (Order $order) => $financialSummaries->get($order->id),
            )->filter();
            $bucketAdSpends = $groupedAdSpends->get($bucketKey, collect());
            $grossProfit = (float) $bucketFinance->sum('profit_amount');
            $adSpendAmount = (float) $bucketAdSpends->sum('amount');

            $bucketStart = $period['granularity'] === 'month'
                ? $cursor->copy()->startOfMonth()
                : $cursor->copy()->startOfDay();
            $bucketEnd = $period['granularity'] === 'month'
                ? $cursor->copy()->endOfMonth()
                : $cursor->copy()->endOfDay();
            if ($bucketStart->lessThan($period['start'])) {
                $bucketStart = $period['start']->copy();
            }
            if ($bucketEnd->greaterThan($period['end'])) {
                $bucketEnd = $period['end']->copy();
            }
            $bucketDayCount = (int) floor(
                $bucketStart->copy()->startOfDay()->diffInDays($bucketEnd->copy()->startOfDay()),
            ) + 1;
            $expectedAdSpendRecords = $shopeeStoreCount * $bucketDayCount;

            $points->push([
                'key' => $bucketKey,
                'order_count' => $bucketOrders->count(),
                'hpp_amount' => (float) $bucketFinance->sum('hpp_amount'),
                'escrow_amount' => (float) $bucketFinance->sum('escrow_amount'),
                'gross_profit_amount' => $grossProfit,
                'ad_spend_amount' => $adSpendAmount,
                'profit_amount' => $grossProfit - $adSpendAmount,
                'ad_spend_synced' => $bucketAdSpends->isNotEmpty(),
                'ad_spend_complete' => $expectedAdSpendRecords > 0
                    && $bucketAdSpends->count() >= $expectedAdSpendRecords,
            ]);

            $period['granularity'] === 'month'
                ? $cursor->addMonthNoOverflow()->startOfMonth()
                : $cursor->addDay();
        }

        return [
            'period' => $period['key'],
            'start_date' => $period['start']->toDateString(),
            'end_date' => $period['end']->toDateString(),
            'granularity' => $period['granularity'],
            'totals' => [
                'order_count' => (int) $points->sum('order_count'),
                'hpp_amount' => (float) $points->sum('hpp_amount'),
                'escrow_amount' => (float) $points->sum('escrow_amount'),
                'gross_profit_amount' => (float) $points->sum('gross_profit_amount'),
                'ad_spend_amount' => (float) $points->sum('ad_spend_amount'),
                'profit_amount' => (float) $points->sum('profit_amount'),
            ],
            'points' => $points,
            'ad_spend' => [
                'available' => $adSpends->isNotEmpty(),
                'amount' => (float) $points->sum('ad_spend_amount'),
                'platforms_included' => ['Shopee'],
                'platforms_pending' => ['Tiktokshop'],
                'is_partial' => true,
                'coverage_complete' => $shopeeStoreCount > 0
                    && $adSpends->count() >= ($shopeeStoreCount * (
                        (int) floor(
                            $period['start']->copy()->startOfDay()->diffInDays(
                                $period['end']->copy()->startOfDay(),
                            ),
                        ) + 1
                    )),
                'synced_records' => $adSpends->count(),
                'last_synced_at' => $adSpends->max('synced_at')?->toIso8601String(),
            ],
        ];
    }
}

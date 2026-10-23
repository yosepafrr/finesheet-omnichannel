<?php

namespace App\Services;

use App\Models\MarketplaceAdSpend;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProfitAnalyticsService
{
    public function __construct(
        private readonly OrderEscrowService $escrowService,
        private readonly ProfitCorrectionService $correctionService,
    ) {}

    /**
     * @param  Collection<int, Order>  $orders
     * @param  Collection<int, array{
     *     hpp_amount: float,
     *     escrow_amount: float,
     *     profit_amount: float,
     *     sale_escrow_amount: float,
     *     sale_profit_amount: float,
     *     has_missing_hpp: bool
     * }>  $financialSummaries
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
        $events = $this->financialEvents($orders, $financialSummaries, $period);
        $groupedEvents = $events->groupBy('bucket_key');
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
            $bucketEvents = $groupedEvents->get($bucketKey, collect());
            $bucketSales = $bucketEvents->where('kind', 'sale');
            $bucketCorrections = $bucketEvents->where('kind', 'correction');
            $bucketAdSpends = $groupedAdSpends->get($bucketKey, collect());
            $grossProfit = (float) $bucketEvents->sum('profit_amount');
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
                'order_count' => $bucketSales->count(),
                'correction_count' => $bucketCorrections->count(),
                'hpp_amount' => (float) $bucketEvents->sum('hpp_amount'),
                'escrow_amount' => (float) $bucketEvents->sum('escrow_amount'),
                'gross_profit_amount' => $grossProfit,
                'correction_profit_amount' => (float) $bucketCorrections->sum('profit_amount'),
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
                'correction_count' => (int) $points->sum('correction_count'),
                'hpp_amount' => (float) $points->sum('hpp_amount'),
                'escrow_amount' => (float) $points->sum('escrow_amount'),
                'gross_profit_amount' => (float) $points->sum('gross_profit_amount'),
                'correction_profit_amount' => (float) $points->sum('correction_profit_amount'),
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

    /**
     * @param  Collection<int, Order>  $orders
     * @param  Collection<int, array<string, mixed>>  $financialSummaries
     * @param  Collection<int, MarketplaceAdSpend>  $adSpends
     * @param  array{key: string, start: Carbon, end: Carbon, granularity: string}  $period
     */
    public function breakdown(
        Collection $orders,
        Collection $financialSummaries,
        array $period,
        Collection $adSpends,
    ): array {
        $financialEvents = $this->financialEvents($orders, $financialSummaries, $period)
            ->map(fn (array $event) => collect($event)->except('bucket_key')->all());
        $adSpendEvents = $adSpends->map(function (MarketplaceAdSpend $spend) {
            $amount = (float) $spend->amount;

            return [
                'id' => 'ad-spend:'.$spend->id,
                'kind' => 'ad_spend',
                'type' => 'ad_spend',
                'label' => 'Iklan Shopee',
                'occurred_at' => $spend->spend_date->copy()->startOfDay()->toIso8601String(),
                'order_id' => null,
                'order_sn' => null,
                'store_id' => $spend->store_id,
                'store_name' => $spend->store?->store_name,
                'platform' => $spend->platform,
                'status' => null,
                'hpp_amount' => 0.0,
                'escrow_amount' => 0.0,
                'profit_amount' => -$amount,
                'affected_ratio' => null,
                'reason' => 'Pengeluaran iklan harian toko',
                'item_summary' => null,
            ];
        });
        $events = $financialEvents
            ->concat($adSpendEvents)
            ->sortByDesc(fn (array $event) => $event['occurred_at'])
            ->values();

        return [
            'period' => $period['key'],
            'start_date' => $period['start']->toDateString(),
            'end_date' => $period['end']->toDateString(),
            'granularity' => $period['granularity'],
            'totals' => [
                'sale_count' => $financialEvents->where('kind', 'sale')->count(),
                'correction_count' => $financialEvents->where('kind', 'correction')->count(),
                'ad_spend_count' => $adSpendEvents->count(),
                'hpp_amount' => (float) $financialEvents->sum('hpp_amount'),
                'escrow_amount' => (float) $financialEvents->sum('escrow_amount'),
                'gross_profit_amount' => (float) $financialEvents->sum('profit_amount'),
                'ad_spend_amount' => (float) $adSpends->sum('amount'),
                'profit_amount' => (float) $events->sum('profit_amount'),
            ],
            'events' => $events,
        ];
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  Collection<int, array<string, mixed>>  $financialSummaries
     * @param  array{key: string, start: Carbon, end: Carbon, granularity: string}  $period
     * @return Collection<int, array<string, mixed>>
     */
    private function financialEvents(
        Collection $orders,
        Collection $financialSummaries,
        array $period,
    ): Collection {
        $events = collect();

        foreach ($orders as $order) {
            $summary = $financialSummaries->get($order->id);
            if (! $summary) {
                continue;
            }

            $saleDate = $this->date($order->order_time ?? $order->created_at);
            $correction = $this->correctionService->forOrder($order, $summary);
            $isSameDayFullReversal = $saleDate
                && $correction
                && (float) $correction['affected_ratio'] >= 0.9999
                && $saleDate->isSameDay($correction['occurred_at']);

            if (! $isSameDayFullReversal
                && $saleDate
                && $this->within($saleDate, $period)
                && $this->escrowService->isProfitAnalyticsSaleEligible($order)) {
                $events->push($this->orderEvent(
                    $order,
                    $saleDate,
                    $period,
                    'sale',
                    'sale',
                    'Pesanan',
                    (float) $summary['hpp_amount'],
                    (float) $summary['sale_escrow_amount'],
                    (float) $summary['sale_profit_amount'],
                    null,
                    null,
                ));
            }

            if ($isSameDayFullReversal
                || ! $correction
                || ! $this->within($correction['occurred_at'], $period)) {
                continue;
            }

            $events->push($this->orderEvent(
                $order,
                $correction['occurred_at'],
                $period,
                'correction',
                $correction['type'],
                $correction['label'],
                $correction['hpp_amount'],
                $correction['escrow_amount'],
                $correction['profit_amount'],
                $correction['affected_ratio'],
                $correction['reason'],
            ));
        }

        return $events;
    }

    /** @param array{key: string, start: Carbon, end: Carbon, granularity: string} $period */
    private function orderEvent(
        Order $order,
        Carbon $occurredAt,
        array $period,
        string $kind,
        string $type,
        string $label,
        float $hppAmount,
        float $escrowAmount,
        float $profitAmount,
        ?float $affectedRatio,
        ?string $reason,
    ): array {
        return [
            'id' => $kind.':'.$order->id,
            'bucket_key' => $period['granularity'] === 'month'
                ? $occurredAt->format('Y-m')
                : $occurredAt->format('Y-m-d'),
            'kind' => $kind,
            'type' => $type,
            'label' => $label,
            'occurred_at' => $occurredAt->toIso8601String(),
            'order_id' => $order->id,
            'order_sn' => $order->order_sn,
            'store_id' => $order->store_id,
            'store_name' => $order->store?->store_name,
            'platform' => $order->platform,
            'status' => $order->order_status,
            'hpp_amount' => $hppAmount,
            'escrow_amount' => $escrowAmount,
            'profit_amount' => $profitAmount,
            'affected_ratio' => $affectedRatio,
            'reason' => $reason,
            'item_summary' => $this->itemSummary($order),
        ];
    }

    private function itemSummary(Order $order): ?array
    {
        $order->loadMissing('orderProducts');
        $firstItem = $order->orderProducts->first();
        if (! $firstItem) {
            return null;
        }

        return [
            'product_name' => $firstItem->product_name,
            'variant_name' => $firstItem->model_name,
            'image' => $firstItem->image,
            'quantity' => (int) $order->orderProducts->sum('quantity_purchased'),
            'additional_items' => max(0, $order->orderProducts->count() - 1),
        ];
    }

    /** @param array{start: Carbon, end: Carbon} $period */
    private function within(Carbon $date, array $period): bool
    {
        return $date->betweenIncluded($period['start'], $period['end']);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone', 'Asia/Jakarta'));
        } catch (\Throwable) {
            return null;
        }
    }
}

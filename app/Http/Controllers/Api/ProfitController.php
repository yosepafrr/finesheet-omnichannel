<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAdSpend;
use App\Models\Order;
use App\Services\OrderEscrowService;
use App\Services\OrderProfitService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ProfitController extends Controller
{
    public function index(
        Request $request,
        OrderEscrowService $escrowService,
        OrderProfitService $profitService,
    ) {
        $request->validate([
            'analytics_period' => 'nullable|in:today,date,range,last_3_days,last_7_days,last_14_days,last_30_days,last_60_days,last_90_days,month,year',
            'analytics_date' => 'nullable|date_format:Y-m-d',
            'analytics_start' => 'nullable|date_format:Y-m-d',
            'analytics_end' => 'nullable|date_format:Y-m-d',
            'analytics_month' => 'nullable|date_format:Y-m',
            'analytics_year' => 'nullable|integer|min:2000|max:2100',
            'include_analytics' => 'nullable|boolean',
            'store_id' => 'nullable|integer',
        ]);

        $user = Auth::user();
        $allStores = $user->stores()->orderBy('store_name')->get();
        $selectedStoreId = $request->filled('store_id') ? (int) $request->query('store_id') : null;

        if ($selectedStoreId !== null && ! $allStores->contains('id', $selectedStoreId)) {
            throw ValidationException::withMessages([
                'store_id' => 'Toko yang dipilih tidak tersedia.',
            ]);
        }

        $analyticsStores = $selectedStoreId === null
            ? $allStores
            : $allStores->where('id', $selectedStoreId)->values();
        $allStoreIds = $allStores->pluck('id');
        $analyticsStoreIds = $analyticsStores->pluck('id');
        $analyticsPeriod = $this->resolveAnalyticsPeriod($request);
        $includeAnalytics = filter_var($request->query('include_analytics', true), FILTER_VALIDATE_BOOLEAN);

        $realtimeOrders = Order::with(['returns', 'packages', 'orderProducts', 'store'])
            ->whereIn('store_id', $allStoreIds)
            ->whereIn('order_status', $escrowService->realtimeStatuses())
            ->orderByDesc('order_time')
            ->get();
        $analyticsOrders = $includeAnalytics
            ? Order::with(['returns', 'packages', 'orderProducts', 'store'])
                ->whereIn('store_id', $analyticsStoreIds)
                ->whereIn('order_status', $escrowService->profitAnalyticsStatuses())
                ->whereBetween('order_time', [$analyticsPeriod['start'], $analyticsPeriod['end']])
                ->orderBy('order_time')
                ->get()
            : collect();
        $analyticsAdSpends = $includeAnalytics
            ? MarketplaceAdSpend::query()
                ->where('user_id', $user->id)
                ->whereIn('store_id', $analyticsStoreIds)
                ->whereRaw('LOWER(platform) = ?', ['shopee'])
                ->whereBetween('spend_date', [
                    $analyticsPeriod['start']->toDateString(),
                    $analyticsPeriod['end']->toDateString(),
                ])
                ->orderBy('spend_date')
                ->get()
            : collect();

        $allOrders = $realtimeOrders
            ->concat($analyticsOrders)
            ->unique('id')
            ->values();
        $financialSummaries = $profitService->summaries($allOrders);
        $ordersByStore = $realtimeOrders->groupBy('store_id');

        $includePerluDikirim = filter_var($request->query('include_perlu_dikirim', true), FILTER_VALIDATE_BOOLEAN);
        $includeDikirim = filter_var($request->query('include_dikirim', true), FILTER_VALIDATE_BOOLEAN);
        $includeReturn = filter_var($request->query('include_return', false), FILTER_VALIDATE_BOOLEAN);

        $includedCategories = array_keys(array_filter([
            OrderEscrowService::CATEGORY_NEEDS_SHIPPING => $includePerluDikirim,
            OrderEscrowService::CATEGORY_SHIPPED => $includeDikirim,
            OrderEscrowService::CATEGORY_RETURN_CANCEL => $includeReturn,
        ]));

        $storeSummaries = $allStores->map(function ($store) use (
            $ordersByStore,
            $escrowService,
            $financialSummaries,
            $includedCategories,
        ) {
            $storeOrders = $ordersByStore->get($store->id, collect());
            $escrowSummary = $escrowService->summarize($storeOrders, $includedCategories);
            $includedOrders = $storeOrders->filter(function (Order $order) use ($escrowService, $includedCategories) {
                $category = $escrowService->category($order);

                return $category !== null && in_array($category, $includedCategories, true);
            });
            $includedFinance = $includedOrders->map(
                fn (Order $order) => $financialSummaries->get($order->id),
            )->filter();

            return [
                'id' => $store->id,
                'store_name' => $store->store_name,
                'platform' => $store->platform,
                'escrow' => (float) $includedFinance->sum('escrow_amount'),
                'hpp' => (float) $includedFinance->sum('hpp_amount'),
                'profit' => (float) $includedFinance->sum('profit_amount'),
                'included_order_count' => $includedOrders->count(),
                'missing_hpp_order_count' => $includedFinance->where('has_missing_hpp', true)->count(),
                'status_counts' => $escrowSummary['status_counts'],
                'status_escrow' => $escrowSummary['status_escrow'],
            ];
        });

        $totalEscrowAmount = (float) $storeSummaries->sum('escrow');
        $totalHppAmount = (float) $storeSummaries->sum('hpp');
        $totalProfitAmount = (float) $storeSummaries->sum('profit');
        $totalOrderCount = (int) $storeSummaries->sum('included_order_count');

        $storeSummaries = $storeSummaries
            ->map(function (array $store) use ($totalEscrowAmount) {
                $store['percent'] = $totalEscrowAmount > 0
                    ? round(($store['escrow'] / $totalEscrowAmount) * 100, 1)
                    : 0;

                return $store;
            })
            ->sortByDesc('escrow')
            ->values();

        $response = [
            'total_escrow_amount' => $totalEscrowAmount,
            'total_hpp_amount' => $totalHppAmount,
            'total_profit_amount' => $totalProfitAmount,
            'total_order_count' => $totalOrderCount,
            'missing_hpp_order_count' => (int) $storeSummaries->sum('missing_hpp_order_count'),
            'stores' => $storeSummaries,
            'store_options' => $allStores->map(fn ($store) => [
                'id' => $store->id,
                'store_name' => $store->store_name,
                'platform' => $store->platform,
            ])->values(),
            'selected_store_id' => $selectedStoreId,
        ];

        if ($includeAnalytics) {
            $response['analytics'] = $this->buildAnalytics(
                $analyticsOrders,
                $financialSummaries,
                $escrowService,
                $analyticsPeriod,
                $analyticsAdSpends,
                $analyticsStores->filter(fn ($store) => strtolower((string) $store->platform) === 'shopee')->count(),
            );
        }

        return response()->json($response);
    }

    /**
     * @return array{key: string, start: Carbon, end: Carbon, granularity: string}
     */
    private function resolveAnalyticsPeriod(Request $request): array
    {
        $timezone = 'Asia/Jakarta';
        $today = now($timezone)->startOfDay();
        $key = (string) $request->query('analytics_period', 'last_3_days');

        [$start, $end, $granularity] = match ($key) {
            'today' => [$today->copy(), $today->copy()->endOfDay(), 'day'],
            'date' => $this->singleDayPeriod(
                (string) ($request->query('analytics_date') ?: $today->toDateString()),
                $timezone,
            ),
            'range' => $this->customRangePeriod($request, $timezone),
            'last_3_days' => [$today->copy()->subDays(2), $today->copy()->endOfDay(), 'day'],
            'last_7_days' => [$today->copy()->subDays(6), $today->copy()->endOfDay(), 'day'],
            'last_14_days' => [$today->copy()->subDays(13), $today->copy()->endOfDay(), 'day'],
            'last_60_days' => [$today->copy()->subDays(59), $today->copy()->endOfDay(), 'day'],
            'last_90_days' => [$today->copy()->subDays(89), $today->copy()->endOfDay(), 'day'],
            'month' => $this->monthPeriod(
                (string) ($request->query('analytics_month') ?: $today->format('Y-m')),
                $timezone,
            ),
            'year' => $this->yearPeriod(
                (int) ($request->query('analytics_year') ?: $today->year),
                $timezone,
            ),
            default => [$today->copy()->subDays(2), $today->copy()->endOfDay(), 'day'],
        };

        return compact('key', 'start', 'end', 'granularity');
    }

    /** @return array{Carbon, Carbon, string} */
    private function singleDayPeriod(string $date, string $timezone): array
    {
        $day = Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();

        return [$day, $day->copy()->endOfDay(), 'day'];
    }

    /** @return array{Carbon, Carbon, string} */
    private function customRangePeriod(Request $request, string $timezone): array
    {
        $fallback = now($timezone)->startOfDay();
        $start = Carbon::createFromFormat(
            'Y-m-d',
            (string) ($request->query('analytics_start') ?: $fallback->copy()->subDays(29)->toDateString()),
            $timezone,
        )->startOfDay();
        $end = Carbon::createFromFormat(
            'Y-m-d',
            (string) ($request->query('analytics_end') ?: $fallback->toDateString()),
            $timezone,
        )->endOfDay();

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'analytics_end' => 'Tanggal akhir harus sama atau setelah tanggal mulai.',
            ]);
        }

        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages([
                'analytics_end' => 'Rentang analitik maksimal 366 hari.',
            ]);
        }

        return [$start, $end, 'day'];
    }

    /** @return array{Carbon, Carbon, string} */
    private function monthPeriod(string $month, string $timezone): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01', $timezone)->startOfMonth();

        return [$start, $start->copy()->endOfMonth(), 'day'];
    }

    /** @return array{Carbon, Carbon, string} */
    private function yearPeriod(int $year, string $timezone): array
    {
        $start = Carbon::create($year, 1, 1, 0, 0, 0, $timezone)->startOfYear();

        return [$start, $start->copy()->endOfYear(), 'month'];
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  Collection<int, array{hpp_amount: float, escrow_amount: float, profit_amount: float, has_missing_hpp: bool}>  $financialSummaries
     * @param  Collection<int, MarketplaceAdSpend>  $adSpends
     * @param  array{key: string, start: Carbon, end: Carbon, granularity: string}  $period
     */
    private function buildAnalytics(
        Collection $orders,
        Collection $financialSummaries,
        OrderEscrowService $escrowService,
        array $period,
        Collection $adSpends,
        int $shopeeStoreCount,
    ): array {
        $eligibleOrders = $orders->filter(
            fn (Order $order) => $escrowService->isProfitAnalyticsEligible($order),
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

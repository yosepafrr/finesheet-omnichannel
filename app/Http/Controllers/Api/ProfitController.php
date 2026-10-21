<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAdSpend;
use App\Models\Order;
use App\Services\OrderEscrowService;
use App\Services\OrderProfitService;
use App\Services\ProfitAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ProfitController extends Controller
{
    public function index(
        Request $request,
        OrderEscrowService $escrowService,
        OrderProfitService $profitService,
        ProfitAnalyticsService $profitAnalyticsService,
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
            ? $this->analyticsOrders(
                $analyticsStoreIds,
                $analyticsPeriod['start'],
                $analyticsPeriod['end'],
            )
            : collect();
        $analyticsAdSpends = $includeAnalytics
            ? $this->analyticsAdSpends(
                $user->id,
                $analyticsStoreIds,
                $analyticsPeriod['start'],
                $analyticsPeriod['end'],
            )
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
            $response['analytics'] = $profitAnalyticsService->build(
                $analyticsOrders,
                $financialSummaries,
                $analyticsPeriod,
                $analyticsAdSpends,
                $analyticsStores->filter(fn ($store) => strtolower((string) $store->platform) === 'shopee')->count(),
            );
        }

        return response()->json($response);
    }

    public function breakdown(
        Request $request,
        OrderProfitService $profitService,
        ProfitAnalyticsService $profitAnalyticsService,
    ) {
        $validated = $request->validate([
            'bucket' => 'required|string',
            'granularity' => 'required|in:day,month',
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

        $stores = $selectedStoreId === null
            ? $allStores
            : $allStores->where('id', $selectedStoreId)->values();
        $period = $this->breakdownPeriod($validated['bucket'], $validated['granularity']);
        $orders = $this->analyticsOrders($stores->pluck('id'), $period['start'], $period['end']);
        $financialSummaries = $profitService->summaries($orders);
        $adSpends = $this->analyticsAdSpends(
            $user->id,
            $stores->pluck('id'),
            $period['start'],
            $period['end'],
        );

        return response()->json($profitAnalyticsService->breakdown(
            $orders,
            $financialSummaries,
            $period,
            $adSpends,
        ));
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
            'last_30_days' => [$today->copy()->subDays(29), $today->copy()->endOfDay(), 'day'],
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

    private function breakdownPeriod(string $bucket, string $granularity): array
    {
        $timezone = 'Asia/Jakarta';

        try {
            $start = $granularity === 'month'
                ? Carbon::createFromFormat('Y-m-d', $bucket.'-01', $timezone)->startOfMonth()
                : Carbon::createFromFormat('Y-m-d', $bucket, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'bucket' => 'Periode rincian tidak valid.',
            ]);
        }

        return [
            'key' => $bucket,
            'start' => $start,
            'end' => $granularity === 'month'
                ? $start->copy()->endOfMonth()
                : $start->copy()->endOfDay(),
            'granularity' => $granularity,
        ];
    }

    private function analyticsOrders($storeIds, Carbon $start, Carbon $end)
    {
        return Order::with(['returns.items', 'packages', 'orderProducts', 'store'])
            ->whereIn('store_id', $storeIds)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('order_time', [$start, $end])
                    ->orWhereHas('returns', function ($returnQuery) use ($start, $end) {
                        $returnQuery->where(function ($dateQuery) use ($start, $end) {
                            $dateQuery->whereBetween('created_at_platform', [$start, $end])
                                ->orWhere(function ($fallbackQuery) use ($start, $end) {
                                    $fallbackQuery->whereNull('created_at_platform')
                                        ->whereBetween('created_at', [$start, $end]);
                                });
                        });
                    })
                    ->orWhereHas('packages', function ($packageQuery) use ($start, $end) {
                        $packageQuery->where('normalized_logistics_status', 'DELIVERY_FAILED')
                            ->where(function ($dateQuery) use ($start, $end) {
                                $dateQuery->whereBetween('failed_at', [$start, $end])
                                    ->orWhere(function ($fallbackQuery) use ($start, $end) {
                                        $fallbackQuery->whereNull('failed_at')
                                            ->whereBetween('updated_at', [$start, $end]);
                                    });
                            });
                    });
            })
            ->orderBy('order_time')
            ->get();
    }

    private function analyticsAdSpends(int $userId, $storeIds, Carbon $start, Carbon $end)
    {
        return MarketplaceAdSpend::query()
            ->with('store')
            ->where('user_id', $userId)
            ->whereIn('store_id', $storeIds)
            ->whereRaw('LOWER(platform) = ?', ['shopee'])
            ->whereDate('spend_date', '>=', $start->toDateString())
            ->whereDate('spend_date', '<=', $end->toDateString())
            ->orderBy('spend_date')
            ->get();
    }
}

<?php

namespace App\Services;

use App\Models\MarketplaceAdSpend;
use App\Models\Order;
use App\Models\Store;
use Carbon\Carbon;

class DashboardService
{
    private const ALLOWED_TREND_DAYS = [7, 14, 30];

    public function __construct(
        private readonly OrderEscrowService $escrowService,
        private readonly OrderProfitService $profitService,
        private readonly ProfitAnalyticsService $profitAnalyticsService,
    ) {}

    public function getStats(int $days = 7): array
    {
        $days = in_array($days, self::ALLOWED_TREND_DAYS, true) ? $days : 7;
        $timezone = 'Asia/Jakarta';
        $today = now($timezone)->startOfDay();
        $start = $today->copy()->subDays($days - 1);
        $end = $today->copy()->endOfDay();

        $user = auth()->user();
        $stores = Store::where('user_id', $user->id)->get();
        $storeIds = $stores->pluck('id');
        $activeStoreCount = $stores->filter(function (Store $store) {
            return $store->shop_expired_at
                && Carbon::parse($store->shop_expired_at)->isFuture()
                && ! empty($store->refresh_token);
        })->count();

        $perluDikirimCount = Order::query()
            ->whereIn('store_id', $storeIds)
            ->whereIn('order_status', $this->escrowService->needsShippingStatuses())
            ->count();

        $rangeOrders = Order::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('order_time', [$start, $end])
            ->orderBy('order_time')
            ->get();
        $analyticsOrders = Order::with(['returns', 'packages', 'orderProducts', 'store'])
            ->whereIn('store_id', $storeIds)
            ->whereIn('order_status', $this->escrowService->profitAnalyticsStatuses())
            ->whereBetween('order_time', [$start, $end])
            ->orderBy('order_time')
            ->get();
        $financialSummaries = $this->profitService->summaries($analyticsOrders);
        $adSpends = MarketplaceAdSpend::query()
            ->where('user_id', $user->id)
            ->whereIn('store_id', $storeIds)
            ->whereRaw('LOWER(platform) = ?', ['shopee'])
            ->whereDate('spend_date', '>=', $start->toDateString())
            ->whereDate('spend_date', '<=', $end->toDateString())
            ->orderBy('spend_date')
            ->get();
        $period = [
            'key' => "last_{$days}_days",
            'start' => $start,
            'end' => $end,
            'granularity' => 'day',
        ];
        $analytics = $this->profitAnalyticsService->build(
            $analyticsOrders,
            $financialSummaries,
            $period,
            $adSpends,
            $stores->filter(fn (Store $store) => strtolower((string) $store->platform) === 'shopee')->count(),
        );

        $todayKey = $today->toDateString();
        $todayOrders = $analyticsOrders->filter(function (Order $order) use ($todayKey) {
            return $this->escrowService->isProfitAnalyticsEligible($order)
                && $order->order_time?->copy()->setTimezone('Asia/Jakarta')->toDateString() === $todayKey;
        });
        $todayPoint = collect($analytics['points'])->firstWhere('key', $todayKey);

        $ordersByDate = $rangeOrders->groupBy(
            fn (Order $order) => $order->order_time?->copy()->setTimezone($timezone)->toDateString(),
        );
        $orderTrend = collect($analytics['points'])->map(function (array $point) use ($ordersByDate, $timezone) {
            return [
                'date' => Carbon::createFromFormat('Y-m-d', $point['key'], $timezone)->format('d M'),
                'Total Pesanan' => $ordersByDate->get($point['key'], collect())->count(),
            ];
        })->values();
        $profitTrend = collect($analytics['points'])->map(function (array $point) use ($timezone) {
            return [
                'date' => Carbon::createFromFormat('Y-m-d', $point['key'], $timezone)->format('d M'),
                'profit_amount' => (float) $point['profit_amount'],
            ];
        })->values();
        $platformDistribution = $rangeOrders
            ->groupBy('platform')
            ->map(fn ($platformOrders, $platform) => [
                'name' => ucfirst((string) $platform),
                'value' => $platformOrders->count(),
            ])
            ->values();

        return [
            'perlu_dikirim_count' => $perluDikirimCount,
            'gmv_today' => (float) $todayOrders->sum(fn (Order $order) => $this->grossOrderValue($order)),
            'profit_today' => (float) ($todayPoint['profit_amount'] ?? 0),
            'order_trend' => $orderTrend,
            'profit_trend' => $profitTrend,
            'platform_distribution' => $platformDistribution,
            'store_count' => $stores->count(),
            'active_store_count' => $activeStoreCount,
        ];
    }

    private function grossOrderValue(Order $order): float
    {
        $grossAmount = (float) ($order->order_selling_price ?? 0);

        if ($grossAmount > 0) {
            return $grossAmount;
        }

        return (float) $order->orderProducts->sum(
            fn ($item) => (float) ($item->price ?? 0) * (int) ($item->quantity_purchased ?? 0),
        );
    }
}

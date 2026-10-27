<?php

namespace App\Services;

use App\Models\MarketplaceAdSpend;
use App\Models\Order;
use App\Models\PayableEvent;
use App\Models\PayablePayment;
use App\Models\PayablePeriod;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CashflowService
{
    public function __construct(
        private readonly OrderProfitService $orderProfitService,
        private readonly ProfitAnalyticsService $profitAnalyticsService,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function supplierPayables(int $userId): Collection
    {
        $periodIds = PayablePeriod::query()
            ->where('user_id', $userId)
            ->whereIn('payment_status', ['UNPAID', 'PARTIAL'])
            ->where('is_closed', false)
            ->pluck('id');

        if ($periodIds->isEmpty()) {
            return collect();
        }

        $eventTotals = PayableEvent::query()
            ->where('user_id', $userId)
            ->whereIn('payable_period_id', $periodIds)
            ->whereNotNull('supplier_id')
            ->selectRaw('supplier_id, SUM(amount) AS amount')
            ->groupBy('supplier_id')
            ->pluck('amount', 'supplier_id');
        $paymentTotals = PayablePayment::query()
            ->where('user_id', $userId)
            ->whereIn('payable_period_id', $periodIds)
            ->whereNotNull('supplier_id')
            ->selectRaw('supplier_id, SUM(amount) AS amount')
            ->groupBy('supplier_id')
            ->pluck('amount', 'supplier_id');
        $supplierIds = $eventTotals->keys()->merge($paymentTotals->keys())->unique()->values();
        $suppliers = Supplier::query()
            ->where('user_id', $userId)
            ->whereIn('id', $supplierIds)
            ->get()
            ->keyBy('id');

        return $supplierIds->map(function ($supplierId) use ($eventTotals, $paymentTotals, $suppliers) {
            $amount = max(
                0,
                (float) ($eventTotals[$supplierId] ?? 0) - (float) ($paymentTotals[$supplierId] ?? 0),
            );

            return [
                'supplier_id' => (int) $supplierId,
                'supplier_name' => $suppliers->get($supplierId)?->name ?: 'Supplier tidak diketahui',
                'amount' => $amount,
            ];
        })->filter(fn (array $item) => $item['amount'] > 0)
            ->sortByDesc('amount')
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function dailyProfit(User $user, Carbon $start, Carbon $end): Collection
    {
        $stores = $user->stores()->get();
        if ($stores->isEmpty()) {
            return collect();
        }

        $storeIds = $stores->pluck('id');
        $orders = Order::query()
            ->with(['returns.items', 'packages', 'orderProducts', 'store'])
            ->whereIn('store_id', $storeIds)
            ->whereBetween('order_time', [$start, $end])
            ->orderBy('order_time')
            ->get();
        $financialSummaries = $this->orderProfitService->summaries($orders);
        $adSpends = MarketplaceAdSpend::query()
            ->where('user_id', $user->id)
            ->whereIn('store_id', $storeIds)
            ->whereDate('spend_date', '>=', $start->toDateString())
            ->whereDate('spend_date', '<=', $end->toDateString())
            ->orderBy('spend_date')
            ->get();
        $period = [
            'key' => 'cashflow',
            'start' => $start,
            'end' => $end,
            'granularity' => 'day',
        ];
        $shopeeStoreCount = $stores
            ->filter(fn ($store) => str_contains(strtolower((string) $store->platform), 'shopee'))
            ->count();
        $analytics = $this->profitAnalyticsService->build(
            $orders,
            $financialSummaries,
            $period,
            $adSpends,
            $shopeeStoreCount,
        );

        return collect($analytics['points'])
            ->filter(fn (array $point) => abs((float) $point['profit_amount']) >= 0.01)
            ->map(function (array $point) {
                $profit = (float) $point['profit_amount'];

                return [
                    'id' => 'profit:'.$point['key'],
                    'source' => 'daily_profit',
                    'direction' => $profit >= 0 ? 'income' : 'expense',
                    'category' => 'Profit penjualan',
                    'description' => 'Profit harian (otomatis)',
                    'amount' => abs($profit),
                    'occurred_at' => Carbon::parse($point['key'], config('app.timezone'))->startOfDay()->toIso8601String(),
                    'account' => null,
                    'is_read_only' => true,
                    'meta' => [
                        'order_count' => (int) $point['order_count'],
                        'hpp_amount' => (float) $point['hpp_amount'],
                        'escrow_amount' => (float) $point['escrow_amount'],
                        'ad_spend_amount' => (float) $point['ad_spend_amount'],
                    ],
                ];
            })->values();
    }
}

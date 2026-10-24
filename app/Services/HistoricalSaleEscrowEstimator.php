<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class HistoricalSaleEscrowEstimator
{
    private const CORRECTION_COMPONENTS = [
        'refund_amount',
        'refund_amount_to_buyer',
        'reverse_shipping_fee',
        'reverse_shipping_fee_sst',
        'seller_return_refund',
    ];

    private const MAX_SAMPLES = 50;

    public function __construct(
        private readonly OrderEscrowService $escrowService,
        private readonly OrderFinancialBreakdownService $financialBreakdownService,
        private readonly TiktokEscrowAmountResolver $tiktokEscrowResolver,
    ) {}

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, array{
     *     amount: float,
     *     source: string,
     *     is_estimated: bool,
     *     sample_size: int,
     *     ratio: float|null
     * }>
     */
    public function estimates(Collection $orders, bool $recalculateEstimated = false): Collection
    {
        (new EloquentCollection($orders->all()))->loadMissing([
            'store',
            'orderProducts',
            'returns.items',
            'packages',
        ]);

        $targets = $orders
            ->filter(fn (Order $order) => $this->needsEstimate($order, $recalculateEstimated))
            ->values();

        if ($targets->isEmpty()) {
            return collect();
        }

        $resolved = collect();
        $ratioTargets = collect();

        foreach ($targets as $order) {
            $platformAmount = $this->historicalPlatformAmount($order);
            if ($platformAmount !== null) {
                $resolved->put($order->id, $this->result(
                    $platformAmount,
                    'platform_history',
                    false,
                ));

                continue;
            }

            $ratioTargets->push($order);
        }

        if ($ratioTargets->isEmpty()) {
            return $resolved;
        }

        $sampleIndex = $this->sampleIndex($ratioTargets);

        foreach ($ratioTargets as $order) {
            $grossAmount = $this->grossAmount($order);
            if ($grossAmount <= 0) {
                continue;
            }

            $ratioEstimate = $this->ratioEstimate($order, $sampleIndex);
            if ($ratioEstimate !== null) {
                $resolved->put($order->id, $this->result(
                    round($grossAmount * $ratioEstimate['ratio'], 2),
                    $ratioEstimate['source'],
                    true,
                    $ratioEstimate['sample_size'],
                    $ratioEstimate['ratio'],
                ));

                continue;
            }

            $feeEstimate = $this->feeBreakdownEstimate($order, $grossAmount);
            if ($feeEstimate !== null) {
                $resolved->put($order->id, $this->result(
                    $feeEstimate,
                    'estimated_fee_breakdown',
                    true,
                ));

                continue;
            }

            // The gross fallback is intentionally marked as estimated. It is
            // preferable to silently treating a terminal zero as the original
            // sale settlement, and remains visible for manual review.
            $resolved->put($order->id, $this->result(
                $grossAmount,
                'estimated_gross_fallback',
                true,
            ));
        }

        return $resolved;
    }

    private function needsEstimate(Order $order, bool $recalculateEstimated): bool
    {
        if (! $this->escrowService->hasReturnOrFailedDelivery($order)) {
            return false;
        }

        if ($this->escrowService->isPreShipmentCancellation($order)) {
            return false;
        }

        if (is_numeric($order->sale_escrow_amount)
            && (float) $order->sale_escrow_amount > 0
            && (! $recalculateEstimated || ! $order->sale_escrow_is_estimated)) {
            return false;
        }

        if ($this->escrowService->amount($order) > 0) {
            return false;
        }

        return ! is_numeric($order->escrow_amount_after_adjustment)
            || (float) $order->escrow_amount_after_adjustment <= 0;
    }

    /** @return array<string, Collection<int, array{ratio: float, timestamp: int}>> */
    private function sampleIndex(Collection $targets): array
    {
        $userIds = $targets->pluck('store.user_id')->filter()->unique()->values();
        $platforms = $targets->pluck('platform')
            ->filter()
            ->map(fn ($platform) => strtolower((string) $platform))
            ->unique()
            ->values();
        $storeIds = Store::query()
            ->whereIn('user_id', $userIds)
            ->get(['id', 'platform'])
            ->filter(fn (Store $store) => $platforms->contains(
                strtolower(trim((string) $store->platform)),
            ))
            ->pluck('id');
        $dates = $targets->map(fn (Order $order) => $this->date($order->order_time ?? $order->created_at))
            ->filter()
            ->sortBy(fn (Carbon $date) => $date->getTimestamp())
            ->values();
        $start = $dates->first()?->copy()->subDays(365);
        $end = $dates->last()?->copy()->addDays(365);

        if ($storeIds->isEmpty() || $platforms->isEmpty() || ! $start || ! $end) {
            return [];
        }

        $candidates = Order::query()
            ->with(['store', 'orderProducts'])
            ->whereIn('store_id', $storeIds)
            ->whereIn('order_status', $this->escrowService->profitAnalyticsStatuses())
            ->whereBetween('order_time', [$start, $end])
            ->whereDoesntHave('returns')
            ->whereDoesntHave('packages', fn ($query) => $query
                ->where('normalized_logistics_status', 'DELIVERY_FAILED'))
            ->where(function ($query) {
                $query->where('sale_escrow_amount', '>', 0)
                    ->orWhere('escrow_amount', '>', 0)
                    ->orWhere('escrow_amount_after_adjustment', '>', 0);
            })
            ->orderByDesc('order_time')
            ->limit(10000)
            ->get();

        $index = [];
        foreach ($candidates as $candidate) {
            $grossAmount = $this->grossAmount($candidate);
            $knownAmount = $this->knownPlatformAmount($candidate);
            if ($grossAmount <= 0 || $knownAmount === null) {
                continue;
            }

            $ratio = $knownAmount / $grossAmount;
            if ($ratio < 0.1 || $ratio > 1.25) {
                continue;
            }

            $entry = [
                'ratio' => $ratio,
                'timestamp' => $this->date($candidate->order_time ?? $candidate->created_at)?->getTimestamp() ?? 0,
            ];
            $storeKey = (string) $candidate->store_id;
            $platformKey = $this->platformKey($candidate);
            $this->pushSample($index, 'store:'.$storeKey, $entry);
            $this->pushSample($index, 'platform:'.$platformKey, $entry);

            foreach ($this->identifiers($candidate, 'sku') as $sku) {
                $this->pushSample($index, 'sku:'.$storeKey.':'.$sku, $entry);
            }
            foreach ($this->identifiers($candidate, 'product_id') as $productId) {
                $this->pushSample($index, 'product:'.$storeKey.':'.$productId, $entry);
            }
        }

        return $index;
    }

    /**
     * @param  array<string, Collection<int, array{ratio: float, timestamp: int}>>  $index
     * @return array{ratio: float, source: string, sample_size: int}|null
     */
    private function ratioEstimate(Order $order, array $index): ?array
    {
        $storeKey = (string) $order->store_id;
        $targetTimestamp = $this->date($order->order_time ?? $order->created_at)?->getTimestamp() ?? 0;
        $choices = [];

        foreach ($this->identifiers($order, 'sku') as $sku) {
            $choices[] = ['key' => 'sku:'.$storeKey.':'.$sku, 'source' => 'estimated_sku_ratio'];
        }
        foreach ($this->identifiers($order, 'product_id') as $productId) {
            $choices[] = ['key' => 'product:'.$storeKey.':'.$productId, 'source' => 'estimated_product_ratio'];
        }
        $choices[] = ['key' => 'store:'.$storeKey, 'source' => 'estimated_store_ratio'];
        $choices[] = ['key' => 'platform:'.$this->platformKey($order), 'source' => 'estimated_platform_ratio'];

        foreach ($choices as $choice) {
            $samples = $index[$choice['key']] ?? collect();
            if ($samples->isEmpty()) {
                continue;
            }

            $nearest = $samples
                ->sortBy(fn (array $sample) => abs($sample['timestamp'] - $targetTimestamp))
                ->take(self::MAX_SAMPLES)
                ->pluck('ratio')
                ->sort()
                ->values();

            return [
                'ratio' => $this->median($nearest),
                'source' => $choice['source'],
                'sample_size' => $nearest->count(),
            ];
        }

        return null;
    }

    private function knownPlatformAmount(Order $order): ?float
    {
        if (is_numeric($order->sale_escrow_amount)
            && (float) $order->sale_escrow_amount > 0
            && ! (bool) $order->sale_escrow_is_estimated) {
            return (float) $order->sale_escrow_amount;
        }

        if (strcasecmp((string) $order->platform, 'Shopee') === 0) {
            if (empty($order->fee_details)) {
                return null;
            }

            foreach ([$order->escrow_amount, $order->escrow_amount_after_adjustment] as $amount) {
                if (is_numeric($amount) && (float) $amount > 0) {
                    return (float) $amount;
                }
            }

            return null;
        }

        if ($this->tiktokEscrowResolver->hasValidStoredAmount(
            $order->fee_details,
            $order->escrow_amount,
        ) && is_numeric($order->escrow_amount) && (float) $order->escrow_amount > 0) {
            return (float) $order->escrow_amount;
        }

        return null;
    }

    private function historicalPlatformAmount(Order $order): ?float
    {
        $details = is_array($order->fee_details) ? $order->fee_details : [];
        if ($details === []) {
            return null;
        }

        if (strcasecmp((string) $order->platform, 'Shopee') === 0) {
            $income = is_array($details['order_income'] ?? null)
                ? $details['order_income']
                : $details;

            foreach (['escrow_amount', 'escrow_amount_after_adjustment'] as $key) {
                $amount = $income[$key] ?? null;
                if (is_numeric($amount) && (float) $amount > 0) {
                    return (float) $amount;
                }
            }

            return null;
        }

        $summary = is_array($details['summary'] ?? null) ? $details['summary'] : [];
        foreach (['settlement_amount', 'est_settlement_amount'] as $key) {
            $amount = $summary[$key] ?? null;
            if (is_numeric($amount) && (float) $amount > 0) {
                return (float) $amount;
            }
        }

        $transactions = is_array($details['transactions'] ?? null)
            ? $details['transactions']
            : (array_is_list($details) ? $details : []);

        foreach (['settlement_amount', 'est_settlement_amount'] as $key) {
            $amount = $this->positiveTransactionSum($transactions, $key);
            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    private function feeBreakdownEstimate(Order $order, float $grossAmount): ?float
    {
        $breakdown = $this->financialBreakdownService->forOrder($order);
        $estimatedAmount = $grossAmount;
        $ordinaryComponents = 0;

        foreach ($breakdown['components'] as $component) {
            if (in_array($component['key'], self::CORRECTION_COMPONENTS, true)
                || $component['key'] === 'platform_adjustment') {
                continue;
            }

            $ordinaryComponents++;
            $estimatedAmount += $component['operator'] === '+'
                ? (float) $component['amount']
                : -(float) $component['amount'];
        }

        return $ordinaryComponents > 0 && $estimatedAmount > 0
            ? round($estimatedAmount, 2)
            : null;
    }

    private function grossAmount(Order $order): float
    {
        $order->loadMissing('orderProducts');
        $itemGross = (float) $order->orderProducts->sum(
            fn ($item) => (float) ($item->price ?? 0)
                * max(0, (int) ($item->quantity_purchased ?? 0)),
        );

        return $itemGross > 0
            ? $itemGross
            : max(0, (float) ($order->order_selling_price ?? 0));
    }

    /** @return Collection<int, string> */
    private function identifiers(Order $order, string $field): Collection
    {
        return $order->orderProducts
            ->pluck($field)
            ->filter(fn ($value) => $value !== null && trim((string) $value) !== '')
            ->map(fn ($value) => mb_strtolower(trim((string) $value)))
            ->unique()
            ->values();
    }

    private function platformKey(Order $order): string
    {
        return ($order->store?->user_id ?? 'unknown')
            .':'.strtolower(trim((string) $order->platform));
    }

    /** @param array{ratio: float, timestamp: int} $entry */
    private function pushSample(array &$index, string $key, array $entry): void
    {
        $index[$key] ??= collect();
        $index[$key]->push($entry);
    }

    private function median(Collection $values): float
    {
        $count = $values->count();
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    private function positiveTransactionSum(array $transactions, string $key): ?float
    {
        $amounts = collect($transactions)
            ->filter(fn ($transaction) => is_array($transaction))
            ->map(fn (array $transaction) => $transaction[$key] ?? null)
            ->filter(fn ($amount) => is_numeric($amount) && (float) $amount > 0)
            ->map(fn ($amount) => (float) $amount);

        return $amounts->isEmpty() ? null : (float) $amounts->sum();
    }

    /**
     * @return array{amount: float, source: string, is_estimated: bool, sample_size: int, ratio: float|null}
     */
    private function result(
        float $amount,
        string $source,
        bool $isEstimated,
        int $sampleSize = 0,
        ?float $ratio = null,
    ): array {
        return [
            'amount' => max(0, $amount),
            'source' => $source,
            'is_estimated' => $isEstimated,
            'sample_size' => $sampleSize,
            'ratio' => $ratio,
        ];
    }

    private function date(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

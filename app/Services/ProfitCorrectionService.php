<?php

namespace App\Services;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProfitCorrectionService
{
    public function __construct(
        private readonly OrderEscrowService $escrowService,
        private readonly ProductHppService $hppService,
        private readonly LogisticsStatusNormalizer $logisticsStatusNormalizer,
    ) {}

    /**
     * @param  array{hpp_amount: float, sale_escrow_amount: float}  $financialSummary
     * @return array{
     *     type: string,
     *     label: string,
     *     occurred_at: Carbon,
     *     escrow_amount: float,
     *     hpp_amount: float,
     *     profit_amount: float,
     *     affected_ratio: float,
     *     reason: string|null
     * }|null
     */
    public function forOrder(Order $order, array $financialSummary): ?array
    {
        $descriptor = $this->describeOrder($order);
        if (! $descriptor) {
            return null;
        }

        $failedPackages = $this->escrowService->failedDeliveryPackages($order);
        $activeReturns = $this->escrowService->activeReturns($order);

        $saleEscrowAmount = max(0, (float) ($financialSummary['sale_escrow_amount'] ?? 0));
        $fullHppAmount = max(0, (float) ($financialSummary['hpp_amount'] ?? 0));

        if ($descriptor['type'] === 'failed_delivery') {
            return $this->correction(
                $order,
                $descriptor['type'],
                $descriptor['label'],
                $descriptor['occurred_at'],
                1.0,
                $fullHppAmount,
                $saleEscrowAmount,
                $descriptor['reason'],
            );
        }

        [$affectedRatio, $returnedHppAmount] = $this->returnAmounts(
            $order,
            $activeReturns,
            $fullHppAmount,
        );

        return $this->correction(
            $order,
            $descriptor['type'],
            $descriptor['label'],
            $descriptor['occurred_at'],
            $affectedRatio,
            $returnedHppAmount,
            $saleEscrowAmount,
            $descriptor['reason'],
        );
    }

    /**
     * Return correction metadata without calculating HPP or historical escrow.
     *
     * @return array{type: string, label: string, occurred_at: Carbon, reason: string|null}|null
     */
    public function describeOrder(Order $order): ?array
    {
        $failedPackages = $this->escrowService->failedDeliveryPackages($order);
        if ($failedPackages->isNotEmpty()) {
            $occurredAt = $failedPackages
                ->map(fn ($package) => $this->date($package->failed_at ?? $package->updated_at))
                ->filter()
                ->sortBy(fn (Carbon $date) => $date->getTimestamp())
                ->first();

            return $occurredAt ? [
                'type' => 'failed_delivery',
                'label' => 'Pengantaran Gagal',
                'occurred_at' => $occurredAt,
                'reason' => $this->failedDeliveryReason($failedPackages),
            ] : null;
        }

        $activeReturns = $this->escrowService->activeReturns($order);
        if ($activeReturns->isEmpty()) {
            return null;
        }

        $occurredAt = $activeReturns
            ->map(fn ($return) => $this->date($return->created_at_platform ?? $return->created_at))
            ->filter()
            ->sortBy(fn (Carbon $date) => $date->getTimestamp())
            ->first();
        if (! $occurredAt) {
            return null;
        }

        $firstReturn = $activeReturns->sortBy(
            fn ($return) => $this->date($return->created_at_platform ?? $return->created_at)?->getTimestamp() ?? PHP_INT_MAX,
        )->first();

        return [
            'type' => 'return',
            'label' => 'Pengembalian',
            'occurred_at' => $occurredAt,
            'reason' => $firstReturn?->text_reason ?: $firstReturn?->return_reason,
        ];
    }

    private function correction(
        Order $order,
        string $type,
        string $label,
        Carbon $occurredAt,
        float $affectedRatio,
        float $returnedHppAmount,
        float $saleEscrowAmount,
        ?string $reason,
    ): array {
        $affectedRatio = max(0, min(1, $affectedRatio));
        $currentEscrowAmount = (float) $this->escrowService->amount($order);

        if ($affectedRatio >= 0.9999) {
            $targetEscrowAmount = min(0, $currentEscrowAmount);
        } else {
            $proportionalTarget = $saleEscrowAmount * (1 - $affectedRatio);
            $targetEscrowAmount = $currentEscrowAmount < ($saleEscrowAmount - 0.5)
                ? $currentEscrowAmount
                : $proportionalTarget;
        }

        $escrowCorrection = $targetEscrowAmount - $saleEscrowAmount;
        $hppCorrection = -max(0, $returnedHppAmount);

        return [
            'type' => $type,
            'label' => $label,
            'occurred_at' => $occurredAt,
            'escrow_amount' => $escrowCorrection,
            'hpp_amount' => $hppCorrection,
            'profit_amount' => $escrowCorrection - $hppCorrection,
            'affected_ratio' => $affectedRatio,
            'reason' => $reason ? trim($reason) : null,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $returns
     * @return array{float, float}
     */
    private function returnAmounts(Order $order, Collection $returns, float $fullHppAmount): array
    {
        $order->loadMissing('orderProducts');
        $orderItems = $order->orderProducts->values();
        $remainingQuantities = $orderItems->map(
            fn ($item) => max(0, (int) ($item->quantity_purchased ?? 0)),
        )->all();
        $totalRevenue = (float) $orderItems->sum(
            fn ($item) => (float) ($item->price ?? 0) * max(0, (int) ($item->quantity_purchased ?? 0)),
        );
        $returnedRevenue = 0.0;
        $returnedHpp = 0.0;
        $matchedQuantity = 0;

        foreach ($returns as $return) {
            $items = $return->relationLoaded('items')
                ? $return->items
                : ($return->exists ? $return->items()->get() : collect());

            foreach ($items as $returnItem) {
                $identifiers = $this->returnItemIdentifiers($returnItem);
                if ($identifiers->isEmpty()) {
                    continue;
                }

                foreach ($orderItems as $index => $orderItem) {
                    if (($remainingQuantities[$index] ?? 0) <= 0
                        || ! $this->matchesOrderItem($orderItem, $identifiers)) {
                        continue;
                    }

                    $quantity = min(
                        max(1, (int) ($returnItem->quantity ?? 1)),
                        $remainingQuantities[$index],
                    );
                    $remainingQuantities[$index] -= $quantity;
                    $hppDetails = $this->hppService->orderItemHppDetails($orderItem);
                    $returnedHpp += (float) $hppDetails['hpp'] * $quantity;
                    $returnedRevenue += (float) ($orderItem->price ?? 0) * $quantity;
                    $matchedQuantity += $quantity;
                    break;
                }
            }
        }

        if ($matchedQuantity > 0) {
            $ratio = $totalRevenue > 0
                ? $returnedRevenue / $totalRevenue
                : ($fullHppAmount > 0 ? $returnedHpp / $fullHppAmount : 1);

            return [max(0, min(1, $ratio)), min($fullHppAmount, $returnedHpp)];
        }

        $refundAmount = (float) $returns->sum(
            fn ($return) => max(0, (float) ($return->refund_amount ?? 0)),
        );
        $grossAmount = max(
            (float) ($order->order_selling_price ?? 0),
            $totalRevenue,
        );
        $ratio = $refundAmount > 0 && $grossAmount > 0
            ? max(0, min(1, $refundAmount / $grossAmount))
            : 1.0;

        return [$ratio, $fullHppAmount * $ratio];
    }

    private function returnItemIdentifiers(mixed $returnItem): Collection
    {
        $rawData = is_array($returnItem->raw_data ?? null) ? $returnItem->raw_data : [];

        return collect([
            $returnItem->sku_id ?? null,
            $returnItem->external_line_item_id ?? null,
            data_get($rawData, 'model_id'),
            data_get($rawData, 'model_sku'),
            data_get($rawData, 'item_id'),
            data_get($rawData, 'item_sku'),
            data_get($rawData, 'sku'),
        ])->filter(fn ($value) => $value !== null && trim((string) $value) !== '')
            ->map(fn ($value) => mb_strtolower(trim((string) $value)))
            ->unique()
            ->values();
    }

    private function matchesOrderItem(mixed $orderItem, Collection $identifiers): bool
    {
        $orderIdentifiers = collect([
            $orderItem->platform_variant_id,
            $orderItem->sku,
            $orderItem->product_id,
        ])->filter(fn ($value) => $value !== null && trim((string) $value) !== '')
            ->map(fn ($value) => mb_strtolower(trim((string) $value)));

        return $orderIdentifiers->intersect($identifiers)->isNotEmpty();
    }

    private function failedDeliveryReason(Collection $packages): ?string
    {
        foreach ($packages as $package) {
            $history = $this->logisticsStatusNormalizer->history($package->raw_data ?? []);
            $event = collect($history)->first(fn (array $item) => $item['is_failed_delivery'] ?? false);
            if (! empty($event['description'])) {
                return $event['description'];
            }
        }

        return null;
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

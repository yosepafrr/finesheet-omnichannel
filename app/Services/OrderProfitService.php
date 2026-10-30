<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;

class OrderProfitService
{
    public function __construct(
        private readonly OrderEscrowService $escrowService,
        private readonly ProductHppService $hppService,
        private readonly HistoricalSaleEscrowEstimator $historicalSaleEscrowEstimator,
    ) {}

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, array{
     *     hpp_amount: float,
     *     escrow_amount: float,
     *     profit_amount: float,
     *     sale_escrow_amount: float,
     *     sale_profit_amount: float,
     *     sale_escrow_source: string,
     *     sale_escrow_is_estimated: bool,
     *     sale_escrow_sample_size: int,
     *     has_missing_hpp: bool
     * }>
     */
    public function summaries(Collection $orders): Collection
    {
        $this->hydrateMarketplaceProducts($orders);
        $historicalEstimates = $this->historicalSaleEscrowEstimator->estimates($orders);

        return $orders->mapWithKeys(function (Order $order) use ($historicalEstimates) {
            $hppAmount = 0.0;
            $hasMissingHpp = $order->orderProducts->isEmpty();

            foreach ($order->orderProducts as $item) {
                $details = $this->hppService->orderItemHppDetails($item);
                $hppAmount += (float) $details['hpp'] * (int) ($item->quantity_purchased ?? 0);
                $hasMissingHpp = $hasMissingHpp || ! $details['available'];
            }

            $escrowAmount = (float) $this->escrowService->amount($order);
            $saleEscrow = $this->saleEscrow(
                $order,
                $escrowAmount,
                $historicalEstimates->get($order->id),
            );
            $displayEscrowAmount = ! $this->escrowService->hasReturnOrFailedDelivery($order)
                && abs($escrowAmount) < 0.0001
                && $saleEscrow['amount'] > 0
                    ? $saleEscrow['amount']
                    : $escrowAmount;

            return [$order->id => [
                'hpp_amount' => $hppAmount,
                'escrow_amount' => $displayEscrowAmount,
                'profit_amount' => $displayEscrowAmount - $hppAmount,
                'sale_escrow_amount' => $saleEscrow['amount'],
                'sale_profit_amount' => $saleEscrow['amount'] - $hppAmount,
                'sale_escrow_source' => $saleEscrow['source'],
                'sale_escrow_is_estimated' => $saleEscrow['is_estimated'],
                'sale_escrow_sample_size' => $saleEscrow['sample_size'],
                'has_missing_hpp' => $hasMissingHpp,
            ]];
        });
    }

    /**
     * @param  array{amount: float, source: string, is_estimated: bool, sample_size: int}|null  $historicalEstimate
     * @return array{amount: float, source: string, is_estimated: bool, sample_size: int}
     */
    private function saleEscrow(
        Order $order,
        float $currentEscrowAmount,
        ?array $historicalEstimate,
    ): array {
        $hasCorrection = $this->escrowService->hasReturnOrFailedDelivery($order);

        if (! $hasCorrection && $currentEscrowAmount > 0) {
            return $this->saleEscrowResult(
                $currentEscrowAmount,
                'current_platform',
                false,
            );
        }

        if (is_numeric($order->sale_escrow_amount)
            && (float) $order->sale_escrow_amount > 0) {
            return $this->saleEscrowResult(
                (float) $order->sale_escrow_amount,
                $order->sale_escrow_source ?: 'platform_snapshot',
                (bool) $order->sale_escrow_is_estimated,
                (int) ($order->sale_escrow_sample_size ?? 0),
            );
        }

        if (! $hasCorrection && $historicalEstimate !== null) {
            return $this->saleEscrowResult(
                (float) $historicalEstimate['amount'],
                (string) $historicalEstimate['source'],
                (bool) $historicalEstimate['is_estimated'],
                (int) $historicalEstimate['sample_size'],
            );
        }

        if (! $hasCorrection) {
            return $this->saleEscrowResult(0, 'unavailable', true);
        }

        // Some marketplaces keep the positive sale settlement on the order
        // while separately reporting the return. That value is more reliable
        // than reconstructing net income from a post-return payload.
        if ($currentEscrowAmount > 0) {
            return $this->saleEscrowResult(
                $currentEscrowAmount,
                'current_platform',
                false,
            );
        }

        if (is_numeric($order->escrow_amount_after_adjustment)
            && (float) $order->escrow_amount_after_adjustment > 0) {
            return $this->saleEscrowResult(
                (float) $order->escrow_amount_after_adjustment,
                'adjusted_platform',
                false,
            );
        }

        if ($historicalEstimate !== null) {
            return $this->saleEscrowResult(
                (float) $historicalEstimate['amount'],
                (string) $historicalEstimate['source'],
                (bool) $historicalEstimate['is_estimated'],
                (int) $historicalEstimate['sample_size'],
            );
        }

        return $this->saleEscrowResult(0, 'unavailable', true);
    }

    /** @return array{amount: float, source: string, is_estimated: bool, sample_size: int} */
    private function saleEscrowResult(
        float $amount,
        string $source,
        bool $isEstimated,
        int $sampleSize = 0,
    ): array {
        return [
            'amount' => $amount,
            'source' => $source,
            'is_estimated' => $isEstimated,
            'sample_size' => $sampleSize,
        ];
    }

    /** @param Collection<int, Order> $orders */
    private function hydrateMarketplaceProducts(Collection $orders): void
    {
        $marketplaceProducts = Product::query()
            ->with([
                'store',
                'skuSyncMember.group.masterVariant.bundleComponents.componentVariant.syncGroup',
                'variantProducts.skuSyncMember.group.masterVariant.bundleComponents.componentVariant.syncGroup',
            ])
            ->whereIn('store_id', $orders->pluck('store_id')->filter()->unique())
            ->whereIn('product_id', $orders->flatMap(
                fn (Order $order) => $order->orderProducts->pluck('product_id'),
            )->filter()->unique())
            ->get()
            ->keyBy(fn (Product $product) => $product->store_id.':'.$product->product_id);

        foreach ($orders as $order) {
            foreach ($order->orderProducts as $item) {
                $item->setRelation('order', $order);
                $item->setRelation(
                    'product',
                    $marketplaceProducts->get($order->store_id.':'.$item->product_id),
                );
            }
        }
    }
}

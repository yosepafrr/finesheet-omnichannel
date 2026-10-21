<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;

class OrderProfitService
{
    private const PROFIT_CORRECTION_COMPONENTS = [
        'refund_amount',
        'refund_amount_to_buyer',
        'reverse_shipping_fee',
        'reverse_shipping_fee_sst',
        'seller_return_refund',
    ];

    public function __construct(
        private readonly OrderEscrowService $escrowService,
        private readonly ProductHppService $hppService,
        private readonly OrderFinancialBreakdownService $financialBreakdownService,
    ) {}

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, array{
     *     hpp_amount: float,
     *     escrow_amount: float,
     *     profit_amount: float,
     *     sale_escrow_amount: float,
     *     sale_profit_amount: float,
     *     has_missing_hpp: bool
     * }>
     */
    public function summaries(Collection $orders): Collection
    {
        $this->hydrateMarketplaceProducts($orders);

        return $orders->mapWithKeys(function (Order $order) {
            $hppAmount = 0.0;
            $hasMissingHpp = $order->orderProducts->isEmpty();

            foreach ($order->orderProducts as $item) {
                $details = $this->hppService->orderItemHppDetails($item);
                $hppAmount += (float) $details['hpp'] * (int) ($item->quantity_purchased ?? 0);
                $hasMissingHpp = $hasMissingHpp || ! $details['available'];
            }

            $escrowAmount = (float) $this->escrowService->amount($order);
            $saleEscrowAmount = $this->saleEscrowAmount($order, $escrowAmount);

            return [$order->id => [
                'hpp_amount' => $hppAmount,
                'escrow_amount' => $escrowAmount,
                'profit_amount' => $escrowAmount - $hppAmount,
                'sale_escrow_amount' => $saleEscrowAmount,
                'sale_profit_amount' => $saleEscrowAmount - $hppAmount,
                'has_missing_hpp' => $hasMissingHpp,
            ]];
        });
    }

    private function saleEscrowAmount(Order $order, float $currentEscrowAmount): float
    {
        if (! $this->escrowService->hasReturnOrFailedDelivery($order)) {
            return $currentEscrowAmount;
        }

        $breakdown = $this->financialBreakdownService->forOrder($order);
        $estimatedAmount = (float) $breakdown['gross_amount'];

        foreach ($breakdown['components'] as $component) {
            if (in_array($component['key'], self::PROFIT_CORRECTION_COMPONENTS, true)
                || $component['key'] === 'platform_adjustment') {
                continue;
            }

            $estimatedAmount += $component['operator'] === '+'
                ? (float) $component['amount']
                : -(float) $component['amount'];
        }

        $positiveStoredAmount = collect([
            $order->escrow_amount,
            $order->escrow_amount_after_adjustment,
        ])->filter(fn ($amount) => is_numeric($amount) && (float) $amount > 0)
            ->map(fn ($amount) => (float) $amount)
            ->max();

        if ($estimatedAmount > 0 && ($positiveStoredAmount === null || $estimatedAmount > $positiveStoredAmount)) {
            return $estimatedAmount;
        }

        if ($positiveStoredAmount !== null) {
            return $positiveStoredAmount;
        }

        return max(0, $estimatedAmount, (float) ($order->order_selling_price ?? 0));
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

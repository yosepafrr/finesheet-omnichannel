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
    ) {}

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, array{hpp_amount: float, escrow_amount: float, profit_amount: float, has_missing_hpp: bool}>
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

            return [$order->id => [
                'hpp_amount' => $hppAmount,
                'escrow_amount' => $escrowAmount,
                'profit_amount' => $escrowAmount - $hppAmount,
                'has_missing_hpp' => $hasMissingHpp,
            ]];
        });
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

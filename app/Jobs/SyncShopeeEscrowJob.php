<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Store;
use App\Services\ShopeeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncShopeeEscrowJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 60;

    public $uniqueFor = 900;

    public function __construct(
        protected int $storeId,
        protected string $orderSn
    ) {
    }

    public function uniqueId(): string
    {
        return "{$this->storeId}:{$this->orderSn}";
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ShopeeService $shopee): void
    {
        $store = Store::query()
            ->whereKey($this->storeId)
            ->whereRaw('LOWER(platform) = ?', ['shopee'])
            ->first();

        $order = Order::query()
            ->where('store_id', $this->storeId)
            ->where('order_sn', $this->orderSn)
            ->first();

        if (! $store || ! $order) {
            Log::warning('Shopee escrow sync skipped because store or order was not found', [
                'store_id' => $this->storeId,
                'order_sn' => $this->orderSn,
            ]);

            return;
        }

        $response = $shopee->getEscrowDetail($store, $this->orderSn);
        $income = $response['response']['order_income'] ?? null;

        if (! is_array($income) || $income === []) {
            Log::warning('Shopee escrow detail is not available from the platform', [
                'store_id' => $this->storeId,
                'order_sn' => $this->orderSn,
                'error' => $response['error'] ?? null,
                'message' => $response['message'] ?? null,
                'request_id' => $response['request_id'] ?? null,
            ]);

            return;
        }

        $order->update([
            'order_selling_price' => $income['order_selling_price'] ?? $order->order_selling_price,
            'escrow_amount' => $income['escrow_amount'] ?? $order->escrow_amount,
            'escrow_amount_after_adjustment' => $income['escrow_amount_after_adjustment'] ?? $order->escrow_amount_after_adjustment,
            'fee_details' => $income,
        ]);
    }
}

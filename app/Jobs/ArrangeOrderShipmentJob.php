<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderFulfillmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ArrangeOrderShipmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $orderId,
        public readonly array $selection = [],
    ) {}

    public function handle(OrderFulfillmentService $fulfillment): void
    {
        $order = Order::with(['store', 'packages'])->findOrFail($this->orderId);

        $order->forceFill([
            'fulfillment_status' => 'processing',
            'fulfillment_error' => null,
        ])->save();

        try {
            $fulfillment->arrange($order, $this->selection);

            $order->forceFill([
                'order_status' => strcasecmp((string) $order->platform, 'Shopee') === 0
                    ? 'PROCESSED'
                    : 'AWAITING_COLLECTION',
                'fulfillment_status' => 'completed',
                'fulfillment_error' => null,
                'fulfillment_completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $order->forceFill([
                'fulfillment_status' => 'retrying',
                'fulfillment_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            throw $exception;
        }

        Log::info('Order shipment arranged', [
            'order_id' => $order->id,
            'order_sn' => $order->order_sn,
            'platform' => $order->platform,
        ]);
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function failed(?Throwable $exception): void
    {
        $order = Order::with('store')->find($this->orderId);
        if (! $order) {
            return;
        }

        $order->forceFill([
            'fulfillment_status' => 'failed',
            'fulfillment_error' => mb_substr($exception?->getMessage() ?: 'Pengaturan pengiriman gagal.', 0, 2000),
            'fulfillment_completed_at' => now(),
        ])->save();

        Log::error('Order shipment arrangement failed', [
            'order_id' => $order->id,
            'order_sn' => $order->order_sn,
            'platform' => $order->platform,
            'message' => $exception?->getMessage(),
        ]);
    }
}

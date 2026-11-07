<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderFulfillmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ArrangeOrderShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 3;

    public function __construct(
        public readonly int $orderId,
        public readonly array $selection = [],
    ) {}

    public function handle(OrderFulfillmentService $fulfillment): void
    {
        $order = Order::with(['store', 'packages'])->findOrFail($this->orderId);
        $fulfillment->arrange($order, $this->selection);

        Log::info('Order shipment arranged', [
            'order_id' => $order->id,
            'order_sn' => $order->order_sn,
            'platform' => $order->platform,
        ]);
    }
}

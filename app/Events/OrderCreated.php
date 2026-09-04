<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class OrderCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;

    /**
     * Create a new event instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel
     */
    public function broadcastOn(): Channel
    {
        return new Channel('orders');
    }

    public function broadcastAs(): string
    {
        return 'OrderCreated';
    }

    public function broadcastWith(): array
    {
        $platform = $this->order->platform ?? ($this->order->store ? $this->order->store->platform : 'Unknown');
        
        $productName = 'Produk tidak diketahui';
        if ($this->order->orderProducts && $this->order->orderProducts->count() > 0) {
            $productName = $this->order->orderProducts->first()->product_name;
            if ($this->order->orderProducts->count() > 1) {
                $productName .= ' (+' . ($this->order->orderProducts->count() - 1) . ' produk)';
            }
        }

        return [
            'id' => $this->order->id,
            'order_sn' => $this->order->order_sn,
            'platform' => $platform,
            'product_name' => $productName,
            'store_id' => $this->order->store_id,
        ];
    }
}

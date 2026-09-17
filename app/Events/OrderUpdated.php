<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderUpdated implements ShouldBroadcastNow
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
     */
    public function broadcastOn(): Channel
    {
        $userId = $this->resolveUserId();
        return new PrivateChannel('orders.' . ($userId ?? 0));
    }

    /**
     * Resolve the owner user ID for this order's store.
     */
    public function resolveUserId(): ?int
    {
        if ($this->order->store && $this->order->store->user_id) {
            return (int) $this->order->store->user_id;
        }

        if ($this->order->store_id) {
            $store = \App\Models\Store::find($this->order->store_id);
            if ($store && $store->user_id) {
                return (int) $store->user_id;
            }
        }

        return null;
    }

    public function broadcastAs(): string
    {
        return 'OrderUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'order_sn' => $this->order->order_sn,
            'platform' => $this->order->platform,
            'order_status' => $this->order->order_status,
            'store_id' => $this->order->store_id,
            'user_id' => $this->resolveUserId(),
        ];
    }
}

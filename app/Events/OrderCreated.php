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
        // Force refresh relations to prevent "Produk tidak diketahui" due to cached empty collections
        // during model saving events that occurred before products were inserted.
        $order->unsetRelation('orderProducts');
        $this->order = $order;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel
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
        return 'OrderCreated';
    }

    public function broadcastWith(): array
    {
        $this->order->loadMissing('store');
        $platform = $this->order->platform ?? $this->order->store?->platform ?? 'Unknown';
        [$productName, $productCount] = $this->resolveProductSummary();

        if ($productCount > 1) {
            $productName .= ' (+'.($productCount - 1).' produk)';
        }

        return [
            'id' => $this->order->id,
            'order_sn' => $this->order->order_sn,
            'platform' => $platform,
            'product_name' => $productName,
            'store_id' => $this->order->store_id,
            'store_name' => $this->order->store?->store_name ?? 'Toko tidak diketahui',
            'user_id' => $this->resolveUserId(),
        ];
    }

    /**
     * Order events can fire before order_products are inserted. Marketplace raw
     * data is therefore the reliable fallback for the first notification.
     */
    private function resolveProductSummary(): array
    {
        $products = $this->order->orderProducts()
            ->whereNotNull('product_name')
            ->where('product_name', '!=', '')
            ->get(['product_name']);

        if ($products->isNotEmpty()) {
            return [trim((string) $products->first()->product_name), $products->count()];
        }

        $rawData = is_array($this->order->raw_data) ? $this->order->raw_data : [];
        $items = data_get($rawData, 'item_list', data_get($rawData, 'line_items', []));
        $items = is_array($items) ? $items : [];
        $firstItem = (array) ($items[0] ?? []);
        $productName = trim((string) ($firstItem['item_name'] ?? $firstItem['product_name'] ?? ''));

        if ($productName !== '') {
            return [$productName, count($items)];
        }

        $platformProductId = $firstItem['item_id'] ?? $firstItem['product_id'] ?? null;
        if ($platformProductId) {
            $catalogName = \App\Models\Product::query()
                ->where('store_id', $this->order->store_id)
                ->where('product_id', (string) $platformProductId)
                ->value('product_name');

            if ($catalogName) {
                return [trim((string) $catalogName), max(count($items), 1)];
            }
        }

        return ['Detail produk sedang dimuat', 1];
    }
}

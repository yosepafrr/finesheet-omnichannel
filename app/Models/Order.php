<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    protected static function booted()
    {
        static::saved(function ($order) {
            if ($order->isDirty('order_status') || $order->isDirty('normalized_cancel_category')) {
                $statusUpper = strtoupper(trim($order->order_status ?? ''));
                if (in_array($statusUpper, ['CANCEL', 'CANCELLED', 'IN_CANCEL'])) {
                    app(\App\Services\PayableService::class)->recordCancellationEvent($order, 'FAILED_DELIVERY');
                } elseif (empty($statusUpper) || in_array($statusUpper, ['UNPAID', 'UNKNOWN', 'ON_HOLD'])) {
                    \App\Models\PayableEvent::where('source_id', $order->order_sn)
                        ->where('source_type', 'CREATE_ORDER')
                        ->delete();
                } else {
                    app(\App\Services\PayableService::class)->recordOrderEvent($order);
                }
            }

            // Smart Notification Logic (OrderCreated is treated as "Pesanan Baru" in frontend)
            $statusUpper = strtoupper(trim($order->order_status ?? ''));
            $isPerluDikirim = in_array($statusUpper, ['READY_TO_SHIP', 'PROCESSED', 'AWAITING_SHIPMENT', 'AWAITING_COLLECTION']);

            $shouldNotify = false;
            if ($order->wasRecentlyCreated && $isPerluDikirim) {
                $shouldNotify = true;
            } elseif (!$order->wasRecentlyCreated && $order->isDirty('order_status')) {
                $oldStatus = strtoupper(trim($order->getOriginal('order_status')));
                if (!in_array($oldStatus, ['READY_TO_SHIP', 'PROCESSED', 'AWAITING_SHIPMENT', 'AWAITING_COLLECTION']) && $isPerluDikirim) {
                    $shouldNotify = true;
                }
            }

            if ($shouldNotify) {
                try {
                    event(new \App\Events\OrderCreated($order));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Failed to broadcast OrderCreated: " . $e->getMessage());
                }
            }

            // Broadcast only meaningful order-list changes. Bulk sync can save many
            // rows and would otherwise flood the browser with refresh events.
            $shouldBroadcastUpdate = !$order->wasRecentlyCreated && $order->wasChanged([
                'order_status',
                'normalized_cancel_category',
                'order_selling_price',
                'escrow_amount',
                'escrow_amount_after_adjustment',
            ]);

            if ($shouldBroadcastUpdate) {
                try {
                    broadcast(new \App\Events\OrderUpdated($order));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Failed to broadcast OrderUpdated: " . $e->getMessage());
                }
            }
        });
    }

    protected $fillable = [
        'store_id',
        'platform',
        'order_sn',
        'booking_sn',
        'order_status',
        'order_time',
        'cod',
        'ship_by_date',
        'message_to_seller',
        'raw_data',
        'order_selling_price',
        'escrow_amount',
        'escrow_amount_after_adjustment',
        'fee_details',
        'cancel_source',
        'cancel_reason',
        'buyer_cancel_reason',
        'normalized_cancel_category',
        'stock_sync_processed_at',
        'stock_sync_deductions',
        'stock_sync_reverted_at',
        'stock_sync_shipped_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'cod' => 'boolean',
        'ship_by_date' => 'datetime',
        'order_time' => 'datetime',
        'raw_data' => 'array',
        'fee_details' => 'array',
        'total_amount' => 'float',
        'order_selling_price' => 'float',
        'escrow_amount' => 'float',
        'escrow_amount_after_adjustment' => 'float',
        'stock_sync_processed_at' => 'datetime',
        'stock_sync_deductions' => 'array',
        'stock_sync_reverted_at' => 'datetime',
        'stock_sync_shipped_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class, 'order_id', 'id')->orderBy('id', 'asc');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id', 'id');
    }

    public function returns()
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function packages()
    {
        return $this->hasMany(OrderPackage::class);
    }
}

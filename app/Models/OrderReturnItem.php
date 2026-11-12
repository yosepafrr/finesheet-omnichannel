<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReturnItem extends Model
{
    protected $table = 'order_return_items';

    protected static function booted()
    {
        static::saved(function ($returnItem) {
            app(\App\Services\PayableService::class)->recordReturnEvent($returnItem->orderReturn);

            if ($returnItem->wasRecentlyCreated || $returnItem->wasChanged([
                'order_return_id',
                'external_line_item_id',
                'sku_id',
                'quantity',
                'refund_amount',
            ])) {
                CashflowProfitSnapshot::invalidateForOrder($returnItem->orderReturn?->order);
            }
        });

        static::deleted(fn (OrderReturnItem $returnItem) => (
            CashflowProfitSnapshot::invalidateForOrder($returnItem->orderReturn?->order)
        ));
    }

    protected $fillable = [
        'order_return_id',
        'external_line_item_id',
        'sku_id',
        'product_name',
        'quantity',
        'refund_amount',
        'raw_data',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'refund_amount' => 'float',
        'raw_data' => 'array',
    ];

    public function orderReturn()
    {
        return $this->belongsTo(OrderReturn::class);
    }
}

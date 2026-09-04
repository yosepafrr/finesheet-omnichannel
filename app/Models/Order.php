<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

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
        'escrow_amount_after_adjustment' => 'float',
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
}

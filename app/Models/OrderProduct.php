<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderProduct extends Model
{
    use HasFactory;

    protected static function booted()
    {
        static::saved(function ($orderProduct) {
            app(\App\Services\PayableService::class)->recordOrderEvent($orderProduct->order);
        });
    }

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'model_name',
        'quantity_purchased',
        'price',
        'image',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class)->orderBy('order_time', 'desc');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function variantProducts()
    {
        return $this->hasMany(VariantProduct::class, 'product_id', 'id');
    }
}

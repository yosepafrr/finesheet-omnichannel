<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    protected $fillable = [
        'store_id',
        'platform',
        'product_id',
        'product_name',
        'product_sku',
        'product_status',
        'stock',
        'price',
        'category',
        'image',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'product_id', 'product_id');
    }

    public function variantProducts()
    {
        return $this->hasMany(VariantProduct::class, 'product_id', 'id')
                    ->orderBy('tier_index->0')
                    ->orderBy('tier_index->1');
    }
}

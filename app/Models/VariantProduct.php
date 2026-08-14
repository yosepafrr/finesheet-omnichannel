<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VariantProduct extends Model
{
    protected $fillable = [
        'product_id',
        'model_id',
        'model_name',
        'model_sku',
        'price',
        'stock',
        'status',
        'hpp',
        'tier_index',
        'variant_name',
        'variant_options',
        'variant_image',
    ];

    protected $casts = [
        'tier_index' => 'array',
        'variant_options' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

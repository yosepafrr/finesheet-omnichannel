<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterProductVariantListing extends Model
{
    protected $fillable = [
        'master_product_variant_id',
        'store_id',
        'product_id',
        'variant_product_id',
        'listing_key',
        'platform_product_id',
        'platform_variant_id',
        'snapshot_stock',
        'snapshot_price',
    ];

    protected $casts = [
        'snapshot_price' => 'decimal:2',
    ];

    public function masterVariant()
    {
        return $this->belongsTo(MasterProductVariant::class, 'master_product_variant_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(VariantProduct::class, 'variant_product_id');
    }
}

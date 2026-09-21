<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkuSyncMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku_sync_group_id',
        'store_id',
        'product_id',
        'variant_product_id',
        'platform_product_id',
        'platform_variant_id',
        'sync_status',
        'sync_requested_at',
        'last_synced_at',
        'last_sync_error',
    ];

    protected $casts = [
        'sync_requested_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(SkuSyncGroup::class, 'sku_sync_group_id');
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

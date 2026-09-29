<?php

namespace App\Models;

use App\Services\MasterProductClusterService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'master_product_id',
        'user_id',
        'supplier_id',
        'sku',
        'product_cluster_key',
        'variant_cluster_keys',
        'variant_name',
        'barcode',
        'hpp',
        'stock',
        'attributes',
        'is_active',
    ];

    protected $casts = [
        'supplier_id' => 'integer',
        'attributes' => 'array',
        'variant_cluster_keys' => 'array',
        'hpp' => 'decimal:2',
        'stock' => 'integer',
        'is_active' => 'boolean',
    ];

    public function masterProduct()
    {
        return $this->belongsTo(MasterProduct::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function syncGroup()
    {
        return $this->hasOne(SkuSyncGroup::class);
    }

    protected static function booted(): void
    {
        static::saving(function (MasterProductVariant $variant) {
            if (! $variant->isDirty('sku') && $variant->product_cluster_key !== null) {
                return;
            }

            $clusters = app(MasterProductClusterService::class)->fromSku($variant->sku);
            $variant->product_cluster_key = $clusters['product_key'];
            $variant->variant_cluster_keys = $clusters['variant_keys'];
        });
    }
}

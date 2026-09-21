<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'master_product_id',
        'user_id',
        'sku',
        'variant_name',
        'barcode',
        'hpp',
        'stock',
        'attributes',
        'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'hpp' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function masterProduct()
    {
        return $this->belongsTo(MasterProduct::class);
    }

    public function syncGroup()
    {
        return $this->hasOne(SkuSyncGroup::class);
    }

    public function listings()
    {
        return $this->hasMany(MasterProductVariantListing::class);
    }
}

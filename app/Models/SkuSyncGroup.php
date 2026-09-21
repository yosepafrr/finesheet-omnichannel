<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkuSyncGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'master_product_variant_id',
        'sku',
        'master_stock',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function members()
    {
        return $this->hasMany(SkuSyncMember::class);
    }

    public function masterVariant()
    {
        return $this->belongsTo(MasterProductVariant::class, 'master_product_variant_id');
    }
}

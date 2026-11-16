<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = [
        'user_id',
        'master_product_variant_id',
        'type',
        'on_hand_delta',
        'on_order_delta',
        'reserved_delta',
        'on_hand_after',
        'on_order_after',
        'reserved_after',
        'available_after',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'notes',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function variant()
    {
        return $this->belongsTo(MasterProductVariant::class, 'master_product_variant_id');
    }

    public function reference()
    {
        return $this->morphTo();
    }
}

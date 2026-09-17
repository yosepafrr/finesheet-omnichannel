<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderPackage extends Model
{
    protected $fillable = [
        'order_id',
        'platform',
        'package_id',
        'tracking_number',
        'logistics_status',
        'normalized_logistics_status',
        'raw_data',
    ];

    protected $casts = [
        'raw_data' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}

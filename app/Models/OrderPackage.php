<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderPackage extends Model
{
    protected static function booted()
    {
        static::saved(function ($package) {
            $shouldBroadcast = $package->wasChanged('normalized_logistics_status')
                && $package->normalized_logistics_status === 'DELIVERY_FAILED';

            if (!$shouldBroadcast || !$package->order) {
                return;
            }

            try {
                broadcast(new \App\Events\OrderUpdated($package->order));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to broadcast OrderUpdated on OrderPackage: '.$e->getMessage());
            }
        });
    }

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

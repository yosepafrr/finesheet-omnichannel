<?php

namespace App\Models;

use App\Events\OrderUpdated;
use App\Services\LogisticsStatusNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class OrderPackage extends Model
{
    protected static function booted()
    {
        static::saving(function ($package) {
            if ($package->normalized_logistics_status !== 'DELIVERY_FAILED' || $package->failed_at) {
                return;
            }

            $package->loadMissing('order');
            $normalizer = app(LogisticsStatusNormalizer::class);
            $package->failed_at = $normalizer->failedDeliveryOccurredAt($package->raw_data ?? [])
                ?? $normalizer->failedDeliveryOccurredAt($package->order?->raw_data ?? [])
                ?? now();
        });

        static::saved(function ($package) {
            $shouldBroadcast = $package->wasChanged('normalized_logistics_status')
                && $package->normalized_logistics_status === 'DELIVERY_FAILED';

            if (! $shouldBroadcast || ! $package->order) {
                return;
            }

            try {
                broadcast(new OrderUpdated($package->order));
            } catch (\Throwable $e) {
                Log::warning('Failed to broadcast OrderUpdated on OrderPackage: '.$e->getMessage());
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
        'failed_at',
        'raw_data',
    ];

    protected $casts = [
        'failed_at' => 'datetime',
        'raw_data' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}

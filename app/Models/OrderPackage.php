<?php

namespace App\Models;

use App\Events\OrderUpdated;
use App\Services\LogisticsStatusNormalizer;
use App\Services\PayableService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class OrderPackage extends Model
{
    protected static function booted()
    {
        static::saving(function ($package) {
            if ($package->normalized_logistics_status !== 'DELIVERY_FAILED') {
                $package->failed_at = null;

                return;
            }

            $package->loadMissing('order');
            $normalizer = app(LogisticsStatusNormalizer::class);
            $packagePayload = $package->raw_data ?? [];
            $orderPayload = $package->order?->raw_data ?? [];
            $resolvedAt = $normalizer->resolveFailedDeliveryOccurredAt(
                $packagePayload,
                $package->failed_at
            );

            if (! $normalizer->failedDeliveryOccurredAt($packagePayload)) {
                $resolvedAt = $normalizer->resolveFailedDeliveryOccurredAt(
                    $orderPayload,
                    $resolvedAt
                );
            }

            if ($resolvedAt) {
                $package->failed_at = $resolvedAt;
            } elseif (! $package->failed_at) {
                $package->failed_at = now();
            }
        });

        static::saved(function ($package) {
            $shouldBroadcast = $package->wasChanged('normalized_logistics_status');
            $shouldReconcilePayable = $package->wasChanged([
                'normalized_logistics_status',
                'failed_at',
            ]);

            if (! $shouldBroadcast && ! $shouldReconcilePayable) {
                return;
            }

            $order = $package->order;
            if (! $order) {
                return;
            }

            if ($shouldReconcilePayable) {
                try {
                    app(PayableService::class)->reconcileOrderLogistics($order);
                } catch (\Throwable $e) {
                    Log::warning('Failed to reconcile payable after logistics status change: '.$e->getMessage());
                }
            }

            if ($shouldBroadcast) {
                try {
                    broadcast(new OrderUpdated($order));
                } catch (\Throwable $e) {
                    Log::warning('Failed to broadcast OrderUpdated on OrderPackage: '.$e->getMessage());
                }
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

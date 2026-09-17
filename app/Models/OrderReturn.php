<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReturn extends Model
{
    protected $table = 'order_returns';

    protected static function booted()
    {
        static::saved(function ($return) {
            app(\App\Services\PayableService::class)->recordReturnEvent($return);
            try {
                if ($return->order) {
                    broadcast(new \App\Events\OrderUpdated($return->order));
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to broadcast OrderUpdated on OrderReturn: " . $e->getMessage());
            }
        });

        static::deleted(function ($return) {
            try {
                if ($return->order) {
                    broadcast(new \App\Events\OrderUpdated($return->order));
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to broadcast OrderUpdated on OrderReturn delete: " . $e->getMessage());
            }
        });
    }

    protected $fillable = [
        'order_id',
        'platform',
        'external_return_id',
        'return_status',
        'platform_status',
        'normalized_status',
        'return_type',
        'refund_amount',
        'return_reason',
        'text_reason',
        'tracking_number',
        'raw_data',
        'created_at_platform',
        'updated_at_platform',
    ];

    public function setReturnStatusAttribute($value)
    {
        $this->attributes['return_status'] = $value;
        if (empty($this->attributes['platform_status'])) {
            $this->attributes['platform_status'] = $value;
        }
    }

    public function setPlatformStatusAttribute($value)
    {
        $this->attributes['platform_status'] = $value;
        if (empty($this->attributes['return_status'])) {
            $this->attributes['return_status'] = $value;
        }
    }

    protected $casts = [
        'refund_amount' => 'float',
        'raw_data' => 'array',
        'created_at_platform' => 'datetime',
        'updated_at_platform' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function items()
    {
        return $this->hasMany(OrderReturnItem::class);
    }
}

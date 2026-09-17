<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayablePeriod extends Model
{
    protected static function booted()
    {
        static::saved(function ($period) {
            try {
                broadcast(new \App\Events\PayableUpdated($period->id, 'period_saved', $period->user_id));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to broadcast PayableUpdated: " . $e->getMessage());
            }
        });

        static::deleted(function ($period) {
            try {
                broadcast(new \App\Events\PayableUpdated($period->id, 'period_deleted', $period->user_id));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to broadcast PayableUpdated: " . $e->getMessage());
            }
        });
    }

    protected $fillable = [
        'user_id',
        'supplier_id',
        'name',
        'start_date',
        'end_date',
        'payment_status',
        'is_closed',
        'is_manual',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_closed' => 'boolean',
        'is_manual' => 'boolean',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function events()
    {
        return $this->hasMany(PayableEvent::class);
    }

    public function payments()
    {
        return $this->hasMany(PayablePayment::class);
    }
}

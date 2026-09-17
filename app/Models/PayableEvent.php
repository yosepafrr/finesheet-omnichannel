<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayableEvent extends Model
{
    protected static function booted()
    {
        static::saved(function ($event) {
            try {
                $userId = $event->user_id ?? $event->period?->user_id;
                broadcast(new \App\Events\PayableUpdated($event->payable_period_id, 'event_saved', $userId));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to broadcast PayableUpdated: " . $e->getMessage());
            }
        });

        static::deleted(function ($event) {
            try {
                $userId = $event->user_id ?? $event->period?->user_id;
                broadcast(new \App\Events\PayableUpdated($event->payable_period_id, 'event_deleted', $userId));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to broadcast PayableUpdated: " . $e->getMessage());
            }
        });
    }

    protected $fillable = [
        'user_id',
        'supplier_id',
        'payable_period_id',
        'store_id',
        'platform',
        'source_id',
        'source_type',
        'event_date',
        'amount',
        'is_manual_moved',
        'original_period_id',
        'notes',
    ];

    protected $casts = [
        'event_date' => 'datetime',
        'amount' => 'decimal:2',
        'is_manual_moved' => 'boolean',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function period()
    {
        return $this->belongsTo(PayablePeriod::class, 'payable_period_id');
    }

    public function originalPeriod()
    {
        return $this->belongsTo(PayablePeriod::class, 'original_period_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}

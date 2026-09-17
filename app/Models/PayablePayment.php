<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayablePayment extends Model
{
    protected static function booted()
    {
        static::saved(function ($payment) {
            try {
                $userId = $payment->user_id ?? $payment->period?->user_id;
                broadcast(new \App\Events\PayableUpdated($payment->payable_period_id, 'payment_saved', $userId));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to broadcast PayableUpdated: " . $e->getMessage());
            }
        });

        static::deleted(function ($payment) {
            try {
                $userId = $payment->user_id ?? $payment->period?->user_id;
                broadcast(new \App\Events\PayableUpdated($payment->payable_period_id, 'payment_deleted', $userId));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to broadcast PayableUpdated: " . $e->getMessage());
            }
        });
    }

    protected $fillable = [
        'user_id',
        'supplier_id',
        'payable_period_id',
        'payment_date',
        'amount',
        'payment_method',
        'proof_file_path',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function period()
    {
        return $this->belongsTo(PayablePeriod::class, 'payable_period_id');
    }
}

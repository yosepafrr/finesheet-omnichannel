<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayableAudit extends Model
{
    protected $fillable = [
        'user_id',
        'payable_period_id',
        'action',
        'description',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function period()
    {
        return $this->belongsTo(PayablePeriod::class, 'payable_period_id');
    }
}

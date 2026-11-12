<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'category',
        'subtype',
        'source_type',
        'balance',
        'synced_at',
        'integration_details',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'synced_at' => 'datetime',
        'integration_details' => 'array',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(CashflowTransaction::class);
    }
}

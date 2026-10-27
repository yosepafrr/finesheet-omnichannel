<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashflowTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'financial_account_id',
        'direction',
        'category',
        'description',
        'amount',
        'occurred_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function account()
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }
}

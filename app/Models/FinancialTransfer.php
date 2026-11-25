<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialTransfer extends Model
{
    protected $fillable = [
        'user_id', 'source_account_id', 'destination_account_id',
        'source_name', 'destination_name', 'amount', 'fee', 'request_key', 'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];
}

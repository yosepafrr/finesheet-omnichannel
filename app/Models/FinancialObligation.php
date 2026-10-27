<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialObligation extends Model
{
    protected $fillable = [
        'user_id',
        'kind',
        'title',
        'counterparty',
        'amount',
        'due_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceAdSpend extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'platform',
        'spend_date',
        'amount',
        'currency',
        'raw_data',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'spend_date' => 'date',
            'amount' => 'decimal:2',
            'raw_data' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(TiktokAdCampaignSpend::class);
    }
}

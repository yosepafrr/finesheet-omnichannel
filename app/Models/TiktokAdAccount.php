<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiktokAdAccount extends Model
{
    protected $fillable = [
        'tiktok_ads_connection_id',
        'user_id',
        'store_id',
        'advertiser_id',
        'advertiser_name',
        'currency',
        'timezone',
        'is_active',
        'last_synced_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(TiktokAdsConnection::class, 'tiktok_ads_connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}

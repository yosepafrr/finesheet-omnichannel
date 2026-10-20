<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TiktokAdsConnection extends Model
{
    protected $fillable = [
        'user_id',
        'access_token',
        'advertiser_ids',
        'scopes',
        'status',
        'authorized_at',
        'last_synced_at',
        'last_error',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'advertiser_ids' => 'array',
            'scopes' => 'array',
            'authorized_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(TiktokAdAccount::class);
    }
}

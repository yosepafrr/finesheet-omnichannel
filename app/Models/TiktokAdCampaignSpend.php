<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiktokAdCampaignSpend extends Model
{
    protected $fillable = [
        'marketplace_ad_spend_id',
        'tiktok_ad_account_id',
        'campaign_id',
        'campaign_name',
        'amount',
        'impressions',
        'clicks',
        'raw_data',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'raw_data' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function adSpend(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAdSpend::class, 'marketplace_ad_spend_id');
    }

    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(TiktokAdAccount::class, 'tiktok_ad_account_id');
    }
}

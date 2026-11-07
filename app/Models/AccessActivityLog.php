<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'access_profile_id',
        'action',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AccessProfile::class, 'access_profile_id');
    }
}

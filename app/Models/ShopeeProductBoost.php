<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProductBoost extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['selected' => 'boolean', 'last_boosted_at' => 'datetime', 'active_until' => 'datetime', 'observed_at' => 'datetime'];
}

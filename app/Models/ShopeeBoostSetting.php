<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeBoostSetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['enabled' => 'boolean', 'next_check_at' => 'datetime', 'checked_at' => 'datetime'];
}

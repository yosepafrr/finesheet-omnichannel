<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = [
        'platform',
        'store_name',
        'shop_expired_at',
        'access_token',
        'refresh_token',
        'token_expired_at',
        'shopee_shop_id',
        'platform_shop_id',
        'user_id'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders() {
        return $this->hasMany(Order::class, 'store_id', 'id')->orderBy('order_time', 'desc');
    }
}

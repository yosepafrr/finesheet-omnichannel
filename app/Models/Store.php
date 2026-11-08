<?php

namespace App\Models;

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
        'fulfillment_handover_method',
        'shipping_label_template',
        'shopee_thermal_printing',
        'print_shipping_label',
        'print_packing_list',
        'shipping_label_size',
        'packing_list_size',
        'printing_layout',
        'printing_file_format',
        'user_id',
    ];

    protected $casts = [
        'print_shipping_label' => 'boolean',
        'print_packing_list' => 'boolean',
        'shopee_thermal_printing' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'store_id', 'id')->orderBy('order_time', 'desc');
    }

    public function tiktokAdAccounts()
    {
        return $this->hasMany(TiktokAdAccount::class, 'store_id');
    }
}

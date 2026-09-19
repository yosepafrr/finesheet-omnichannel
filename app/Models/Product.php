<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    protected $fillable = [
        'store_id',
        'platform',
        'product_id',
        'product_name',
        'product_sku',
        'product_status',
        'stock',
        'price',
        'category',
        'image',
        'supplier_id',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'product_id', 'product_id');
    }

    public function variantProducts()
    {
        return $this->hasMany(VariantProduct::class, 'product_id', 'id')
                    ->orderBy('tier_index->0')
                    ->orderBy('tier_index->1');
    }

    public function supplierMappings()
    {
        return $this->hasMany(SupplierProductMapping::class);
    }

    protected static function booted()
    {
        static::saving(function ($product) {
            if (empty($product->supplier_id)) {
                $userId = null;
                if ($product->store) {
                    $userId = $product->store->user_id;
                } elseif ($product->store_id) {
                    $userId = \App\Models\Store::where('id', $product->store_id)->value('user_id');
                }
                if ($userId) {
                    $mapping = null;
                    if (!empty($product->product_sku)) {
                        $mapping = \App\Models\SupplierProductMapping::where('user_id', $userId)
                            ->where('sku', $product->product_sku)
                            ->first();
                    }
                    if (!$mapping && !empty($product->product_id)) {
                        $mapping = \App\Models\SupplierProductMapping::where('user_id', $userId)
                            ->where('platform_product_id', (string)$product->product_id)
                            ->first();
                    }
                    if ($mapping) {
                        $product->supplier_id = $mapping->supplier_id;
                    }
                }
            }
        });
    }
}

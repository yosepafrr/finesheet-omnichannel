<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'reference_store_id',
        'name',
        'brand',
        'category',
        'description',
        'image',
        'status',
        'source',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function variants()
    {
        return $this->hasMany(MasterProductVariant::class);
    }

    public function referenceStore()
    {
        return $this->belongsTo(Store::class, 'reference_store_id');
    }
}

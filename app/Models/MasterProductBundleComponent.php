<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterProductBundleComponent extends Model
{
    protected $fillable = [
        'bundle_variant_id',
        'component_variant_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function bundleVariant()
    {
        return $this->belongsTo(MasterProductVariant::class, 'bundle_variant_id');
    }

    public function componentVariant()
    {
        return $this->belongsTo(MasterProductVariant::class, 'component_variant_id');
    }
}

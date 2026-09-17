<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'contact_person',
        'phone',
        'address',
        'notes',
        'period_length_days',
        'first_period_start',
    ];

    protected $casts = [
        'first_period_start' => 'datetime',
    ];

    public function periods()
    {
        return $this->hasMany(PayablePeriod::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function payableEvents()
    {
        return $this->hasMany(PayableEvent::class);
    }

    public function payablePayments()
    {
        return $this->hasMany(PayablePayment::class);
    }

    public function mappings()
    {
        return $this->hasMany(SupplierProductMapping::class);
    }
}

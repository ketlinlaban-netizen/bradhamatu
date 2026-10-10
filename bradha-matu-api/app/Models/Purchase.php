<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'id', 'customer_id', 'package_id', 'package_snapshot',
        'order_id', 'amount_minor', 'status', 'completed_at',
    ];

    protected $casts = [
        'package_snapshot' => 'array',
        'amount_minor' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'purchase_id');
    }

    public function serviceAccount()
    {
        return $this->hasOne(ServiceAccount::class, 'purchase_id');
    }
}

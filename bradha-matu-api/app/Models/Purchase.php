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
        'invoice_number', 'subtotal_minor', 'tax_minor', 'network_identity_id',
        'billing_period_start_at', 'billing_period_end_at', 'due_at',
        'admin_idempotency_key', 'admin_billing_cycle_key', 'admin_request_fingerprint',
    ];

    protected $casts = [
        'package_snapshot' => 'array',
        'amount_minor' => 'integer',
        'subtotal_minor' => 'integer',
        'tax_minor' => 'integer',
        'completed_at' => 'datetime',
        'billing_period_start_at' => 'datetime',
        'billing_period_end_at' => 'datetime',
        'due_at' => 'datetime',
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

    public function networkIdentity()
    {
        return $this->belongsTo(CustomerNetworkIdentity::class, 'network_identity_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceAccount extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DEACTIVATED = 'deactivated';
    public const STATUS_ACTIVATION_FAILED = 'activation_failed';

    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'id', 'purchase_id', 'customer_id', 'router_id',
        'username', 'password_hash', 'access_type',
        'rate_limit_rx_kbps', 'rate_limit_tx_kbps',
        'activation_status', 'activated_at', 'expires_at',
        'deactivation_reason',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'rate_limit_rx_kbps' => 'integer',
        'rate_limit_tx_kbps' => 'integer',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function router()
    {
        return $this->belongsTo(Router::class, 'router_id');
    }
}

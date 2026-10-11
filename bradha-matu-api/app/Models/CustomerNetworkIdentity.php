<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNetworkIdentity extends Model
{
    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'customer_id',
        'router_id',
        'network_client_id',
        'friendly_name',
        'mac_address',
        'ip_address',
        'source',
        'observed_at',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function networkClient(): BelongsTo
    {
        return $this->belongsTo(NetworkClient::class);
    }
}

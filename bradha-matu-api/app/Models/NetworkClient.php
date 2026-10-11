<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkClient extends Model
{
    public const ALIAS_MAX_LENGTH = 80;

    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'router_id',
        'mac_address',
        'connection_type',
        'hostname',
        'alias',
        'ip_address',
        'interface_id',
        'interface_name',
        'signal',
        'rx_bytes',
        'tx_bytes',
        'first_seen_at',
        'last_seen_at',
        'status',
        'offline_reason',
    ];

    protected $casts = [
        'signal' => 'integer',
        'rx_bytes' => 'integer',
        'tx_bytes' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}

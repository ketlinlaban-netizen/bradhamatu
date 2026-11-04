<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Router extends Model
{
    use HasFactory;

    public const SUPPORTED_API_TRANSPORTS = ['binary', 'rest', 'telemetry'];

    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'identity', 'ip_address', 'api_port', 'api_transport', 'api_use_tls',
        'api_username', 'api_password',
        'location', 'site', 'use_ssl', 'verify_cert', 'status_override',
        'last_seen_at', 'monitoring_enabled', 'last_attempted_at',
        'last_routeros_version', 'last_error_category', 'gateway_observations',
        'gateway_observed_at', 'telemetry_token_hash', 'telemetry_snapshot_id',
        'telemetry_observed_at', 'telemetry_received_at', 'latest_telemetry',
    ];

    protected $hidden = ['api_password'];

    protected $casts = [
        'api_port' => 'integer',
        'api_use_tls' => 'boolean',
        'use_ssl' => 'boolean',
        'verify_cert' => 'boolean',
        'last_seen_at' => 'datetime',
        'monitoring_enabled' => 'boolean',
        'last_attempted_at' => 'datetime',
        'api_password' => 'encrypted',
        'gateway_observations' => 'array',
        'gateway_observed_at' => 'datetime',
        'telemetry_observed_at' => 'datetime',
        'telemetry_received_at' => 'datetime',
        'latest_telemetry' => 'array',
    ];

    public function metrics(): HasMany
    {
        return $this->hasMany(RouterMetric::class, 'router_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'router_id');
    }
}

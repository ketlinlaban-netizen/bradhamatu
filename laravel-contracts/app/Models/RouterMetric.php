<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterMetric extends Model
{
    protected $keyType = 'uuid';
    public $incrementing = false;

    protected $fillable = [
        'id', 'router_id', 'cpu_usage', 'memory_usage', 'storage_usage',
        'temperature', 'rx_rate', 'tx_rate', 'recorded_at',
    ];

    protected $casts = [
        'cpu_usage' => 'float',
        'memory_usage' => 'float',
        'storage_usage' => 'float',
        'temperature' => 'float',
        'rx_rate' => 'float',
        'tx_rate' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class, 'router_id');
    }
}

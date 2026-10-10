<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'description', 'price_minor', 'duration_minutes',
        'access_type', 'download_limit_kbps', 'upload_limit_kbps',
        'quota_mb', 'max_devices', 'router_id', 'is_enabled', 'sort_order',
    ];

    protected $casts = [
        'price_minor' => 'integer',
        'duration_minutes' => 'integer',
        'download_limit_kbps' => 'integer',
        'upload_limit_kbps' => 'integer',
        'quota_mb' => 'integer',
        'max_devices' => 'integer',
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class, 'router_id');
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'package_id');
    }

    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price_minor' => $this->price_minor,
            'duration_minutes' => $this->duration_minutes,
            'access_type' => $this->access_type,
            'download_limit_kbps' => $this->download_limit_kbps,
            'upload_limit_kbps' => $this->upload_limit_kbps,
            'quota_mb' => $this->quota_mb,
            'max_devices' => $this->max_devices,
        ];
    }
}

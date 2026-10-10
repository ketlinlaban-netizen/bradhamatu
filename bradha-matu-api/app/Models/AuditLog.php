<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'id', 'actor_type', 'actor_id', 'action',
        'entity_type', 'entity_id', 'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public static function record(string $actorType, ?string $actorId, string $action, ?string $entityType = null, ?string $entityId = null, ?array $details = null): self
    {
        return self::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
        ]);
    }
}

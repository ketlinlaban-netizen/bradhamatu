<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public const STATES = [
        'created', 'initiating', 'pending', 'successful',
        'failed', 'cancelled', 'timed_out', 'reconciliation_required',
    ];

    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'id', 'purchase_id', 'provider', 'provider_request_id',
        'provider_checkout_id', 'provider_reference', 'state',
        'amount_minor', 'phone_normalized', 'idempotency_key',
        'initiated_at', 'completed_at', 'failure_reason',
        'reconciliation_notes', 'sanitized_evidence',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
        'sanitized_evidence' => 'array',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }
}

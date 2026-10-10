<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $fillable = [
        'id', 'customer_id', 'subject', 'body',
        'status', 'priority', 'assigned_to',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}

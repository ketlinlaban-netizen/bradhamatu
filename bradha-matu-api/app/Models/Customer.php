<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['id', 'full_name', 'phone_normalized', 'phone_display', 'email', 'password', 'status', 'terms_accepted_at', 'policy_version', 'balance_minor', 'auto_renewal', 'account_number', 'location', 'notes'])]
#[Hidden(['password'])]
class Customer extends Authenticatable
{
    use HasApiTokens;

    protected $keyType = 'uuid';

    public $incrementing = false;

    protected $casts = [
        'password' => 'hashed',
        'terms_accepted_at' => 'datetime',
        'balance_minor' => 'integer',
        'auto_renewal' => 'boolean',
    ];

    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'customer_id');
    }

    public function serviceAccounts()
    {
        return $this->hasMany(ServiceAccount::class, 'customer_id');
    }

    public function supportTickets()
    {
        return $this->hasMany(SupportTicket::class, 'customer_id');
    }

    public function networkIdentities()
    {
        return $this->hasMany(CustomerNetworkIdentity::class, 'customer_id');
    }
}

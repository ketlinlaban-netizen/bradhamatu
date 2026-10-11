<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadUserGroup extends Model
{
    protected $table = 'radusergroup';

    protected $fillable = [
        'username', 'groupname', 'priority',
    ];
}

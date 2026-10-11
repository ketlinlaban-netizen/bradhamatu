<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadGroupCheck extends Model
{
    protected $table = 'radgroupcheck';

    protected $fillable = [
        'groupname', 'attribute', 'op', 'value',
    ];
}

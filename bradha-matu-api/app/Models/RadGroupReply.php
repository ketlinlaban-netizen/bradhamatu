<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadGroupReply extends Model
{
    protected $table = 'radgroupreply';

    protected $fillable = [
        'groupname', 'attribute', 'op', 'value',
    ];
}

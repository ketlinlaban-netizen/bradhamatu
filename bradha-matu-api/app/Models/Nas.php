<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Nas extends Model
{
    protected $table = 'nas';

    protected $fillable = [
        'nasname', 'shortname', 'type', 'ports',
        'secret', 'server', 'community', 'description',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class, 'nasname', 'ip_address');
    }
}

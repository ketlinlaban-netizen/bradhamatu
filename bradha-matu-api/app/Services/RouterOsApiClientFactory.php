<?php

namespace App\Services;

use App\Models\Router;

interface RouterOsApiClientFactory
{
    /**
     * @param  array{address: string, peer_name: string}  $destination
     */
    public function connect(Router $router, array $destination): RouterOsApiSession;
}

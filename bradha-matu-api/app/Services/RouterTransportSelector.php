<?php

namespace App\Services;

use App\Models\Router;

class RouterTransportSelector implements RouterReadTransport
{
    public function __construct(
        private RouterOsApiTransport $binaryTransport,
        private RouterRestTransport $restTransport,
    ) {}

    public function print(Router $router, string $command, array $args = [], array $queries = []): array
    {
        return $this->forRouter($router)->print($router, $command, $args, $queries);
    }

    public function monitor(Router $router, string $command, array $args = []): array
    {
        return $this->forRouter($router)->monitor($router, $command, $args);
    }

    private function forRouter(Router $router): RouterReadTransport
    {
        return match ($router->api_transport) {
            'binary' => $this->binaryTransport,
            'rest' => $this->restTransport,
            default => throw new RouterApiException(
                'transport_misconfigured',
                'No supported RouterOS transport is selected for this router.',
                httpStatus: 422,
            ),
        };
    }
}

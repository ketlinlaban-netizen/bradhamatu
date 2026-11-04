<?php

namespace App\Services;

use App\Models\Router;

interface RouterReadTransport
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function print(Router $router, string $command, array $args = [], array $queries = []): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monitor(Router $router, string $command, array $args = []): array;
}

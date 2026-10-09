<?php

namespace App\Services;

/**
 * RouterProvider Interface
 *
 * The contract implemented by MikroTikRouterProvider.
 */

interface RouterProvider
{
    public function getRouters(): array;
    public function getRouter(string $routerId): ?array;
    public function getStatus(string $routerId): ?array;
    public function getInterfaces(string $routerId): array;
    public function getClients(string $routerId): array;
    public function getTraffic(string $routerId): ?array;
    public function getWireless(string $routerId): ?array;
    public function getMetrics(string $routerId): array;
    public function getDashboardSummary(): array;
    public function getIncidents(): array;
    public function connect(string $routerId): array;
    public function searchRouters(string $query): array;
}

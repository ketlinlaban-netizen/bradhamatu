<?php

namespace App\Services;

/**
 * RouterProvider Interface
 *
 * The contract that both MockRouterProvider and MikroTikRouterProvider
 * must implement. RouterService depends on this interface — never on
 * a concrete implementation.
 *
 * To migrate from mock to production:
 * 1. Create MikroTikRouterProvider implementing this interface
 * 2. Bind it in a ServiceProvider: $this->app->bind(RouterProvider::class, MikroTikRouterProvider::class)
 * 3. No controllers, frontend, or routes change.
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

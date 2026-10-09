<?php

namespace App\Services;

/**
 * RouterService
 *
 * The single entry point for all router operations. Every call passes
 * through the RouterReadOnlyGuard before reaching the provider.
 *
 * Architecture:
 *   Controller → RouterService → ReadOnlyGuard → Provider
 *                                               └─ MikroTikRouterProvider (live device data)
 *
 * To swap providers, change the binding in a ServiceProvider.
 * Nothing else changes.
 */
class RouterService
{
    private RouterProvider $provider;

    private RouterReadOnlyGuard $guard;

    public function __construct(RouterProvider $provider, ?RouterReadOnlyGuard $guard = null)
    {
        $this->provider = $provider;
        $this->guard = $guard ?? new RouterReadOnlyGuard;
    }

    private function guarded(string $operation, callable $fn): mixed
    {
        $this->guard->enforce($operation);

        return $fn();
    }

    public function getRouters(): array
    {
        return $this->guarded('getRouters', fn () => $this->provider->getRouters());
    }

    public function getRouter(string $routerId): ?array
    {
        return $this->guarded('getRouter', fn () => $this->provider->getRouter($routerId));
    }

    public function getStatus(string $routerId): ?array
    {
        return $this->guarded('getStatus', fn () => $this->provider->getStatus($routerId));
    }

    public function getInterfaces(string $routerId): array
    {
        return $this->guarded('getInterfaces', fn () => $this->provider->getInterfaces($routerId));
    }

    public function getClients(string $routerId): array
    {
        return $this->guarded('getClients', fn () => $this->provider->getClients($routerId));
    }

    public function getTraffic(string $routerId): ?array
    {
        return $this->guarded('getTraffic', fn () => $this->provider->getTraffic($routerId));
    }

    public function getWireless(string $routerId): ?array
    {
        return $this->guarded('getWireless', fn () => $this->provider->getWireless($routerId));
    }

    public function getMetrics(string $routerId): array
    {
        return $this->guarded('getMetrics', fn () => $this->provider->getMetrics($routerId));
    }

    public function getDashboardSummary(): array
    {
        return $this->guarded('getDashboardSummary', fn () => $this->provider->getDashboardSummary());
    }

    public function getIncidents(): array
    {
        return $this->guarded('getIncidents', fn () => $this->provider->getIncidents());
    }

    public function connect(string $routerId): array
    {
        return $this->guarded('connect', fn () => $this->provider->connect($routerId));
    }

    public function searchRouters(string $query): array
    {
        return $this->guarded('searchRouters', fn () => $this->provider->searchRouters($query));
    }
}

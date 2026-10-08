<?php

namespace App\Services;

/**
 * MockRouterProvider (PHP)
 *
 * A Laravel-side mock that returns the same shape of data as
 * MikroTikRouterProvider, but with deterministic fake values.
 * Use this for local development when you don't have real routers.
 *
 * Bind it in RouterServiceProvider instead of MikroTikRouterProvider:
 *   $this->app->bind(RouterProvider::class, MockRouterProvider::class);
 */

class MockRouterProvider implements RouterProvider
{
    private array $routers;

    public function __construct()
    {
        $this->routers = $this->generateRouters();
    }

    public function getRouters(): array
    {
        return $this->routers;
    }

    public function getRouter(string $routerId): ?array
    {
        foreach ($this->routers as $r) {
            if ($r['id'] === $routerId) return $r;
        }
        return null;
    }

    public function getStatus(string $routerId): ?array
    {
        $router = $this->getRouter($routerId);
        if (!$router) return null;

        if ($router['status'] === 'offline') {
            return [
                'routerId' => $routerId,
                'cpuUsage' => 0, 'memoryUsage' => 0, 'storageUsage' => 0,
                'temperature' => 0, 'uptimeSeconds' => 0,
                'boardModel' => '', 'architecture' => '', 'routerosVersion' => '',
                'serialNumber' => '', 'systemIdentity' => '',
                'macAddresses' => [], 'ipAddresses' => [],
                'lastCommunication' => $router['lastSeenAt'],
                'connectionLatencyMs' => 0,
            ];
        }

        return [
            'routerId' => $routerId,
            'cpuUsage' => rand(5, 45),
            'memoryUsage' => rand(20, 60),
            'storageUsage' => rand(10, 40),
            'temperature' => rand(35, 52),
            'uptimeSeconds' => rand(86400, 2592000),
            'boardModel' => $router['model'],
            'architecture' => $router['architecture'],
            'routerosVersion' => $router['routerosVersion'],
            'serialNumber' => $router['serialNumber'],
            'systemIdentity' => $router['identity'],
            'macAddresses' => [$router['macAddress']],
            'ipAddresses' => [$router['ipAddress']],
            'lastCommunication' => now()->toIso8601String(),
            'connectionLatencyMs' => rand(1, 15),
        ];
    }

    public function getInterfaces(string $routerId): array
    {
        $router = $this->getRouter($routerId);
        if (!$router || $router['status'] === 'offline') return [];

        $names = ['ether1', 'ether2', 'ether3', 'wlan1', 'wlan2', 'bridge1'];
        $types = ['ethernet', 'ethernet', 'ethernet', 'wireless', 'wireless', 'bridge'];

        $result = [];
        foreach ($names as $i => $name) {
            $result[] = [
                'id' => "iface-{$routerId}-{$i}",
                'routerId' => $routerId,
                'name' => $name,
                'type' => $types[$i],
                'status' => $i === 2 ? 'down' : 'up',
                'macAddress' => sprintf('48:8F:5A:%02X:%02X:%02X', $i, $i + 1, $i + 2),
                'rxBytes' => rand(1000000, 999999999),
                'txBytes' => rand(1000000, 999999999),
                'ipAddress' => $i === 5 ? $router['ipAddress'] : null,
            ];
        }
        return $result;
    }

    public function getClients(string $routerId): array
    {
        $router = $this->getRouter($routerId);
        if (!$router || $router['status'] === 'offline') return [];

        $count = rand(15, 60);
        $clients = [];
        for ($i = 0; $i < $count; $i++) {
            $clients[] = [
                'id' => "client-{$routerId}-{$i}",
                'routerId' => $routerId,
                'interfaceId' => $i % 2 === 0 ? 'wlan1' : 'wlan2',
                'interfaceName' => $i % 2 === 0 ? 'wlan1' : 'wlan2',
                'hostname' => "device-{$i}",
                'ipAddress' => sprintf('192.168.%d.%d', rand(1, 254), rand(10, 250)),
                'macAddress' => sprintf('AC:DE:48:%02X:%02X:%02X', $i, $i + 1, $i + 2),
                'signal' => rand(-80, -40),
                'rxBytes' => rand(1000, 50000000),
                'txBytes' => rand(1000, 50000000),
                'connectedAt' => now()->subHours(rand(1, 48))->toIso8601String(),
                'lastSeenAt' => now()->toIso8601String(),
            ];
        }
        return $clients;
    }

    public function getTraffic(string $routerId): ?array
    {
        $router = $this->getRouter($routerId);
        if (!$router) return null;

        if ($router['status'] === 'offline') {
            return [
                'routerId' => $routerId,
                'totalRxBytes' => 0, 'totalTxBytes' => 0,
                'currentRxMbps' => 0, 'currentTxMbps' => 0,
                'history' => [],
            ];
        }

        $history = [];
        for ($i = 11; $i >= 0; $i--) {
            $history[] = [
                'time' => now()->subMinutes($i * 5)->format('H:i'),
                'rxMbps' => rand(1, 50) + rand(0, 100) / 100,
                'txMbps' => rand(1, 30) + rand(0, 100) / 100,
            ];
        }

        return [
            'routerId' => $routerId,
            'totalRxBytes' => rand(1000000000, 99999999999),
            'totalTxBytes' => rand(500000000, 99999999999),
            'currentRxMbps' => $history[11]['rxMbps'],
            'currentTxMbps' => $history[11]['txMbps'],
            'history' => $history,
        ];
    }

    public function getWireless(string $routerId): ?array
    {
        $router = $this->getRouter($routerId);
        if (!$router || $router['status'] === 'offline') return null;

        $clients = $this->getClients($routerId);
        $wlanClients = count(array_filter($clients, fn($c) => str_starts_with($c['interfaceName'], 'wlan')));

        return [
            'routerId' => $routerId,
            'ssid' => 'BradhaMatu-Community',
            'frequency' => '2412',
            'channel' => '1',
            'channelWidth' => '20MHz',
            'mode' => 'ap-bridge',
            'signal' => rand(-65, -45),
            'noiseFloor' => rand(-100, -90),
            'connectedClients' => $wlanClients,
            'txRate' => '300Mbps',
            'rxRate' => '300Mbps',
            'protocol' => '802.11n',
            'securityMode' => 'wpa2-psk',
        ];
    }

    public function getMetrics(string $routerId): array
    {
        $status = $this->getStatus($routerId);
        if (!$status) return [];
        $traffic = $this->getTraffic($routerId);

        $metrics = [];
        for ($i = 11; $i >= 0; $i--) {
            $metrics[] = [
                'id' => "metric-{$routerId}-{$i}",
                'routerId' => $routerId,
                'cpuUsage' => $status['cpuUsage'] + rand(-5, 5),
                'memoryUsage' => $status['memoryUsage'] + rand(-3, 3),
                'storageUsage' => $status['storageUsage'],
                'temperature' => $status['temperature'] + rand(-2, 2),
                'rxRate' => $traffic ? $traffic['currentRxMbps'] + rand(-5, 5) : 0,
                'txRate' => $traffic ? $traffic['currentTxMbps'] + rand(-3, 3) : 0,
                'recordedAt' => now()->subMinutes($i * 5)->toIso8601String(),
            ];
        }
        return $metrics;
    }

    public function getDashboardSummary(): array
    {
        $total = count($this->routers);
        $online = count(array_filter($this->routers, fn($r) => $r['status'] === 'online'));
        $offline = count(array_filter($this->routers, fn($r) => $r['status'] === 'offline'));
        $degraded = count(array_filter($this->routers, fn($r) => $r['status'] === 'degraded'));

        $totalClients = 0;
        $totalTraffic = 0;
        foreach ($this->routers as $router) {
            if ($router['status'] !== 'offline') {
                $totalClients += count($this->getClients($router['id']));
                $traffic = $this->getTraffic($router['id']);
                if ($traffic) $totalTraffic += $traffic['totalRxBytes'] + $traffic['totalTxBytes'];
            }
        }

        return [
            'totalRouters' => $total,
            'online' => $online,
            'offline' => $offline,
            'degraded' => $degraded,
            'unknown' => 0,
            'totalClients' => $totalClients,
            'totalTrafficBytes' => $totalTraffic,
            'uptimePercent' => round((($total - $offline) / $total) * 100, 1),
        ];
    }

    public function getIncidents(): array
    {
        $incidents = [];
        $id = 1;
        foreach ($this->routers as $router) {
            if ($router['status'] === 'offline') {
                $incidents[] = [
                    'id' => "inc-{$id++}",
                    'routerId' => $router['id'],
                    'routerName' => $router['name'],
                    'type' => 'offline',
                    'severity' => 'critical',
                    'message' => "Router {$router['name']} is offline.",
                    'startedAt' => $router['lastSeenAt'],
                    'resolvedAt' => null,
                ];
            } elseif ($router['status'] === 'degraded') {
                $incidents[] = [
                    'id' => "inc-{$id++}",
                    'routerId' => $router['id'],
                    'routerName' => $router['name'],
                    'type' => 'degraded',
                    'severity' => 'warning',
                    'message' => "Router {$router['name']} is degraded.",
                    'startedAt' => $router['lastSeenAt'],
                    'resolvedAt' => null,
                ];
            }
        }
        return $incidents;
    }

    public function connect(string $routerId): array
    {
        $router = $this->getRouter($routerId);
        if (!$router) {
            return ['routerId' => $routerId, 'state' => 'failed', 'message' => 'Router not found.', 'readOnly' => true, 'sessionToken' => null];
        }
        return [
            'routerId' => $routerId,
            'state' => 'established',
            'message' => $router['status'] === 'offline'
                ? 'Connection to last-known cache. Router is offline.'
                : 'Connection established. READ-ONLY SESSION.',
            'readOnly' => true,
            'sessionToken' => 'ro-mock-' . substr(md5($routerId . time()), 0, 32),
        ];
    }

    public function searchRouters(string $query): array
    {
        $q = strtolower(trim($query));
        if (!$q) return [];
        return array_values(array_filter($this->routers, function ($r) use ($q) {
            return str_contains(strtolower($r['name']), $q) ||
                   str_contains(strtolower($r['hostname']), $q) ||
                   str_contains($r['ipAddress'], $q) ||
                   str_contains(strtolower($r['location']), $q) ||
                   str_contains(strtolower($r['model']), $q);
        }));
    }

    private function generateRouters(): array
    {
        $locations = [
            ['Mombasa', 'Coast'], ['Malindi', 'Coast'], ['Kilifi', 'Coast'],
            ['Nairobi', 'Central'], ['Kisumu', 'West'], ['Nakuru', 'Rift'],
            ['Eldoret', 'Rift'], ['Garissa', 'East'],
        ];
        $models = ['hAP ax²', 'hEX', 'RB4011', 'RB5009', 'hAP ac²', 'wAP ac'];
        $routers = [];
        $offlineIndices = [2, 10, 17, 22, 5, 14];
        $degradedIndices = [8, 19];

        for ($i = 0; $i < 24; $i++) {
            $loc = $locations[$i % count($locations)];
            $status = in_array($i, $offlineIndices) ? 'offline'
                    : (in_array($i, $degradedIndices) ? 'degraded' : 'online');
            $name = $loc[0] . '-' . str_pad(($i % count($locations)) + 1, 2, '0', STR_PAD_LEFT) . '-' . chr(65 + ($i % 3));

            $routers[] = [
                'id' => 'router-' . str_pad($i + 1, 2, '0', STR_PAD_LEFT),
                'name' => $name,
                'identity' => strtolower(str_replace(' ', '-', $name)),
                'hostname' => sprintf('10.%d.%d.1', intdiv($i, 8) + 1, ($i % 8) + 1),
                'ipAddress' => sprintf('10.%d.%d.1', intdiv($i, 8) + 1, ($i % 8) + 1),
                'macAddress' => sprintf('48:8F:5A:%02X:%02X:%02X', $i, $i + 1, $i + 2),
                'location' => $loc[0],
                'site' => $loc[1],
                'model' => $models[$i % count($models)],
                'serialNumber' => sprintf('BMT%06dX', $i + 1),
                'routerosVersion' => '7.26',
                'architecture' => 'arm64',
                'status' => $status,
                'lastSeenAt' => $status === 'offline'
                    ? now()->subHours(rand(2, 72))->toIso8601String()
                    : now()->toIso8601String(),
                'createdAt' => now()->subDays(rand(30, 365))->toIso8601String(),
                'updatedAt' => now()->toIso8601String(),
            ];
        }
        return $routers;
    }
}

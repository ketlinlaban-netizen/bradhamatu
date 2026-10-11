<?php

namespace App\Services;

class MockRouterProvider implements RouterProvider
{
    private const ONLINE_ID = 'demo-router-online';

    private const OFFLINE_ID = 'demo-router-offline';

    private function routers(): array
    {
        return [
            [
                'id' => self::ONLINE_ID,
                'name' => 'Demo Router — Community Hall',
                'identity' => 'demo-community-hall',
                'hostname' => '192.0.2.10',
                'ipAddress' => '192.0.2.10',
                'macAddress' => '02:00:00:00:00:10',
                'location' => 'Demo location',
                'site' => 'Training network (fictional)',
                'model' => 'Demo RouterOS device',
                'serialNumber' => 'DEMO-00010',
                'routerosVersion' => '7.16 (demo)',
                'architecture' => 'arm (demo)',
                'uptimeSeconds' => 864000,
                'status' => 'online',
                'lastSeenAt' => now()->subSeconds(20)->toIso8601String(),
                'createdAt' => now()->subDays(30)->toIso8601String(),
                'updatedAt' => now()->subSeconds(20)->toIso8601String(),
                'dataSource' => 'mock',
            ],
            [
                'id' => self::OFFLINE_ID,
                'name' => 'Demo Router — Remote Site',
                'identity' => 'demo-remote-site',
                'hostname' => '198.51.100.20',
                'ipAddress' => '198.51.100.20',
                'macAddress' => '02:00:00:00:00:20',
                'location' => 'Demo remote site',
                'site' => 'Training network (fictional)',
                'model' => 'Demo RouterOS device',
                'serialNumber' => 'DEMO-00020',
                'routerosVersion' => '7.16 (demo)',
                'architecture' => 'arm (demo)',
                'uptimeSeconds' => null,
                'status' => 'offline',
                'lastSeenAt' => now()->subMinutes(12)->toIso8601String(),
                'createdAt' => now()->subDays(20)->toIso8601String(),
                'updatedAt' => now()->subMinutes(12)->toIso8601String(),
                'dataSource' => 'mock',
            ],
        ];
    }

    private function known(string $routerId): bool
    {
        return in_array($routerId, [self::ONLINE_ID, self::OFFLINE_ID], true);
    }

    public function getRouters(): array
    {
        return $this->routers();
    }

    public function getRouter(string $routerId): ?array
    {
        foreach ($this->routers() as $router) {
            if ($router['id'] === $routerId) {
                return $router;
            }
        }

        return null;
    }

    public function getStatus(string $routerId): ?array
    {
        if (! $this->known($routerId) || $routerId === self::OFFLINE_ID) {
            return null;
        }

        return [
            'routerId' => self::ONLINE_ID,
            'dataSource' => 'mock',
            'cpuUsage' => 18.0,
            'memoryUsage' => 42.5,
            'storageUsage' => 31.0,
            'temperature' => 39.0,
            'uptimeSeconds' => 864000,
            'boardModel' => 'Demo RouterOS device',
            'architecture' => 'arm (demo)',
            'routerosVersion' => '7.16 (demo)',
            'serialNumber' => 'DEMO-00010',
            'systemIdentity' => 'demo-community-hall',
            'macAddresses' => ['02:00:00:00:00:10'],
            'ipAddresses' => ['192.0.2.10/24'],
            'lastCommunication' => now()->subSeconds(20)->toIso8601String(),
            'connectionLatencyMs' => 8,
            'dataSource' => 'mock',
        ];
    }

    public function getInterfaces(string $routerId): array
    {
        if (! $this->known($routerId) || $routerId === self::OFFLINE_ID) {
            return [];
        }

        return [
            [
                'id' => 'demo-if-ether1',
                'routerId' => self::ONLINE_ID,
                'name' => 'ether1-wan',
                'type' => 'ethernet',
                'status' => 'up',
                'macAddress' => '02:00:00:00:01:01',
                'rxBytes' => 381204812,
                'txBytes' => 120847221,
                'ipAddress' => '192.0.2.10/24',
                'dataSource' => 'mock',
            ],
            [
                'id' => 'demo-if-bridge',
                'routerId' => self::ONLINE_ID,
                'name' => 'bridge-lan',
                'type' => 'bridge',
                'status' => 'up',
                'macAddress' => '02:00:00:00:01:02',
                'rxBytes' => 190221884,
                'txBytes' => 302817744,
                'ipAddress' => '10.20.0.1/24',
                'dataSource' => 'mock',
            ],
            [
                'id' => 'demo-if-ether3',
                'routerId' => self::ONLINE_ID,
                'name' => 'ether3-spare',
                'type' => 'ethernet',
                'status' => 'down',
                'macAddress' => '02:00:00:00:01:03',
                'rxBytes' => 0,
                'txBytes' => 0,
                'ipAddress' => null,
                'dataSource' => 'mock',
            ],
        ];
    }

    public function getClients(string $routerId): array
    {
        if (! $this->known($routerId) || $routerId === self::OFFLINE_ID) {
            return [];
        }

        return [
            [
                'id' => 'demo-client-001',
                'routerId' => self::ONLINE_ID,
                'interfaceId' => 'demo-if-bridge',
                'interfaceName' => 'bridge-lan',
                'hostname' => 'demo-phone',
                'ipAddress' => '10.20.0.21',
                'macAddress' => '02:00:00:10:00:21',
                'signal' => -54,
                'rxBytes' => 4820012,
                'txBytes' => 9021104,
                'connectedAt' => now()->subHours(2)->toIso8601String(),
                'lastSeenAt' => now()->subSeconds(15)->toIso8601String(),
                'dataSource' => 'mock',
            ],
            [
                'id' => 'demo-client-002',
                'routerId' => self::ONLINE_ID,
                'interfaceId' => 'demo-if-bridge',
                'interfaceName' => 'bridge-lan',
                'hostname' => 'demo-laptop',
                'ipAddress' => '10.20.0.22',
                'macAddress' => '02:00:00:10:00:22',
                'signal' => -67,
                'rxBytes' => 1920001,
                'txBytes' => 6402990,
                'connectedAt' => now()->subMinutes(44)->toIso8601String(),
                'lastSeenAt' => now()->subSeconds(35)->toIso8601String(),
                'dataSource' => 'mock',
            ],
        ];
    }

    public function getDhcpLeases(string $routerId): array
    {
        if (! $this->known($routerId) || $routerId === self::OFFLINE_ID) {
            return [];
        }

        return [
            [
                'id' => 'demo-lease-001',
                'routerId' => self::ONLINE_ID,
                'hostname' => 'demo-customer-router',
                'ipAddress' => '10.20.0.21',
                'macAddress' => '02:00:00:10:00:21',
                'interfaceName' => 'bridge-lan',
                'leaseStatus' => 'bound',
                'lastSeenAt' => now()->subSeconds(15)->toIso8601String(),
            ],
        ];
    }

    public function getTraffic(string $routerId): ?array
    {
        if (! $this->known($routerId) || $routerId === self::OFFLINE_ID) {
            return null;
        }

        $history = [];
        foreach (range(5, 0) as $minutesAgo) {
            $time = now()->subMinutes($minutesAgo);
            $history[] = [
                'time' => $time->format('H:i'),
                'rxMbps' => 12.4 + (5 - $minutesAgo) * 0.7,
                'txMbps' => 4.8 + (5 - $minutesAgo) * 0.25,
            ];
        }

        return [
            'routerId' => self::ONLINE_ID,
            'totalRxBytes' => 571426696,
            'totalTxBytes' => 423664965,
            'currentRxMbps' => 15.9,
            'currentTxMbps' => 6.05,
            'history' => $history,
            'dataSource' => 'mock',
        ];
    }

    public function getWireless(string $routerId): ?array
    {
        if (! $this->known($routerId) || $routerId === self::OFFLINE_ID) {
            return null;
        }

        return [
            'routerId' => self::ONLINE_ID,
            'ssid' => 'Bradha-Matu-DEMO',
            'frequency' => '2437 MHz',
            'channel' => '6',
            'channelWidth' => '20 MHz',
            'mode' => 'ap-bridge',
            'signal' => -49,
            'noiseFloor' => -110,
            'connectedClients' => 2,
            'txRate' => '144.4 Mbps',
            'rxRate' => '130 Mbps',
            'protocol' => '802.11n',
            'securityMode' => 'wpa2-psk (demo)',
            'dataSource' => 'mock',
        ];
    }

    public function getMetrics(string $routerId): array
    {
        $status = $this->getStatus($routerId);
        $traffic = $this->getTraffic($routerId);
        if (! $status || ! $traffic) {
            return [];
        }

        return collect(range(3, 0))->map(fn (int $minutesAgo) => [
            'id' => "demo-metric-{$minutesAgo}",
            'routerId' => $routerId,
            'cpuUsage' => 15.0 + $minutesAgo,
            'memoryUsage' => 42.5,
            'storageUsage' => 31.0,
            'temperature' => 39.0,
            'rxRate' => max(1, $traffic['currentRxMbps'] - $minutesAgo * 0.8),
            'txRate' => max(1, $traffic['currentTxMbps'] - $minutesAgo * 0.3),
            'recordedAt' => now()->subMinutes($minutesAgo)->toIso8601String(),
            'dataSource' => 'mock',
        ])->all();
    }

    public function getDashboardSummary(): array
    {
        return [
            'totalRouters' => 2,
            'online' => 1,
            'offline' => 1,
            'degraded' => 0,
            'unknown' => 0,
            'totalClients' => 2,
            'totalTrafficBytes' => 995091661,
            'uptimePercent' => 50.0,
            'dataSource' => 'mock',
        ];
    }

    public function getIncidents(): array
    {
        return [[
            'id' => 'demo-incident-offline',
            'routerId' => self::OFFLINE_ID,
            'routerName' => 'Demo Router — Remote Site',
            'type' => 'offline',
            'severity' => 'critical',
            'message' => 'Demo incident: the fictional remote router is offline.',
            'startedAt' => now()->subMinutes(12)->toIso8601String(),
            'resolvedAt' => null,
            'dataSource' => 'mock',
        ]];
    }

    public function connect(string $routerId): array
    {
        $router = $this->getRouter($routerId);
        if (! $router || $router['status'] === 'offline') {
            return [
                'routerId' => $routerId,
                'state' => 'failed',
                'message' => $router ? 'Demo result: this fictional router is offline.' : 'Demo router not found.',
                'readOnly' => true,
                'sessionToken' => null,
                'dataSource' => 'mock',
            ];
        }

        return [
            'routerId' => $routerId,
            'state' => 'established',
            'message' => 'Demo result only. No physical router connection was attempted.',
            'readOnly' => true,
            'sessionToken' => null,
            'dataSource' => 'mock',
        ];
    }

    public function searchRouters(string $query): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        return array_values(array_filter(
            $this->routers(),
            fn (array $router): bool => str_contains(mb_strtolower(json_encode($router) ?: ''), $query),
        ));
    }
}

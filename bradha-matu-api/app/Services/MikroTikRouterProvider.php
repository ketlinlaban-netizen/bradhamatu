<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * MikroTikRouterProvider
 *
 * Implements the shared RouterProvider data mapping over an allowlisted
 * RouterOS read transport.
 */
class MikroTikRouterProvider implements RouterProvider
{
    private const API_PORT_PLAIN = 8728;

    private const API_PORT_TLS = 8729;

    private array $routersConfig;

    public function __construct(private RouterReadTransport $transport)
    {
        $this->routersConfig = Router::query()
            ->where('monitoring_enabled', true)
            ->get()
            ->map(fn (Router $router) => [
                'id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
                'api_username' => $router->api_username,
                'api_password' => $router->api_password,
                'api_port' => $router->api_port ?: ($router->api_use_tls ? self::API_PORT_TLS : self::API_PORT_PLAIN),
                'api_use_tls' => $router->api_use_tls,
                'verify_cert' => $router->verify_cert,
                'location' => $router->location,
                'site' => $router->site,
                'model' => $router,
                'created_at' => $router->created_at?->toIso8601String(),
            ])
            ->all();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Transport layer — allowlisted native binary API only
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Execute a print command on the router.
     * Delegates only fixed read-only command paths to the binary API transport.
     *
     * @param  string  $command  Exact RouterOS read-only command path
     * @param  array  $args  attribute / proplist args
     * @param  array  $queries  binary API query words
     * @return array<int, array<string, string>>
     */
    private function query(string $routerId, string $command, array $args = [], array $queries = []): array
    {
        $router = $this->findRouter($routerId);
        if (! $router) {
            return [];
        }

        $model = $router['model'];
        $cacheKey = 'routeros:print:'.hash('sha256', json_encode([
            $model->id,
            $model->updated_at?->getTimestamp(),
            $command,
            $args,
            $queries,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, now()->addSeconds(15), fn () => $this->transport->print(
            $model,
            $command,
            $args,
            $queries,
        ));
    }

    /**
     * Execute a read-only monitor command such as /interface/monitor-traffic.
     */
    private function monitor(string $routerId, string $command, array $args = []): array
    {
        $router = $this->findRouter($routerId);
        if (! $router) {
            return [];
        }

        $model = $router['model'];
        $cacheKey = 'routeros:monitor:'.hash('sha256', json_encode([
            $model->id,
            $model->updated_at?->getTimestamp(),
            $command,
            $args,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, now()->addSeconds(3), fn () => $this->transport->monitor(
            $model,
            $command,
            $args,
        ));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════════════════════════

    private function findRouter(string $routerId): ?array
    {
        foreach ($this->routersConfig as $router) {
            if ($router['id'] === $routerId) {
                return $router;
            }
        }

        return null;
    }

    private function parseUptime(string $uptime): int
    {
        $seconds = 0;
        preg_match_all('/(\d+)([wdhms])/', $uptime, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $value = (int) $match[1];
            $unit = $match[2];
            $seconds += match ($unit) {
                'w' => $value * 604800,
                'd' => $value * 86400,
                'h' => $value * 3600,
                'm' => $value * 60,
                's' => $value,
                default => 0,
            };
        }

        return $seconds;
    }

    private function parseBytes(string|int|null $value): int
    {
        if ($value === null) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * Normalize binary API response item attributes.
     */
    private function normalizeItem(array $item): array
    {
        return $item;
    }

    private function normalizeItems(?array $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_map(fn ($item) => $this->normalizeItem($item), $items);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  RouterProvider interface
    // ═══════════════════════════════════════════════════════════════════

    public function getRouters(): array
    {
        return array_map(fn (array $cfg) => $this->routerSummary($cfg), $this->routersConfig);
    }

    public function getRouter(string $routerId): ?array
    {
        $cfg = $this->findRouter($routerId);
        if (! $cfg) {
            return null;
        }

        return $this->routerSummary($cfg);
    }

    public function getStatus(string $routerId): ?array
    {
        $resource = $this->query($routerId, '/system/resource/print');
        if (! $resource || empty($resource)) {
            return null;
        }
        $res = $resource[0];

        $identity = $this->query($routerId, '/system/identity/print');
        $interfaces = $this->query($routerId, '/interface/print', [
            '.proplist' => 'name,mac-address,type',
        ]);
        $ipAddresses = $this->query($routerId, '/ip/address/print', [
            '.proplist' => 'address,interface',
        ]);

        $macAddresses = [];
        foreach ($this->normalizeItems($interfaces) as $iface) {
            if (! empty($iface['mac-address'])) {
                $macAddresses[] = $iface['mac-address'];
            }
        }

        $ipList = [];
        foreach ($this->normalizeItems($ipAddresses) as $ip) {
            if (! empty($ip['address'])) {
                $ipList[] = $ip['address'];
            }
        }

        $totalMemory = isset($res['total-memory']) ? $this->parseBytes($res['total-memory']) : 0;
        $freeMemory = isset($res['free-memory']) ? $this->parseBytes($res['free-memory']) : null;
        $totalHdd = isset($res['total-hdd-space']) ? $this->parseBytes($res['total-hdd-space']) : 0;
        $freeHdd = isset($res['free-hdd-space']) ? $this->parseBytes($res['free-hdd-space']) : null;

        $memoryUsage = $totalMemory > 0 && $freeMemory !== null
            ? round((($totalMemory - $freeMemory) / $totalMemory) * 100, 1)
            : null;
        $storageUsage = $totalHdd > 0 && $freeHdd !== null
            ? round((($totalHdd - $freeHdd) / $totalHdd) * 100, 1)
            : null;

        return [
            'routerId' => $routerId,
            'cpuUsage' => isset($res['cpu-load']) ? (float) $res['cpu-load'] : null,
            'memoryUsage' => $memoryUsage,
            'storageUsage' => $storageUsage,
            'temperature' => null,
            'uptimeSeconds' => isset($res['uptime']) ? $this->parseUptime($res['uptime']) : null,
            'boardModel' => $res['board-name'] ?? '',
            'architecture' => $res['architecture'] ?? '',
            'routerosVersion' => $res['version'] ?? '',
            'serialNumber' => $res['serial-number'] ?? '',
            'systemIdentity' => $identity[0]['name'] ?? '',
            'macAddresses' => $macAddresses,
            'ipAddresses' => $ipList,
            'lastCommunication' => now()->toIso8601String(),
            'connectionLatencyMs' => null,
            'dataSource' => 'live',
        ];
    }

    public function getInterfaces(string $routerId): array
    {
        $interfaces = $this->query($routerId, '/interface/print', [
            '.proplist' => '.id,name,type,running,disabled,mac-address,rx-byte,tx-byte,mtu',
        ]);
        if (! $interfaces) {
            return [];
        }

        $result = [];
        foreach ($this->normalizeItems($interfaces) as $iface) {
            $type = $iface['type'] ?? 'unknown';
            $mappedType = match ($type) {
                'ether' => 'ethernet',
                'wlan', 'wifi', 'wireless' => 'wireless',
                'bridge' => 'bridge',
                'vlan' => 'vlan',
                'ppp', 'pppoe', 'pppoe-client' => 'ppp',
                default => 'ethernet',
            };

            $running = ($iface['running'] ?? 'false') === 'true';
            $disabled = ($iface['disabled'] ?? 'false') === 'true';
            $status = $disabled ? 'disabled' : ($running ? 'up' : 'down');

            $ipAddr = $this->query($routerId, '/ip/address/print', [
                '.proplist' => 'address',
            ], [
                "?interface={$iface['name']}",
            ]);
            $ipString = null;
            if ($ipAddr && ! empty($ipAddr) && isset($ipAddr[0]['address'])) {
                $ipString = $ipAddr[0]['address'];
            }

            $result[] = [
                'id' => $iface['.id'] ?? Str::uuid()->toString(),
                'routerId' => $routerId,
                'name' => $iface['name'] ?? '',
                'type' => $mappedType,
                'status' => $status,
                'macAddress' => $iface['mac-address'] ?? '',
                'rxBytes' => $this->parseBytes($iface['rx-byte'] ?? '0'),
                'txBytes' => $this->parseBytes($iface['tx-byte'] ?? '0'),
                'ipAddress' => $ipString,
            ];
        }

        return $result;
    }

    public function getClients(string $routerId): array
    {
        $registrations = $this->query($routerId, '/interface/wireless/registration-table/print', [
            '.proplist' => '.id,interface,mac-address,ap,signal-strength,rx-rate,tx-rate,uptime,bytes',
        ]);
        $leases = $this->query($routerId, '/ip/dhcp-server/lease/print', [
            '.proplist' => '.id,address,mac-address,host-name,interface,status',
        ]);

        $leaseMap = [];
        foreach ($this->normalizeItems($leases) as $lease) {
            $mac = $lease['mac-address'] ?? '';
            if ($mac) {
                $leaseMap[strtolower($mac)] = $lease;
            }
        }

        $clients = [];
        foreach ($this->normalizeItems($registrations) as $reg) {
            $mac = $reg['mac-address'] ?? '';
            $lease = $mac ? ($leaseMap[strtolower($mac)] ?? null) : null;
            $bytesParts = explode(',', $reg['bytes'] ?? '0,0');

            $clients[] = [
                'id' => $reg['.id'] ?? Str::uuid()->toString(),
                'routerId' => $routerId,
                'interfaceId' => $reg['interface'] ?? '',
                'interfaceName' => $reg['interface'] ?? '',
                'hostname' => $lease['host-name'] ?? '',
                'ipAddress' => $lease['address'] ?? '',
                'macAddress' => $mac,
                'connectionType' => 'wireless',
                'signal' => (int) ($reg['signal-strength'] ?? 0),
                'rxBytes' => $this->parseBytes($bytesParts[0] ?? '0'),
                'txBytes' => $this->parseBytes($bytesParts[1] ?? '0'),
                'connectedAt' => now()->toIso8601String(),
                'lastSeenAt' => now()->toIso8601String(),
            ];
        }

        return $clients;
    }

    public function getDhcpLeases(string $routerId): array
    {
        return array_map(function (array $lease) use ($routerId): array {
            return [
                'id' => (string) ($lease['.id'] ?? ''),
                'routerId' => $routerId,
                'hostname' => (string) ($lease['host-name'] ?? ''),
                'ipAddress' => (string) ($lease['address'] ?? ''),
                'macAddress' => strtoupper((string) ($lease['mac-address'] ?? '')),
                'interfaceName' => (string) ($lease['interface'] ?? ''),
                'leaseStatus' => (string) ($lease['status'] ?? 'unknown'),
                'lastSeenAt' => $lease['last-seen'] ?? null,
            ];
        }, $this->query($routerId, '/ip/dhcp-server/lease/print', [
            '.proplist' => '.id,address,mac-address,host-name,interface,status,last-seen',
        ]));
    }

    public function getTraffic(string $routerId): ?array
    {
        $traffic = $this->monitor($routerId, '/interface/monitor-traffic', [
            'interface' => 'all',
            '.proplist' => 'name,rx-bits-per-second,tx-bits-per-second',
        ]);
        if ($traffic === []) {
            return null;
        }

        $currentRx = 0;
        $currentTx = 0;
        if (is_array($traffic)) {
            foreach ($traffic as $t) {
                $currentRx += (int) ($t['rx-bits-per-second'] ?? '0');
                $currentTx += (int) ($t['tx-bits-per-second'] ?? '0');
            }
        }

        $interfaces = $this->query($routerId, '/interface/print', [
            '.proplist' => 'rx-byte,tx-byte',
        ]);
        $totalRx = 0;
        $totalTx = 0;
        if (is_array($interfaces)) {
            foreach ($interfaces as $iface) {
                $totalRx += $this->parseBytes($iface['rx-byte'] ?? '0');
                $totalTx += $this->parseBytes($iface['tx-byte'] ?? '0');
            }
        }

        $history = [[
            'time' => now()->format('H:i'),
            'rxMbps' => round($currentRx / 1_000_000, 2),
            'txMbps' => round($currentTx / 1_000_000, 2),
        ]];

        return [
            'routerId' => $routerId,
            'totalRxBytes' => $totalRx,
            'totalTxBytes' => $totalTx,
            'currentRxMbps' => round($currentRx / 1_000_000, 2),
            'currentTxMbps' => round($currentTx / 1_000_000, 2),
            'history' => $history,
        ];
    }

    public function getWireless(string $routerId): ?array
    {
        $wireless = $this->query($routerId, '/interface/wireless/print');
        if (! $wireless || empty($wireless)) {
            return null;
        }
        $wlan = $wireless[0];

        $registrations = $this->query($routerId, '/interface/wireless/registration-table/print');
        $clientCount = is_array($registrations) ? count($registrations) : 0;

        return [
            'routerId' => $routerId,
            'ssid' => $wlan['ssid'] ?? '',
            'frequency' => $wlan['frequency'] ?? '',
            'channel' => $wlan['channel'] ?? '',
            'channelWidth' => $wlan['channel-width'] ?? '',
            'mode' => $wlan['mode'] ?? '',
            'signal' => 0,
            'noiseFloor' => (int) ($wlan['noise-floor'] ?? 0),
            'connectedClients' => $clientCount,
            'txRate' => $wlan['tx-rate'] ?? '',
            'rxRate' => $wlan['rx-rate'] ?? '',
            'protocol' => $wlan['protocol'] ?? '',
            'securityMode' => $wlan['security-profile'] ?? '',
        ];
    }

    public function getMetrics(string $routerId): array
    {
        $status = $this->getStatus($routerId);
        if (! $status) {
            return [];
        }
        $traffic = $this->getTraffic($routerId);

        return [[
            'id' => Str::uuid()->toString(),
            'routerId' => $routerId,
            'cpuUsage' => $status['cpuUsage'],
            'memoryUsage' => $status['memoryUsage'],
            'storageUsage' => $status['storageUsage'],
            'temperature' => $status['temperature'],
            'rxRate' => $traffic['currentRxMbps'] ?? 0,
            'txRate' => $traffic['currentTxMbps'] ?? 0,
            'recordedAt' => now()->toIso8601String(),
        ]];
    }

    public function getDashboardSummary(): array
    {
        $routers = $this->getRouters();
        $total = count($routers);
        $online = count(array_filter($routers, fn ($r) => $r['status'] === 'online'));
        $offline = count(array_filter($routers, fn ($r) => $r['status'] === 'offline'));
        $degraded = count(array_filter($routers, fn ($r) => $r['status'] === 'degraded'));
        $unknown = count(array_filter($routers, fn ($r) => $r['status'] === 'unknown'));

        $totalClients = 0;
        $totalTraffic = 0;
        foreach ($routers as $router) {
            if ($router['status'] !== 'offline') {
                $totalClients += count($this->getClients($router['id']));
                $traffic = $this->getTraffic($router['id']);
                if ($traffic) {
                    $totalTraffic += $traffic['totalRxBytes'] + $traffic['totalTxBytes'];
                }
            }
        }

        $uptimePercent = $total > 0 ? round((($total - $offline) / $total) * 100, 1) : 0;

        return [
            'totalRouters' => $total,
            'online' => $online,
            'offline' => $offline,
            'degraded' => $degraded,
            'unknown' => $unknown,
            'totalClients' => $totalClients,
            'totalTrafficBytes' => $totalTraffic,
            'uptimePercent' => $uptimePercent,
            'dataSource' => 'live',
        ];
    }

    public function getIncidents(): array
    {
        $routers = $this->getRouters();
        $incidents = [];
        $id = 1;

        foreach ($routers as $router) {
            if ($router['status'] === 'offline') {
                $incidents[] = [
                    'id' => 'inc-'.$id++,
                    'routerId' => $router['id'],
                    'routerName' => $router['name'],
                    'type' => 'offline',
                    'severity' => 'critical',
                    'message' => "Router {$router['name']} is offline and unreachable.",
                    'startedAt' => $router['lastSeenAt'] ?? now()->toIso8601String(),
                    'resolvedAt' => null,
                ];
            } elseif ($router['status'] === 'degraded') {
                $incidents[] = [
                    'id' => 'inc-'.$id++,
                    'routerId' => $router['id'],
                    'routerName' => $router['name'],
                    'type' => 'degraded',
                    'severity' => 'warning',
                    'message' => "Router {$router['name']} is in a degraded state (high CPU).",
                    'startedAt' => $router['lastSeenAt'] ?? now()->toIso8601String(),
                    'resolvedAt' => null,
                ];
            }
        }

        return $incidents;
    }

    public function connect(string $routerId): array
    {
        $router = $this->findRouter($routerId);
        if (! $router) {
            return [
                'routerId' => $routerId,
                'state' => 'failed',
                'message' => 'Router not found in configuration.',
                'readOnly' => true,
                'sessionToken' => null,
            ];
        }

        $identity = $this->query($routerId, '/system/identity/print');
        $resources = $this->query($routerId, '/system/resource/print');
        if ($identity === [] || $resources === []) {
            return [
                'routerId' => $routerId,
                'state' => 'failed',
                'message' => 'Router did not return identity and system resource data.',
                'readOnly' => true,
                'sessionToken' => null,
                'dataSource' => 'live',
            ];
        }

        $router['model']->forceFill([
            'last_seen_at' => now(),
            'last_routeros_version' => $resources[0]['version'] ?? null,
            'status_override' => 'online',
            'last_error_category' => null,
        ])->save();

        return [
            'routerId' => $routerId,
            'state' => 'established',
            'message' => 'Read-only API connectivity test succeeded. The short-lived connection has been closed.',
            'readOnly' => true,
            'sessionToken' => null,
            'dataSource' => 'live',
        ];
    }

    public function searchRouters(string $query): array
    {
        $routers = $this->getRouters();
        $q = strtolower(trim($query));
        if (! $q) {
            return [];
        }

        return array_values(array_filter($routers, function ($r) use ($q) {
            return str_contains(strtolower($r['name']), $q) ||
                   str_contains(strtolower($r['hostname']), $q) ||
                   str_contains($r['ipAddress'], $q) ||
                   str_contains(strtolower($r['macAddress']), $q) ||
                   str_contains(strtolower($r['location']), $q) ||
                   str_contains(strtolower($r['site']), $q) ||
                   str_contains(strtolower($r['serialNumber']), $q) ||
                   str_contains(strtolower($r['model']), $q) ||
                   str_contains(strtolower($r['status']), $q);
        }));
    }

    private function getFirstMacAddress(string $routerId): string
    {
        $interfaces = $this->query($routerId, '/interface/print', [
            '.proplist' => 'mac-address,type',
        ], [
            '?type=ether',
        ]);
        if ($interfaces && ! empty($interfaces) && isset($interfaces[0]['mac-address'])) {
            return $interfaces[0]['mac-address'];
        }

        return '';
    }

    private function routerSummary(array $cfg): array
    {
        $status = 'unknown';
        $identityName = $cfg['name'];
        $resource = [];
        $macAddress = '';
        $errorCategory = $cfg['model']->last_error_category;

        try {
            $identity = $this->query($cfg['id'], '/system/identity/print');
            $resources = $this->query($cfg['id'], '/system/resource/print');
            if ($resources !== []) {
                $resource = $resources[0];
                $status = isset($resource['cpu-load']) && (int) $resource['cpu-load'] > 90
                    ? 'degraded'
                    : 'online';
                $identityName = $identity[0]['name'] ?? $identityName;
                $macAddress = $this->getFirstMacAddress($cfg['id']);
                $errorCategory = null;
            } else {
                $errorCategory = 'empty_response';
            }
        } catch (RouterApiException $exception) {
            $status = 'offline';
            $errorCategory = $exception->category;
        }

        return [
            'id' => $cfg['id'],
            'name' => $cfg['name'],
            'identity' => $identityName,
            'hostname' => $cfg['ip_address'],
            'ipAddress' => $cfg['ip_address'],
            'macAddress' => $macAddress,
            'location' => $cfg['location'] ?? '',
            'site' => $cfg['site'] ?? '',
            'model' => $resource['board-name'] ?? null,
            'serialNumber' => $resource['serial-number'] ?? null,
            'routerosVersion' => $resource['version'] ?? $cfg['model']->last_routeros_version,
            'architecture' => $resource['architecture'] ?? null,
            'uptimeSeconds' => isset($resource['uptime'])
                ? $this->parseUptime($resource['uptime'])
                : null,
            'status' => $status,
            'lastErrorCategory' => $errorCategory,
            'lastSeenAt' => $status !== 'offline' ? now()->toIso8601String() : $cfg['model']->last_seen_at?->toIso8601String(),
            'createdAt' => $cfg['created_at'] ?? now()->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
            'dataSource' => 'live',
        ];
    }
}

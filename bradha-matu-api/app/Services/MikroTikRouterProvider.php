<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Carbon;
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

    public function __construct(
        private RouterReadTransport $transport,
        private RouterTelemetryService $telemetry,
    ) {
        $this->routersConfig = Router::query()
            ->where('monitoring_enabled', true)
            ->get()
            ->map(fn (Router $router) => [
                'id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
                'api_username' => $router->api_username,
                'api_password' => $router->api_password,
                'api_transport' => $router->api_transport,
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
                if ($router['api_transport'] === 'telemetry') {
                    $model = Router::query()
                        ->whereKey($routerId)
                        ->where('monitoring_enabled', true)
                        ->first();
                    if (! $model) {
                        return null;
                    }
                    $router['model'] = $model;
                    $router['name'] = $model->name;
                    $router['identity'] = $model->identity;
                    $router['location'] = $model->location;
                    $router['site'] = $model->site;
                    $router['created_at'] = $model->created_at?->toIso8601String();
                }

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
        $routerConfig = $this->findRouter($routerId);
        if (! $routerConfig) {
            return null;
        }
        if ($routerConfig['api_transport'] === 'telemetry') {
            return $this->telemetry->status($routerConfig['model']);
        }

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
        $routes = $this->query($routerId, '/ip/route/print', [
            '.proplist' => '.id,dst-address,gateway,immediate-gw,distance,active,dynamic,static,disabled,routing-table',
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

        $gatewayRoutes = $this->defaultRouteObservations($this->normalizeItems($routes));
        $gatewayObservedAt = now();
        $routerConfig['model']->forceFill([
            'gateway_observations' => $gatewayRoutes,
            'gateway_observed_at' => $gatewayObservedAt,
        ])->save();

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
            'gatewayRoutes' => $gatewayRoutes,
            'gatewayObservedAt' => $gatewayObservedAt->toIso8601String(),
            'lastCommunication' => now()->toIso8601String(),
            'connectionLatencyMs' => null,
            'dataSource' => 'live',
        ];
    }

    /**
     * Keep every default route, including equal-cost active alternatives.
     * A gateway observation never changes the configured management address.
     *
     * @param  array<int, array<string, string>>  $routes
     * @return array<int, array<string, mixed>>
     */
    private function defaultRouteObservations(array $routes): array
    {
        $defaults = array_values(array_filter(
            $routes,
            fn (array $route): bool => ($route['dst-address'] ?? null) === '0.0.0.0/0',
        ));

        $observations = array_map(function (array $route): array {
            $immediateGateway = $route['immediate-gw'] ?? null;
            $gateway = $route['gateway'] ?? null;
            $gatewayInterface = null;
            if (is_string($immediateGateway) && str_contains($immediateGateway, '%')) {
                [, $gatewayInterface] = explode('%', $immediateGateway, 2);
            } elseif (is_string($gateway) && str_contains($gateway, '%')) {
                [, $gatewayInterface] = explode('%', $gateway, 2);
            }

            return [
                'routeId' => $route['.id'] ?? null,
                'gateway' => $gateway,
                'immediateGateway' => $immediateGateway,
                'interface' => $gatewayInterface,
                'distance' => isset($route['distance']) ? (int) $route['distance'] : null,
                'routingTable' => $route['routing-table'] ?? 'main',
                'active' => $this->routerOsFlag($route['active'] ?? false),
                'dynamic' => $this->routerOsFlag($route['dynamic'] ?? false),
                'static' => $this->routerOsFlag($route['static'] ?? false),
                'disabled' => $this->routerOsFlag($route['disabled'] ?? false),
            ];
        }, $defaults);

        usort($observations, function (array $left, array $right): int {
            return [
                $left['routingTable'],
                $left['distance'] ?? PHP_INT_MAX,
                $left['gateway'] ?? '',
            ] <=> [
                $right['routingTable'],
                $right['distance'] ?? PHP_INT_MAX,
                $right['gateway'] ?? '',
            ];
        });

        $activeRouteIndexes = array_keys(array_filter(
            $observations,
            fn (array $route): bool => $route['active'] && ! $route['disabled'],
        ));
        foreach ($activeRouteIndexes as $index) {
            $route = $observations[$index];
            $observations[$index]['ecmp'] = count(array_filter(
                $observations,
                fn (array $candidate): bool => $candidate['active']
                    && ! $candidate['disabled']
                    && $candidate['routingTable'] === $route['routingTable']
                    && $candidate['distance'] === $route['distance'],
            )) > 1;
        }

        foreach ($observations as $index => $route) {
            $observations[$index]['ecmp'] ??= false;
        }

        return $observations;
    }

    private function routerOsFlag(mixed $value): bool
    {
        return $value === true || in_array(strtolower((string) $value), ['true', 'yes', '1'], true);
    }

    public function getInterfaces(string $routerId): array
    {
        $routerConfig = $this->findRouter($routerId);
        if ($routerConfig && $routerConfig['api_transport'] === 'telemetry') {
            return $this->telemetry->interfaces($routerConfig['model']);
        }

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
        $routerConfig = $this->findRouter($routerId);
        if ($routerConfig && $routerConfig['api_transport'] === 'telemetry') {
            return $this->telemetry->clients($routerConfig['model']);
        }

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

    public function getTraffic(string $routerId): ?array
    {
        $routerConfig = $this->findRouter($routerId);
        if ($routerConfig && $routerConfig['api_transport'] === 'telemetry') {
            return $this->telemetry->traffic($routerConfig['model']);
        }

        if (! $routerConfig) {
            return null;
        }

        $interfaces = $this->transport->print(
            $routerConfig['model'],
            '/interface/print',
            ['.proplist' => 'name,running,disabled,rx-byte,tx-byte'],
        );
        $interfaces = array_values(array_filter(
            $interfaces,
            fn (array $interface): bool => is_string($interface['name'] ?? null)
                && $interface['name'] !== ''
                && $interface['name'] !== 'lo'
                && ($interface['running'] ?? 'false') === 'true'
                && ($interface['disabled'] ?? 'false') !== 'true',
        ));
        if ($interfaces === []) {
            return null;
        }

        $currentRx = 0;
        $currentTx = 0;
        $totalRx = 0;
        $totalTx = 0;
        foreach ($interfaces as $interface) {
            $totalRx += $this->parseBytes($interface['rx-byte'] ?? '0');
            $totalTx += $this->parseBytes($interface['tx-byte'] ?? '0');

            $traffic = $this->monitor($routerId, '/interface/monitor-traffic', [
                'interface' => $interface['name'],
                '.proplist' => 'name,rx-bits-per-second,tx-bits-per-second',
            ]);
            foreach ($traffic as $t) {
                $currentRx += (int) ($t['rx-bits-per-second'] ?? '0');
                $currentTx += (int) ($t['tx-bits-per-second'] ?? '0');
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
        $routerConfig = $this->findRouter($routerId);
        if ($routerConfig && $routerConfig['api_transport'] === 'telemetry') {
            $snapshot = $routerConfig['model']->latest_telemetry;
            if (! is_array($snapshot)) {
                return null;
            }
            $wirelessClients = ($snapshot['wirelessClientsUsingLastGoodSnapshot'] ?? false) === true
                ? ($snapshot['wirelessClientsLastReport'] ?? [])
                : ($snapshot['wirelessClients'] ?? []);

            return [
                'routerId' => $routerId,
                'ssid' => '',
                'frequency' => '',
                'channel' => '',
                'channelWidth' => '',
                'mode' => '',
                'signal' => 0,
                'noiseFloor' => 0,
                'connectedClients' => ($snapshot['wirelessCollectionSucceeded'] ?? null) === true
                    && ($snapshot['wirelessInventoryFresh'] ?? false) === true
                    ? count($wirelessClients)
                    : null,
                'txRate' => '',
                'rxRate' => '',
                'protocol' => '',
                'securityMode' => '',
                'observedAt' => ($snapshot['wirelessCollectionSucceeded'] ?? null) === true
                    ? ($snapshot['observedAt'] ?? null)
                    : ($snapshot['wirelessClientsObservedAt'] ?? null),
                'collectionSucceeded' => $snapshot['wirelessCollectionSucceeded'] ?? null,
                'collectionComplete' => $snapshot['wirelessClientsComplete'] ?? null,
                'expectedCount' => $snapshot['wirelessRegistrationCount'] ?? null,
                'receivedCount' => $snapshot['wirelessReceivedCount'] ?? null,
                'usingLastGoodSnapshot' => $snapshot['wirelessClientsUsingLastGoodSnapshot'] ?? false,
            ];
        }

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
            'rxRate' => $traffic['currentRxMbps'] ?? null,
            'txRate' => $traffic['currentTxMbps'] ?? null,
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
        $stale = count(array_filter($routers, fn ($r) => ($r['dataSource'] ?? null) === 'stale'));
        $unknown = count(array_filter(
            $routers,
            fn ($r) => $r['status'] === 'unknown' && ($r['dataSource'] ?? null) !== 'stale',
        ));

        $totalClients = 0;
        $totalTraffic = 0;
        foreach ($routers as $router) {
            if (in_array($router['status'], ['online', 'degraded'], true)) {
                $totalClients += count($this->getClients($router['id']));
                $traffic = $this->getTraffic($router['id']);
                if ($traffic) {
                    $totalTraffic += $traffic['totalRxBytes'] + $traffic['totalTxBytes'];
                }
            }
        }

        $determinateRouters = $online + $degraded + $offline;
        $uptimePercent = $determinateRouters > 0
            ? round((($online + $degraded) / $determinateRouters) * 100, 1)
            : 0;

        return [
            'totalRouters' => $total,
            'online' => $online,
            'offline' => $offline,
            'degraded' => $degraded,
            'stale' => $stale,
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
            } elseif (($router['dataSource'] ?? null) === 'stale') {
                $receivedAt = $router['telemetryReceivedAt'] ?? null;
                $staleStartedAt = $receivedAt
                    ? Carbon::parse($receivedAt)
                        ->addSeconds(max(15, (int) config('mikrotik.telemetry_stale_seconds', 15)))
                        ->toIso8601String()
                    : now()->toIso8601String();
                $incidents[] = [
                    'id' => 'inc-'.$id++,
                    'routerId' => $router['id'],
                    'routerName' => $router['name'],
                    'type' => 'stale',
                    'severity' => 'warning',
                    'message' => "No recent telemetry report has been received from router {$router['name']}; reachability is unconfirmed.",
                    'startedAt' => $staleStartedAt,
                    'resolvedAt' => null,
                ];
            } elseif ($router['status'] === 'unknown') {
                $incidents[] = [
                    'id' => 'inc-'.$id++,
                    'routerId' => $router['id'],
                    'routerName' => $router['name'],
                    'type' => 'unknown',
                    'severity' => 'info',
                    'message' => "No telemetry report has been received from router {$router['name']} yet; status is unknown.",
                    'startedAt' => $router['createdAt'] ?? now()->toIso8601String(),
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
        if ($router['api_transport'] === 'telemetry') {
            $status = $this->telemetry->status($router['model']);
            $telemetryIsFresh = ($status['telemetryStatus'] ?? null) === 'online';
            $hasReceivedTelemetry = $router['model']->telemetry_received_at !== null;

            return [
                'routerId' => $routerId,
                'state' => $telemetryIsFresh ? 'telemetry' : 'waiting',
                'message' => $telemetryIsFresh
                    ? 'Recent outbound telemetry is available. No inbound router API connection was attempted.'
                    : ($hasReceivedTelemetry
                        ? 'No recent telemetry report was received; reachability is unconfirmed.'
                        : 'No telemetry report has been received yet; status is unknown.'),
                'readOnly' => true,
                'sessionToken' => null,
                'dataSource' => $telemetryIsFresh ? 'telemetry' : ($hasReceivedTelemetry ? 'stale' : 'unknown'),
                'telemetryReceivedAt' => $router['model']->telemetry_received_at?->toIso8601String(),
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
        ]);
        foreach ($this->normalizeItems($interfaces) as $interface) {
            if (($interface['type'] ?? null) === 'ether' && ! empty($interface['mac-address'])) {
                return $interface['mac-address'];
            }
        }

        return '';
    }

    private function routerSummary(array $cfg): array
    {
        if ($cfg['api_transport'] === 'telemetry') {
            $statusData = $this->telemetry->status($cfg['model']);
            $hasReport = $cfg['model']->telemetry_received_at !== null;
            $isStale = ! $hasReport || $statusData === null || $statusData['telemetryStatus'] === 'stale';

            return [
                'id' => $cfg['id'],
                'name' => $cfg['name'],
                'identity' => $statusData['systemIdentity'] ?? $cfg['name'],
                'hostname' => $cfg['ip_address'],
                'ipAddress' => $cfg['ip_address'],
                'macAddress' => $statusData['macAddresses'][0] ?? '',
                'location' => $cfg['location'] ?? '',
                'site' => $cfg['site'] ?? '',
                'model' => $statusData['boardModel'] ?? null,
                'serialNumber' => null,
                'routerosVersion' => $statusData['routerosVersion'] ?? $cfg['model']->last_routeros_version,
                'architecture' => $statusData['architecture'] ?? null,
                'uptimeSeconds' => $statusData['uptimeSeconds'] ?? null,
                'status' => $isStale
                    ? 'unknown'
                    : ((($statusData['cpuUsage'] ?? 0) > 90) ? 'degraded' : 'online'),
                'lastErrorCategory' => $isStale ? 'telemetry_stale' : null,
                'lastSeenAt' => $cfg['model']->telemetry_received_at?->toIso8601String(),
                'telemetryObservedAt' => $cfg['model']->telemetry_observed_at?->toIso8601String(),
                'telemetryReceivedAt' => $cfg['model']->telemetry_received_at?->toIso8601String(),
                'createdAt' => $cfg['created_at'] ?? now()->toIso8601String(),
                'updatedAt' => $cfg['model']->updated_at?->toIso8601String(),
                'dataSource' => $isStale ? ($hasReport ? 'stale' : 'unknown') : 'telemetry',
            ];
        }

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

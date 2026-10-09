<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MikroTikRouterProvider
 *
 * Implements RouterProvider by talking to MikroTik routers via:
 *   1. Binary API (TCP 8728/8729) — primary, full protocol support
 *   2. REST API (HTTPS /rest/)   — fallback if binary API is unavailable
 *
 * Both paths are READ-ONLY: only print/getall/monitor commands are sent.
 * No set, add, remove, reboot, or other mutation commands are ever used.
 *
 * The binary API client (MikroTikApiClient) implements the exact wire
 * protocol described in the RouterOS API documentation:
 *   - Length-prefixed word encoding (1-5 bytes)
 *   - Sentence structure (command word + attributes + zero-length terminator)
 *   - Challenge-response login (MD5)
 *   - Query words for filtering (?name=x, ?#|, etc.)
 *   - Reply parsing (!re, !done, !trap, !fatal)
 *
 * The REST API is a thin HTTP wrapper around the same RouterOS commands.
 *
 * References:
 *   Binary API: https://help.mikrotik.com/docs/display/ROS/API
 *   REST API:   https://help.mikrotik.com/docs/display/ROS/REST+API
 */
class MikroTikRouterProvider implements RouterProvider
{
    private const DEFAULT_TIMEOUT = 10;

    private const API_PORT_PLAIN = 8728;

    private const API_PORT_TLS = 8729;

    private array $routersConfig;

    private int $timeout;

    public function __construct()
    {
        $this->routersConfig = Router::query()
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
                'use_ssl' => $router->use_ssl,
                'verify_cert' => $router->verify_cert,
                'api_protocol' => 'auto',
                'created_at' => $router->created_at?->toIso8601String(),
            ])
            ->all();
        $this->timeout = (int) config('mikrotik.timeout', self::DEFAULT_TIMEOUT);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Transport layer — binary API (primary) with REST fallback
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Execute a print command on the router.
     * Tries the binary API first, falls back to REST API.
     *
     * @param  string  $command  e.g. "/system/resource/print" (binary) or "system/resource" (REST)
     * @param  array  $args  attribute / proplist args
     * @param  array  $queries  binary API query words (ignored for REST)
     * @return array|null array of items, or null on failure
     */
    private function query(string $routerId, string $command, array $args = [], array $queries = []): ?array
    {
        $router = $this->findRouter($routerId);
        if (! $router) {
            return null;
        }

        $protocol = $router['api_protocol'] ?? config('mikrotik.api_protocol', 'auto');

        if ($protocol === 'binary' || $protocol === 'auto') {
            $result = $this->binaryQuery($router, $command, $args, $queries);
            if ($result !== null) {
                return $result;
            }

            if ($protocol === 'binary') {
                return null;
            }
            // Auto: fall through to REST
        }

        if ($protocol === 'rest' || $protocol === 'auto') {
            $restPath = ltrim($command, '/');
            // /system/resource/print → system/resource
            $restPath = preg_replace('/\/print$/', '', $restPath);

            // /interface/wireless/registration-table/print → interface/wireless/registration-table
            return $this->restGet($routerId, $restPath, $args);
        }

        return null;
    }

    /**
     * Execute a monitor command (e.g. /interface/monitor-traffic).
     * Binary API: print with once arg. REST: POST with once in body.
     */
    private function monitor(string $routerId, string $command, array $args = []): ?array
    {
        $router = $this->findRouter($routerId);
        if (! $router) {
            return null;
        }

        $protocol = $router['api_protocol'] ?? config('mikrotik.api_protocol', 'auto');

        if ($protocol === 'binary' || $protocol === 'auto') {
            $result = $this->binaryMonitor($router, $command, $args);
            if ($result !== null) {
                return $result;
            }
            if ($protocol === 'binary') {
                return null;
            }
        }

        if ($protocol === 'rest' || $protocol === 'auto') {
            $restPath = ltrim($command, '/');
            $restPath = preg_replace('/\/print$/', '', $restPath);
            $body = array_merge($args, ['once' => '']);

            return $this->restPost($routerId, $restPath, $body);
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Binary API transport
    // ═══════════════════════════════════════════════════════════════════

    private function binaryQuery(array $router, string $command, array $args, array $queries): ?array
    {
        $useTls = ($router['api_use_tls'] ?? false);
        $port = (int) ($router['api_port'] ?? ($useTls ? self::API_PORT_TLS : self::API_PORT_PLAIN));

        try {
            $client = new MikroTikApiClient(
                $router['ip_address'],
                $port,
                $useTls,
                $this->timeout,
                (bool) ($router['verify_cert'] ?? true),
            );
            $client->connect($router['api_username'], $router['api_password']);

            // Ensure command ends with /print
            $cmd = str_starts_with($command, '/') ? $command : '/'.$command;
            if (! str_ends_with($cmd, '/print') && ! str_ends_with($cmd, '/getall')) {
                $cmd = rtrim($cmd, '/').'/print';
            }

            $items = $client->print($cmd, $args, $queries);
            $client->disconnect();

            return $items;
        } catch (\Exception $e) {
            Log::debug('MikroTik binary API query failed, trying REST fallback', [
                'router' => $router['id'] ?? 'unknown',
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function binaryMonitor(array $router, string $command, array $args): ?array
    {
        $useTls = ($router['api_use_tls'] ?? false);
        $port = (int) ($router['api_port'] ?? ($useTls ? self::API_PORT_TLS : self::API_PORT_PLAIN));

        try {
            $client = new MikroTikApiClient(
                $router['ip_address'],
                $port,
                $useTls,
                $this->timeout,
                (bool) ($router['verify_cert'] ?? true),
            );
            $client->connect($router['api_username'], $router['api_password']);

            $cmd = str_starts_with($command, '/') ? $command : '/'.$command;
            $items = $client->monitor($cmd, $args);
            $client->disconnect();

            return $items;
        } catch (\Exception $e) {
            Log::debug('MikroTik binary API monitor failed, trying REST fallback', [
                'router' => $router['id'] ?? 'unknown',
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  REST API transport (fallback)
    // ═══════════════════════════════════════════════════════════════════

    private function restGet(string $routerId, string $path, array $params = []): ?array
    {
        $router = $this->findRouter($routerId);
        if (! $router) {
            return null;
        }

        $protocol = ($router['use_ssl'] ?? true) ? 'https' : 'http';
        $url = "{$protocol}://{$router['ip_address']}/rest/{$path}";

        try {
            $response = Http::withBasicAuth($router['api_username'], $router['api_password'])
                ->timeout($this->timeout)
                ->withoutVerifying(! ($router['verify_cert'] ?? false))
                ->get($url, $params);

            if ($response->failed()) {
                Log::warning("MikroTik REST GET {$path} failed", [
                    'router' => $routerId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error("MikroTik REST GET {$path} exception", [
                'router' => $routerId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function restPost(string $routerId, string $path, array $body = []): ?array
    {
        $router = $this->findRouter($routerId);
        if (! $router) {
            return null;
        }

        $protocol = ($router['use_ssl'] ?? true) ? 'https' : 'http';
        $url = "{$protocol}://{$router['ip_address']}/rest/{$path}";

        try {
            $response = Http::withBasicAuth($router['api_username'], $router['api_password'])
                ->timeout($this->timeout)
                ->withoutVerifying(! ($router['verify_cert'] ?? false))
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $body);

            if ($response->failed()) {
                Log::warning("MikroTik REST POST {$path} failed", [
                    'router' => $routerId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error("MikroTik REST POST {$path} exception", [
                'router' => $routerId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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

    private function probeStatus(string $routerId): string
    {
        $resource = $this->query($routerId, '/system/resource/print', [
            '.proplist' => 'cpu-load,uptime',
        ]);

        if ($resource === null) {
            return 'offline';
        }
        if (! empty($resource) && isset($resource[0]['cpu-load']) && (int) $resource[0]['cpu-load'] > 90) {
            return 'degraded';
        }

        return 'online';
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
     * Normalize binary API item (key=value attributes) to the same
     * JSON structure that REST returns. Binary API uses kebab-case keys
     * like the REST API, so the shapes are nearly identical.
     */
    private function normalizeItem(array $item): array
    {
        // Binary API returns the same key format as REST
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
        $routers = [];
        foreach ($this->routersConfig as $cfg) {
            $status = $this->probeStatus($cfg['id']);
            $identity = $this->query($cfg['id'], '/system/identity/print');
            $resource = $this->query($cfg['id'], '/system/resource/print');

            $idName = $identity[0]['name'] ?? $cfg['name'] ?? '';
            $res = $resource[0] ?? [];

            $routers[] = [
                'id' => $cfg['id'],
                'name' => $cfg['name'],
                'identity' => $idName,
                'hostname' => $cfg['ip_address'],
                'ipAddress' => $cfg['ip_address'],
                'macAddress' => $this->getFirstMacAddress($cfg['id']),
                'location' => $cfg['location'] ?? '',
                'site' => $cfg['site'] ?? '',
                'model' => $res['board-name'] ?? 'Unknown',
                'serialNumber' => $res['serial-number'] ?? '',
                'routerosVersion' => $res['version'] ?? 'Unknown',
                'architecture' => $res['architecture'] ?? '',
                'status' => $status,
                'lastSeenAt' => $status !== 'offline' ? now()->toIso8601String() : null,
                'createdAt' => $cfg['created_at'] ?? now()->toIso8601String(),
                'updatedAt' => now()->toIso8601String(),
            ];
        }

        return $routers;
    }

    public function getRouter(string $routerId): ?array
    {
        $cfg = $this->findRouter($routerId);
        if (! $cfg) {
            return null;
        }

        $status = $this->probeStatus($routerId);
        $identity = $this->query($routerId, '/system/identity/print');
        $resource = $this->query($routerId, '/system/resource/print');

        $idName = $identity[0]['name'] ?? $cfg['name'];
        $res = $resource[0] ?? [];

        return [
            'id' => $cfg['id'],
            'name' => $cfg['name'],
            'identity' => $idName,
            'hostname' => $cfg['ip_address'],
            'ipAddress' => $cfg['ip_address'],
            'macAddress' => $this->getFirstMacAddress($routerId),
            'location' => $cfg['location'] ?? '',
            'site' => $cfg['site'] ?? '',
            'model' => $res['board-name'] ?? 'Unknown',
            'serialNumber' => $res['serial-number'] ?? '',
            'routerosVersion' => $res['version'] ?? 'Unknown',
            'architecture' => $res['architecture'] ?? '',
            'status' => $status,
            'lastSeenAt' => $status !== 'offline' ? now()->toIso8601String() : null,
            'createdAt' => $cfg['created_at'] ?? now()->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ];
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

        $totalMemory = $this->parseBytes($res['total-memory'] ?? '0');
        $freeMemory = $this->parseBytes($res['free-memory'] ?? '0');
        $totalHdd = $this->parseBytes($res['total-hdd-space'] ?? '0');
        $freeHdd = $this->parseBytes($res['free-hdd-space'] ?? '0');

        $memoryUsage = $totalMemory > 0 ? round((($totalMemory - $freeMemory) / $totalMemory) * 100, 1) : 0;
        $storageUsage = $totalHdd > 0 ? round((($totalHdd - $freeHdd) / $totalHdd) * 100, 1) : 0;

        return [
            'routerId' => $routerId,
            'cpuUsage' => (float) ($res['cpu-load'] ?? 0),
            'memoryUsage' => $memoryUsage,
            'storageUsage' => $storageUsage,
            'temperature' => 0,
            'uptimeSeconds' => $this->parseUptime($res['uptime'] ?? '0s'),
            'boardModel' => $res['board-name'] ?? '',
            'architecture' => $res['architecture'] ?? '',
            'routerosVersion' => $res['version'] ?? '',
            'serialNumber' => $res['serial-number'] ?? '',
            'systemIdentity' => $identity[0]['name'] ?? '',
            'macAddresses' => $macAddresses,
            'ipAddresses' => $ipList,
            'lastCommunication' => now()->toIso8601String(),
            'connectionLatencyMs' => 0,
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

        $clients = [];
        $leaseMap = [];
        foreach ($this->normalizeItems($leases) as $lease) {
            $mac = $lease['mac-address'] ?? '';
            if ($mac) {
                $leaseMap[strtolower($mac)] = $lease;
            }
        }

        $wirelessMacs = [];
        foreach ($this->normalizeItems($registrations) as $reg) {
            $mac = $reg['mac-address'] ?? '';
            $wirelessMacs[strtolower($mac)] = true;
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
                'signal' => (int) ($reg['signal-strength'] ?? 0),
                'rxBytes' => $this->parseBytes($bytesParts[0] ?? '0'),
                'txBytes' => $this->parseBytes($bytesParts[1] ?? '0'),
                'connectedAt' => now()->toIso8601String(),
                'lastSeenAt' => now()->toIso8601String(),
            ];
        }

        // Add DHCP-only clients (wired, not in wireless registration)
        foreach ($this->normalizeItems($leases) as $lease) {
            $mac = $lease['mac-address'] ?? '';
            if ($mac && ! isset($wirelessMacs[strtolower($mac)]) && ($lease['status'] ?? '') === 'bound') {
                $clients[] = [
                    'id' => $lease['.id'] ?? Str::uuid()->toString(),
                    'routerId' => $routerId,
                    'interfaceId' => $lease['interface'] ?? '',
                    'interfaceName' => $lease['interface'] ?? '',
                    'hostname' => $lease['host-name'] ?? '',
                    'ipAddress' => $lease['address'] ?? '',
                    'macAddress' => $mac,
                    'signal' => 0,
                    'rxBytes' => 0,
                    'txBytes' => 0,
                    'connectedAt' => now()->toIso8601String(),
                    'lastSeenAt' => now()->toIso8601String(),
                ];
            }
        }

        return $clients;
    }

    public function getTraffic(string $routerId): ?array
    {
        $traffic = $this->monitor($routerId, '/interface/monitor-traffic', [
            'interface' => 'all',
            '.proplist' => 'name,rx-bits-per-second,tx-bits-per-second',
        ]);

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

        $status = $this->probeStatus($routerId);
        if ($status === 'offline') {
            return [
                'routerId' => $routerId,
                'state' => 'failed',
                'message' => 'Router is unreachable. Check network connectivity and API configuration.',
                'readOnly' => true,
                'sessionToken' => null,
            ];
        }

        return [
            'routerId' => $routerId,
            'state' => 'established',
            'message' => 'Connection established. READ-ONLY SESSION — no mutations permitted.',
            'readOnly' => true,
            'sessionToken' => 'ro-'.Str::random(32),
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
}

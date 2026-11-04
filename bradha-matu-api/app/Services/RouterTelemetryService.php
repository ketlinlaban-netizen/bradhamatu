<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterMetric;
use App\Support\IpAddress;
use App\Support\MacAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RouterTelemetryService
{
    public function __construct(private ClientInventory $inventory) {}

    public function endpoint(): string
    {
        $endpoint = (string) config('mikrotik.telemetry_endpoint');
        $parts = parse_url($endpoint);
        $application = parse_url((string) config('app.url'));
        if (! is_array($parts)
            || ! is_array($application)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($application['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || strtolower($parts['host']) !== strtolower((string) ($application['host'] ?? ''))
            || ($parts['port'] ?? 443) !== ($application['port'] ?? 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || ($parts['path'] ?? null) !== '/api/v1/internal/mikrotik/telemetry'
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RouterApiException(
                'telemetry_endpoint_invalid',
                'Telemetry endpoint must use the configured HTTPS application host and ingestion path.',
                httpStatus: 503,
            );
        }

        return $endpoint;
    }

    public function issueCredential(Router $router): string
    {
        $this->endpoint();
        $token = bin2hex(random_bytes(32));
        $router->forceFill([
            'api_transport' => 'telemetry',
            'api_username' => 'telemetry-only',
            'api_password' => bin2hex(random_bytes(32)),
            'telemetry_token_hash' => hash('sha256', $token),
        ])->save();

        return $token;
    }

    public function receive(Router $router, array $payload): array
    {
        // RouterOS stamps observed_at with the board's own GMT offset (e.g. +03:00). Both
        // telemetry_observed_at and telemetry_received_at are UTC columns, so persisting the
        // offset verbatim leaves the replay high-water mark hours in the future and every
        // following report comes back 409 replayed_telemetry.
        $observedAt = (new \DateTimeImmutable($payload['observed_at']))
            ->setTimezone(new \DateTimeZone((string) config('app.timezone', 'UTC')));
        $now = now();

        return DB::transaction(function () use ($router, $payload, $observedAt, $now): array {
            $lockedRouter = Router::query()->whereKey($router->id)->lockForUpdate()->firstOrFail();
            if ($lockedRouter->telemetry_snapshot_id === $payload['snapshot_id']) {
                return [
                    'accepted' => true,
                    'duplicate' => true,
                    'snapshotId' => $payload['snapshot_id'],
                    'receivedAt' => $lockedRouter->telemetry_received_at?->toIso8601String(),
                ];
            }
            if ($lockedRouter->telemetry_observed_at !== null
                && $observedAt <= $lockedRouter->telemetry_observed_at) {
                throw new RouterApiException(
                    'replayed_telemetry',
                    'Telemetry snapshot is not newer than the last accepted report.',
                    httpStatus: 409,
                );
            }

            $maximumSkew = max(30, (int) config('mikrotik.telemetry_max_clock_skew_seconds', 300));
            if (abs($now->getTimestamp() - $observedAt->getTimestamp()) > $maximumSkew) {
                throw new RouterApiException(
                    'stale_telemetry',
                    'Router telemetry timestamp is outside the accepted clock window.',
                    httpStatus: 422,
                );
            }

            $normalized = $this->normalize($payload);
            $previous = $lockedRouter->latest_telemetry ?? [];
            $normalized['wirelessClientsLastReport'] = $normalized['wirelessClients'];
            $normalized['bridgeHostsLastReport'] = $normalized['bridgeHosts'];
            $normalized['arpNeighborsLastReport'] = $normalized['arpNeighbors'];
            foreach ([
                'interfaces' => 'interfaces',
                'ipAddresses' => 'ipAddresses',
                'routes' => 'routes',
                'dhcpLeases' => 'dhcpLeases',
                'wirelessClients' => 'wirelessClients',
                'bridgeHosts' => 'bridgeHosts',
                'arpNeighbors' => 'arpNeighbors',
            ] as $source => $dataKey) {
                $this->preserveIncompleteSource($normalized, $previous, $source, $dataKey, $observedAt, $now);
            }
            foreach (['interfaces', 'ipAddresses', 'routes', 'dhcpLeases', 'wirelessClients', 'bridgeHosts', 'arpNeighbors'] as $source) {
                $normalized[$source.'Fresh'] = isset($normalized[$source.'ObservedAt'])
                    && $this->isObservationFresh(
                        new \DateTimeImmutable($normalized[$source.'ObservedAt']),
                        $now,
                    );
            }
            $normalized['wirelessInventoryFresh'] = $normalized['wirelessClientsFresh'];
            $rates = $this->counterRates(
                $lockedRouter->latest_telemetry['interfaces'] ?? [],
                $normalized['interfaces'],
                $lockedRouter->telemetry_observed_at,
                $observedAt,
            );
            $normalized['rates'] = $rates;

            $lockedRouter->forceFill([
                'latest_telemetry' => $normalized,
                'telemetry_snapshot_id' => $payload['snapshot_id'],
                'telemetry_observed_at' => $observedAt,
                'telemetry_received_at' => $now,
                'last_attempted_at' => $now,
                'last_seen_at' => $now,
                'last_routeros_version' => $normalized['system']['version'],
                'last_error_category' => null,
                'status_override' => $normalized['system']['cpuLoad'] > 90 ? 'degraded' : 'online',
            ])->save();
            $inventory = $this->inventory->recordTelemetrySnapshot(
                $lockedRouter,
                $this->projectWirelessClients(
                    $lockedRouter,
                    $normalized['wirelessClientsLastReport'],
                    $observedAt,
                ),
                $normalized['wirelessClientsComplete'] && $normalized['wirelessInventoryFresh'],
                $normalized['wirelessCollectionSucceeded'] === true
                    && $normalized['wirelessInventoryFresh'],
                $observedAt,
                $this->projectWiredClients(
                    $normalized['bridgeHostsLastReport'],
                    $normalized['wirelessClientsLastReport'],
                    $observedAt,
                    $normalized['interfaces'],
                ),
                $normalized['bridgeHostsComplete'] && $normalized['bridgeHostsFresh'],
                $normalized['bridgeHostsCollectionSucceeded'] === true
                    && $normalized['bridgeHostsFresh'],
                $this->projectArpNeighbors(
                    $normalized['arpNeighborsLastReport'],
                    $observedAt,
                ),
                $normalized['arpNeighborsCollectionSucceeded'] === true
                    && $normalized['arpNeighborsFresh'],
                $normalized['dhcpLeases'],
                $normalized['dhcpLeasesComplete']
                    && $normalized['dhcpLeasesCollectionSucceeded'] === true
                    && $normalized['dhcpLeasesFresh'],
                $normalized['dhcpLeaseDynamicSupported'],
            );

            RouterMetric::query()->create([
                'id' => (string) Str::uuid(),
                'router_id' => $lockedRouter->id,
                'cpu_usage' => $normalized['system']['cpuLoad'],
                'memory_usage' => $this->percentage(
                    $normalized['system']['totalMemory'],
                    $normalized['system']['freeMemory'],
                ),
                'storage_usage' => $this->percentage(
                    $normalized['system']['totalHddSpace'],
                    $normalized['system']['freeHddSpace'],
                ),
                'temperature' => null,
                'rx_rate' => $rates['rxMbps'],
                'tx_rate' => $rates['txMbps'],
                'recorded_at' => $now,
            ]);
            RouterMetric::query()
                ->where('router_id', $lockedRouter->id)
                ->where('recorded_at', '<', $now->copy()->subDays(30))
                ->delete();

            return [
                'accepted' => true,
                'duplicate' => false,
                'snapshotId' => $payload['snapshot_id'],
                'inventory' => $inventory['counts'],
                'sources' => [
                    'interfaces' => [
                        'succeeded' => $normalized['interfacesCollectionSucceeded'],
                        'complete' => $normalized['interfacesComplete'],
                        'expectedCount' => $normalized['interfaceCount'],
                        'collectedCount' => $normalized['interfacesCollectedCount'],
                        'receivedCount' => $normalized['interfacesReceivedCount'],
                        'observedAt' => $normalized['interfacesObservedAt'],
                    ],
                    'ipAddresses' => [
                        'succeeded' => $normalized['ipAddressesCollectionSucceeded'],
                        'complete' => $normalized['ipAddressesComplete'],
                        'expectedCount' => $normalized['ipAddressCount'],
                        'collectedCount' => $normalized['ipAddressesCollectedCount'],
                        'receivedCount' => $normalized['ipAddressesReceivedCount'],
                        'observedAt' => $normalized['ipAddressesObservedAt'],
                    ],
                    'routes' => [
                        'succeeded' => $normalized['routesCollectionSucceeded'],
                        'complete' => $normalized['routesComplete'],
                        'expectedCount' => $normalized['routeCount'],
                        'collectedCount' => $normalized['routesCollectedCount'],
                        'receivedCount' => $normalized['routesReceivedCount'],
                        'observedAt' => $normalized['routesObservedAt'],
                    ],
                    'dhcpLeases' => [
                        'succeeded' => $normalized['dhcpLeasesCollectionSucceeded'],
                        'complete' => $normalized['dhcpLeasesComplete'],
                        'expectedCount' => $normalized['dhcpLeaseCount'],
                        'collectedCount' => $normalized['dhcpCollectedCount'],
                        'receivedCount' => $normalized['dhcpReceivedCount'],
                        'observedAt' => $normalized['dhcpLeasesObservedAt'],
                    ],
                    'wirelessClients' => [
                        'succeeded' => $normalized['wirelessCollectionSucceeded'],
                        'complete' => $normalized['wirelessClientsComplete'],
                        'expectedCount' => $normalized['wirelessRegistrationCount'],
                        'collectedCount' => $normalized['wirelessCollectedCount'],
                        'receivedCount' => $normalized['wirelessReceivedCount'],
                        'observedAt' => $observedAt->format(DATE_ATOM),
                    ],
                    'bridgeHosts' => [
                        'succeeded' => $normalized['bridgeHostsCollectionSucceeded'],
                        'complete' => $normalized['bridgeHostsComplete'],
                        'expectedCount' => $normalized['bridgeHostCount'],
                        'collectedCount' => $normalized['bridgeHostsCollectedCount'],
                        'receivedCount' => $normalized['bridgeHostsReceivedCount'],
                        'observedAt' => $normalized['bridgeHostsObservedAt'],
                    ],
                    'arpNeighbors' => [
                        'succeeded' => $normalized['arpNeighborsCollectionSucceeded'],
                        'complete' => $normalized['arpNeighborsComplete'],
                        'expectedCount' => $normalized['arpNeighborCount'],
                        'collectedCount' => $normalized['arpNeighborsCollectedCount'],
                        'receivedCount' => $normalized['arpNeighborsReceivedCount'],
                        'observedAt' => $normalized['arpNeighborsObservedAt'],
                    ],
                ],
                'receivedAt' => $now->toIso8601String(),
            ];
        });
    }

    public function status(Router $router): ?array
    {
        $snapshot = $router->latest_telemetry;
        if (! is_array($snapshot)) {
            return [
                'routerId' => $router->id,
                'cpuUsage' => null,
                'memoryUsage' => null,
                'storageUsage' => null,
                'temperature' => null,
                'uptimeSeconds' => null,
                'boardModel' => '',
                'architecture' => '',
                'routerosVersion' => $router->last_routeros_version ?? '',
                'serialNumber' => '',
                'systemIdentity' => $router->identity ?? $router->name,
                'macAddresses' => [],
                'routerMacAddress' => null,
                'ipAddresses' => [],
                'interfaceCollectionSucceeded' => null,
                'interfaceCollectionComplete' => null,
                'interfaceCollectedCount' => null,
                'interfaceReceivedCount' => null,
                'interfaceObservedAt' => null,
                'interfaceFresh' => false,
                'wirelessCollectionSucceeded' => null,
                'wirelessCollectionComplete' => null,
                'wirelessExpectedCount' => null,
                'wirelessCollectedCount' => null,
                'wirelessReceivedCount' => null,
                'wirelessObservedAt' => null,
                'wirelessFresh' => false,
                'wirelessUsingLastGoodSnapshot' => false,
                'gatewayRoutes' => [],
                'gatewayObservedAt' => null,
                'routeCollectionSucceeded' => null,
                'routeCollectionComplete' => null,
                'routeCollectedCount' => null,
                'routeReceivedCount' => null,
                'routeFresh' => false,
                'ipAddressCollectionSucceeded' => null,
                'ipAddressCollectionComplete' => null,
                'ipAddressCollectedCount' => null,
                'ipAddressReceivedCount' => null,
                'ipAddressObservedAt' => null,
                'ipAddressFresh' => false,
                'lastCommunication' => '',
                'connectionLatencyMs' => null,
                'dataSource' => 'stale',
                'staleReason' => 'Waiting for the router to submit its first telemetry report.',
                'telemetryObservedAt' => null,
                'telemetryReceivedAt' => null,
                'telemetryStatus' => 'unknown',
            ];
        }

        $system = $snapshot['system'];
        $stale = $this->isStale($router);

        return [
            'routerId' => $router->id,
            'cpuUsage' => $system['cpuLoad'],
            'memoryUsage' => $this->percentage($system['totalMemory'], $system['freeMemory']),
            'storageUsage' => $this->percentage($system['totalHddSpace'], $system['freeHddSpace']),
            'temperature' => null,
            'uptimeSeconds' => $this->uptimeSeconds($system['uptime']),
            'boardModel' => $system['boardName'],
            'architecture' => $system['architecture'],
            'routerosVersion' => $system['version'],
            'serialNumber' => '',
            'systemIdentity' => $system['identity'],
            'macAddresses' => array_values(array_unique(array_filter(array_map(
                fn (string $mac): ?string => $this->validRouterMac($mac),
                array_column($snapshot['interfaces'], 'macAddress'),
            )))),
            'routerMacAddress' => $snapshot['routerMacAddress'] ?? null,
            'ipAddresses' => array_values(array_filter(array_column($snapshot['ipAddresses'], 'address'))),
            'interfaceCollectionSucceeded' => $snapshot['interfacesCollectionSucceeded'] ?? null,
            'interfaceCollectionComplete' => $snapshot['interfacesComplete'] ?? null,
            'interfaceCollectedCount' => $snapshot['interfacesCollectedCount'] ?? null,
            'interfaceReceivedCount' => $snapshot['interfacesReceivedCount'] ?? null,
            'interfaceObservedAt' => $snapshot['interfacesObservedAt'] ?? null,
            'interfaceFresh' => $this->sourceIsFresh($snapshot['interfacesObservedAt'] ?? null),
            'wirelessCollectionSucceeded' => $snapshot['wirelessCollectionSucceeded'] ?? null,
            'wirelessCollectionComplete' => $snapshot['wirelessClientsComplete'] ?? null,
            'wirelessExpectedCount' => $snapshot['wirelessRegistrationCount'] ?? null,
            'wirelessCollectedCount' => $snapshot['wirelessCollectedCount'] ?? null,
            'wirelessReceivedCount' => $snapshot['wirelessReceivedCount'] ?? null,
            'wirelessObservedAt' => $snapshot['wirelessClientsObservedAt'] ?? null,
            'wirelessFresh' => $this->sourceIsFresh($snapshot['wirelessClientsObservedAt'] ?? null),
            'wirelessUsingLastGoodSnapshot' => $snapshot['wirelessClientsUsingLastGoodSnapshot'] ?? false,
            'gatewayRoutes' => $snapshot['gatewayRoutes'] ?? [],
            'gatewayObservedAt' => $snapshot['routesObservedAt'] ?? null,
            'routeCollectionSucceeded' => $snapshot['routesCollectionSucceeded'] ?? null,
            'routeCollectionComplete' => $snapshot['routesComplete'] ?? null,
            'routeCollectedCount' => $snapshot['routesCollectedCount'] ?? null,
            'routeReceivedCount' => $snapshot['routesReceivedCount'] ?? null,
            'routeFresh' => $this->sourceIsFresh($snapshot['routesObservedAt'] ?? null),
            'ipAddressCollectionSucceeded' => $snapshot['ipAddressesCollectionSucceeded'] ?? null,
            'ipAddressCollectionComplete' => $snapshot['ipAddressesComplete'] ?? null,
            'ipAddressCollectedCount' => $snapshot['ipAddressesCollectedCount'] ?? null,
            'ipAddressReceivedCount' => $snapshot['ipAddressesReceivedCount'] ?? null,
            'ipAddressObservedAt' => $snapshot['ipAddressesObservedAt'] ?? null,
            'ipAddressFresh' => $this->sourceIsFresh($snapshot['ipAddressesObservedAt'] ?? null),
            'lastCommunication' => $router->telemetry_received_at?->toIso8601String() ?? '',
            'connectionLatencyMs' => null,
            'dataSource' => $stale ? 'stale' : 'telemetry',
            'staleReason' => $stale ? 'No recent router-initiated telemetry report was received.' : null,
            'telemetryObservedAt' => $router->telemetry_observed_at?->toIso8601String(),
            'telemetryReceivedAt' => $router->telemetry_received_at?->toIso8601String(),
            'telemetryStatus' => $stale ? 'stale' : 'online',
        ];
    }

    public function interfaces(Router $router): array
    {
        $snapshot = $router->latest_telemetry ?? [];

        return array_map(fn (array $interface): array => [
            'id' => $interface['id'],
            'routerId' => $router->id,
            'name' => $interface['name'],
            'type' => $this->interfaceType($interface['type']),
            'status' => $interface['disabled'] ? 'disabled' : ($interface['running'] ? 'up' : 'down'),
            'macAddress' => $this->validRouterMac($interface['macAddress'] ?? '') ?? '',
            'rxBytes' => $interface['rxBytes'],
            'txBytes' => $interface['txBytes'],
            'ipAddress' => $interface['ipAddress'],
            'observedAt' => $snapshot['interfacesObservedAt'] ?? null,
            'receivedAt' => $snapshot['interfacesReceivedAt'] ?? null,
            'collectionSucceeded' => $snapshot['interfacesCollectionSucceeded'] ?? null,
            'collectionComplete' => $snapshot['interfacesComplete'] ?? null,
            'collectedCount' => $snapshot['interfacesCollectedCount'] ?? null,
            'receivedCount' => $snapshot['interfacesReceivedCount'] ?? null,
            'stale' => ! $this->sourceIsFresh($snapshot['interfacesObservedAt'] ?? null),
        ], $snapshot['interfaces'] ?? []);
    }

    public function traffic(Router $router): ?array
    {
        $snapshot = $router->latest_telemetry;
        if (! is_array($snapshot)) {
            return null;
        }

        $interfaces = $snapshot['interfaces'];

        $history = RouterMetric::query()
            ->where('router_id', $router->id)
            ->where('recorded_at', '>=', now()->subDay())
            ->orderBy('recorded_at')
            ->get(['rx_rate', 'tx_rate', 'recorded_at'])
            ->map(fn (RouterMetric $metric): array => [
                'time' => $metric->recorded_at->format('H:i'),
                'rxMbps' => $metric->rx_rate,
                'txMbps' => $metric->tx_rate,
            ])
            ->all();

        return [
            'routerId' => $router->id,
            'totalRxBytes' => array_sum(array_column($interfaces, 'rxBytes')),
            'totalTxBytes' => array_sum(array_column($interfaces, 'txBytes')),
            'currentRxMbps' => $snapshot['rates']['rxMbps'],
            'currentTxMbps' => $snapshot['rates']['txMbps'],
            'history' => $history,
            'observedAt' => $router->telemetry_observed_at?->toIso8601String(),
            'receivedAt' => $router->telemetry_received_at?->toIso8601String(),
            'stale' => $this->isStale($router),
            'dataSource' => $this->isStale($router) ? 'stale' : 'telemetry',
        ];
    }

    public function leases(Router $router): array
    {
        $snapshot = $router->latest_telemetry;

        return [
            'routerId' => $router->id,
            'observedAt' => $snapshot['dhcpLeasesObservedAt'] ?? null,
            'receivedAt' => $snapshot['dhcpLeasesReceivedAt'] ?? null,
            'leases' => $snapshot['dhcpLeases'] ?? [],
            'collectionComplete' => $snapshot['dhcpLeasesComplete'] ?? null,
            'collectionSucceeded' => $snapshot['dhcpLeasesCollectionSucceeded'] ?? null,
            'expectedCount' => $snapshot['dhcpLeaseCount'] ?? null,
            'collectedCount' => $snapshot['dhcpCollectedCount'] ?? null,
            'receivedCount' => $snapshot['dhcpReceivedCount'] ?? count($snapshot['dhcpLeases'] ?? []),
            'usingLastGoodSnapshot' => $snapshot['dhcpLeasesUsingLastGoodSnapshot'] ?? false,
            'stale' => ! $this->sourceIsFresh($snapshot['dhcpLeasesObservedAt'] ?? null),
            'reportObservedAt' => $router->telemetry_observed_at?->toIso8601String(),
            'reportReceivedAt' => $router->telemetry_received_at?->toIso8601String(),
            'dataSource' => $this->isStale($router) ? 'stale' : 'telemetry',
        ];
    }

    public function clients(Router $router): array
    {
        $snapshot = $router->latest_telemetry ?? [];
        $wirelessClients = ($snapshot['wirelessClientsUsingLastGoodSnapshot'] ?? false) === true
            ? ($snapshot['wirelessClientsLastReport'] ?? [])
            : ($snapshot['wirelessClients'] ?? []);

        return $this->projectWirelessClients(
            $router,
            $wirelessClients,
            $router->telemetry_observed_at,
        );
    }

    private function projectWirelessClients(
        Router $router,
        array $wirelessClients,
        ?\DateTimeInterface $observedAt,
    ): array {
        return array_map(fn (array $client): array => [
            'id' => $client['id'],
            'routerId' => $router->id,
            'interfaceId' => $client['interface'],
            'interfaceName' => $client['interface'],
            'hostname' => $client['hostName'] !== '' ? $client['hostName'] : null,
            'ipAddress' => $client['address'] !== '' ? $client['address'] : null,
            'macAddress' => MacAddress::normalize((string) $client['macAddress']) ?? $client['macAddress'],
            'connectionType' => 'wireless',
            'signal' => $client['signalStrength'],
            'rxBytes' => $client['rxBytes'],
            'txBytes' => $client['txBytes'],
            'connectedAt' => $observedAt?->format(DATE_ATOM),
            'lastSeenAt' => $observedAt?->format(DATE_ATOM),
        ], $wirelessClients);
    }

    private function projectWiredClients(
        array $bridgeHosts,
        array $wirelessClients,
        ?\DateTimeInterface $observedAt,
        array $interfaces = [],
    ): array {
        $wirelessMacs = array_fill_keys(array_map(
            fn (array $client): string => $client['macAddress'],
            $wirelessClients,
        ), true);
        $radioPorts = [];
        foreach ($interfaces as $interface) {
            $name = strtolower(trim((string) ($interface['name'] ?? '')));
            $type = strtolower(trim((string) ($interface['type'] ?? '')));
            if ($name !== ''
                && (in_array($type, ['wlan', 'wifi', 'wireless'], true) || preg_match('/^(wlan|wifi|master-|wds)/', $name) === 1)) {
                $radioPorts[$name] = true;
            }
        }
        $wiredByMac = [];
        foreach ($bridgeHosts as $host) {
            $mac = MacAddress::normalize((string) ($host['macAddress'] ?? ''));
            if ($mac === null
                || MacAddress::isBridgePortArtifact($mac)
                || ($host['local'] ?? true)
                || ! ($host['dynamic'] ?? false)
                || isset($wirelessMacs[$mac])
                || isset($radioPorts[strtolower(trim((string) ($host['onInterface'] ?? '')))])) {
                continue;
            }

            $wiredByMac[$mac] = [
                'macAddress' => $mac,
                'hostname' => $host['hostName'] ?? '',
                'ipAddress' => $host['address'] ?? '',
                'interfaceId' => $host['onInterface'],
                'interfaceName' => $host['onInterface'],
                'connectionType' => 'wired',
                'signal' => null,
                'rxBytes' => 0,
                'txBytes' => 0,
                'connectedAt' => $observedAt?->format(DATE_ATOM),
                'lastSeenAt' => $observedAt?->format(DATE_ATOM),
            ];
        }

        return array_values($wiredByMac);
    }

    private function projectArpNeighbors(
        array $arpNeighbors,
        ?\DateTimeInterface $observedAt,
    ): array {
        $neighborsByMac = [];
        foreach ($arpNeighbors as $neighbor) {
            $mac = MacAddress::normalize((string) ($neighbor['macAddress'] ?? ''));
            if ($mac === null
                || ! ($neighbor['dynamic'] ?? false)
                || IpAddress::normalize($neighbor['address'] ?? null) === null
                || isset($neighborsByMac[$mac])) {
                continue;
            }

            $neighborsByMac[$mac] = [
                'macAddress' => $mac,
                'hostname' => $neighbor['hostName'] ?? '',
                'ipAddress' => $neighbor['address'] ?? '',
                'interfaceId' => $neighbor['interface'],
                'interfaceName' => $neighbor['interface'],
                'connectionType' => 'arp',
                'signal' => null,
                'rxBytes' => 0,
                'txBytes' => 0,
                'connectedAt' => $observedAt?->format(DATE_ATOM),
                'lastSeenAt' => $observedAt?->format(DATE_ATOM),
            ];
        }

        return array_values($neighborsByMac);
    }

    private function normalize(array $payload): array
    {
        $system = $payload['system'];
        $interfaces = array_map(fn (array $interface): array => [
            'id' => (string) ($interface['id'] ?? $interface['name']),
            'name' => $interface['name'],
            'type' => $interface['type'],
            'running' => $this->flag($interface['running']),
            'disabled' => $this->flag($interface['disabled']),
            'macAddress' => $interface['mac_address'] ?? '',
            'rxBytes' => (int) $interface['rx_bytes'],
            'txBytes' => (int) $interface['tx_bytes'],
            'ipAddress' => $interface['ip_address'] ?? null,
        ], $payload['interfaces']);

        $ipAddresses = array_map(fn (array $address): array => [
            'address' => $address['address'],
            'network' => $address['network'] ?? null,
            'interface' => $address['interface'],
            'disabled' => $this->flag($address['disabled']),
            'dynamic' => $this->flag($address['dynamic']),
        ], $payload['ip_addresses'] ?? []);
        if (! array_key_exists('ip_addresses', $payload)) {
            $ipAddresses = array_values(array_filter(array_map(
                fn (array $interface): ?array => ! is_string($interface['ipAddress'])
                    || trim($interface['ipAddress']) === ''
                    ? null
                    : [
                        'address' => $interface['ipAddress'],
                        'network' => null,
                        'interface' => $interface['name'],
                        'disabled' => false,
                        'dynamic' => false,
                    ],
                $interfaces,
            )));
        }

        $routes = array_map(function (array $route): array {
            $immediateGateway = $route['immediate_gateway'] ?? null;
            $gateway = $route['gateway'] ?? null;
            $gatewayInterface = null;
            foreach ([$immediateGateway, $gateway] as $gatewaySource) {
                if (is_string($gatewaySource) && str_contains($gatewaySource, '%')) {
                    [, $gatewayInterface] = explode('%', $gatewaySource, 2);
                    break;
                }
            }

            return [
                'routeId' => $route['id'] ?? null,
                'destination' => $route['dst_address'],
                'gateway' => $gateway,
                'immediateGateway' => $immediateGateway,
                'interface' => $gatewayInterface,
                'distance' => isset($route['distance']) ? (int) $route['distance'] : null,
                'routingTable' => $route['routing_table'],
                'active' => $this->flag($route['active']),
                'dynamic' => $this->flag($route['dynamic']),
                'static' => $this->flag($route['static']),
                'disabled' => $this->flag($route['disabled']),
                'ecmp' => false,
            ];
        }, $payload['routes'] ?? []);
        $gatewayRoutes = array_values(array_filter(
            $routes,
            fn (array $route): bool => $route['destination'] === '0.0.0.0/0',
        ));

        $leaseMap = [];
        foreach ($payload['dhcp_leases'] as $lease) {
            $mac = MacAddress::normalize($lease['mac_address']);
            if ($mac !== null
                && ($lease['status'] ?? null) === 'bound'
                && IpAddress::normalize($lease['address'] ?? null) !== null) {
                $leaseMap[$mac] = $lease;
            }
        }
        $dhcpReceivedCount = count($payload['dhcp_leases']);
        $dhcpLeaseCount = isset($payload['dhcp_lease_count']) ? (int) $payload['dhcp_lease_count'] : null;
        $dhcpCollectedCount = isset($payload['dhcp_collected_count'])
            ? (int) $payload['dhcp_collected_count']
            : $dhcpReceivedCount;
        $dhcpLeasesComplete = array_key_exists('dhcp_leases_complete', $payload)
            ? $this->flag($payload['dhcp_leases_complete'])
            : null;
        $dhcpLeasesCollectionSucceeded = array_key_exists('dhcp_leases_collection_succeeded', $payload)
            ? $this->flag($payload['dhcp_leases_collection_succeeded'])
            : ($dhcpLeasesComplete === true ? true : null);
        if ($dhcpLeaseCount !== null || array_key_exists('dhcp_collected_count', $payload)) {
            $dhcpLeasesComplete = $dhcpLeasesComplete === true
                && $dhcpLeasesCollectionSucceeded === true
                && $dhcpLeaseCount === $dhcpCollectedCount
                && $dhcpCollectedCount === $dhcpReceivedCount;
        }
        $wirelessClients = array_map(function (array $client) use ($leaseMap): array {
            $mac = $this->normalizeMac($client['mac_address']);
            $lease = $leaseMap[$mac] ?? [];
            $boundLease = ($lease['status'] ?? null) === 'bound' ? $lease : [];
            $bytes = explode(',', $client['bytes'] ?? '0,0');
            $signalStrength = isset($client['signal_strength']) ? (int) $client['signal_strength'] : null;

            return [
                'id' => (string) ($client['id'] ?? $mac),
                'interface' => $client['interface'],
                'macAddress' => $mac,
                'signalStrength' => $signalStrength === 0 ? null : $signalStrength,
                'rxBytes' => (int) ($bytes[0] ?? 0),
                'txBytes' => (int) ($bytes[1] ?? 0),
                'hostName' => $lease['host_name'] ?? '',
                'address' => $boundLease['address'] ?? '',
            ];
        }, $payload['wireless_clients']);

        $dhcpLeases = array_map(fn (array $lease): array => [
            'macAddress' => $this->normalizeMac($lease['mac_address']),
            'hostName' => $lease['host_name'] ?? '',
            'address' => $lease['address'],
            'interface' => $lease['interface'],
            'status' => $lease['status'],
            'dynamic' => array_key_exists('dynamic', $lease) ? $this->flag($lease['dynamic']) : null,
            'expiresAfter' => $lease['expires_after'] ?? '',
        ], $payload['dhcp_leases']);
        $dhcpLeaseDynamicSupported = ($payload['dhcp_lease_dynamic_supported'] ?? false) === true
            && collect($payload['dhcp_leases'])->every(
                fn (array $lease): bool => array_key_exists('dynamic', $lease)
                    && in_array($lease['dynamic'], [true, false, 0, 1, '0', '1'], true),
            );
        $bridgeHosts = array_map(function (array $host) use ($leaseMap): array {
            $mac = $this->normalizeMac($host['mac_address']);
            $lease = $leaseMap[$mac] ?? [];
            $boundLease = ($lease['status'] ?? null) === 'bound' ? $lease : [];

            return [
                'id' => (string) ($host['id'] ?? $mac),
                'macAddress' => $mac,
                'bridge' => $host['bridge'],
                'onInterface' => $host['on_interface'],
                'local' => $this->flag($host['local']),
                'dynamic' => $this->flag($host['dynamic']),
                'hostName' => $boundLease['host_name'] ?? '',
                'address' => $boundLease['address'] ?? '',
            ];
        }, $payload['bridge_hosts'] ?? []);
        $bridgeHostReceivedCount = count($bridgeHosts);
        $bridgeHostCount = isset($payload['bridge_host_count'])
            ? (int) $payload['bridge_host_count']
            : null;
        $bridgeHostsCollectedCount = isset($payload['bridge_hosts_collected_count'])
            ? (int) $payload['bridge_hosts_collected_count']
            : $bridgeHostReceivedCount;
        $bridgeHostsCollectionSucceeded = array_key_exists('bridge_hosts_collection_succeeded', $payload)
            ? $this->flag($payload['bridge_hosts_collection_succeeded'])
            : null;
        $bridgeHostsComplete = array_key_exists('bridge_hosts_complete', $payload)
            && $this->flag($payload['bridge_hosts_complete'])
            && $bridgeHostsCollectionSucceeded === true
            && $bridgeHostCount !== null
            && $bridgeHostCount === $bridgeHostsCollectedCount
            && $bridgeHostsCollectedCount === $bridgeHostReceivedCount;
        $arpNeighbors = array_map(function (array $neighbor) use ($leaseMap): array {
            $mac = $this->normalizeMac($neighbor['mac_address']);
            $lease = $leaseMap[$mac] ?? [];
            $boundLease = ($lease['status'] ?? null) === 'bound' ? $lease : [];

            return [
                'address' => $neighbor['address'],
                'macAddress' => $mac,
                'interface' => $neighbor['interface'],
                'dynamic' => $this->flag($neighbor['dynamic']),
                'hostName' => $boundLease['host_name'] ?? '',
            ];
        }, $payload['arp_neighbors'] ?? []);
        $arpNeighborsReceivedCount = count($arpNeighbors);
        $arpNeighborCount = isset($payload['arp_neighbor_count'])
            ? (int) $payload['arp_neighbor_count']
            : null;
        $arpNeighborsCollectedCount = isset($payload['arp_neighbors_collected_count'])
            ? (int) $payload['arp_neighbors_collected_count']
            : $arpNeighborsReceivedCount;
        $arpNeighborsCollectionSucceeded = array_key_exists('arp_neighbors_collection_succeeded', $payload)
            ? $this->flag($payload['arp_neighbors_collection_succeeded'])
            : null;
        $arpNeighborsComplete = array_key_exists('arp_neighbors_complete', $payload)
            && $this->flag($payload['arp_neighbors_complete'])
            && $arpNeighborsCollectionSucceeded === true
            && $arpNeighborCount !== null
            && $arpNeighborCount === $arpNeighborsCollectedCount
            && $arpNeighborsCollectedCount === $arpNeighborsReceivedCount;
        $wirelessRegistrationCount = isset($payload['wireless_registration_count'])
            ? (int) $payload['wireless_registration_count']
            : null;
        $wirelessCollectedCount = isset($payload['wireless_collected_count'])
            ? (int) $payload['wireless_collected_count']
            : null;
        $wirelessReceivedCount = count($payload['wireless_clients']);
        $wirelessClientsComplete = array_key_exists('wireless_clients_complete', $payload)
            && $this->flag($payload['wireless_clients_complete']);
        $wirelessCollectionSucceeded = array_key_exists('wireless_clients_collection_succeeded', $payload)
            ? $this->flag($payload['wireless_clients_collection_succeeded'])
            : ($wirelessClientsComplete ? true : null);
        $wirelessClientsComplete = $wirelessClientsComplete && $wirelessCollectionSucceeded === true;
        if ($wirelessRegistrationCount !== null || $wirelessCollectedCount !== null) {
            $wirelessClientsComplete = $wirelessClientsComplete
                && $wirelessRegistrationCount === $wirelessCollectedCount
                && $wirelessCollectedCount === $wirelessReceivedCount;
        }
        $dhcpLeasesComplete = $dhcpLeasesComplete === true
            && $dhcpLeaseCount === $dhcpCollectedCount
            && $dhcpCollectedCount === $dhcpReceivedCount;

        $interfacesReceivedCount = count($interfaces);
        $interfacesCollectionSucceeded = $this->sourceFlag(
            $payload,
            'interfaces_collection_succeeded',
            'interfaces_complete',
        );
        $interfacesComplete = $this->sourceComplete(
            $payload['interfaces_complete'] ?? null,
            $interfacesCollectionSucceeded,
            $payload['interface_count'] ?? null,
            $payload['interfaces_collected_count'] ?? $interfacesReceivedCount,
            $interfacesReceivedCount,
        );

        $ipAddressesReceivedCount = count($ipAddresses);
        $ipAddressesCollectionSucceeded = $this->sourceFlag(
            $payload,
            'ip_addresses_collection_succeeded',
            'ip_addresses_complete',
        );
        $ipAddressesComplete = $this->sourceComplete(
            $payload['ip_addresses_complete'] ?? null,
            $ipAddressesCollectionSucceeded,
            $payload['ip_address_count'] ?? null,
            $payload['ip_addresses_collected_count'] ?? $ipAddressesReceivedCount,
            $ipAddressesReceivedCount,
        );

        $routesReceivedCount = count($routes);
        $routesCollectionSucceeded = $this->sourceFlag(
            $payload,
            'routes_collection_succeeded',
            'routes_complete',
        );
        $routesComplete = $this->sourceComplete(
            $payload['routes_complete'] ?? null,
            $routesCollectionSucceeded,
            $payload['route_count'] ?? null,
            $payload['routes_collected_count'] ?? $routesReceivedCount,
            $routesReceivedCount,
        );
        $bridgeInterfaces = array_values(array_filter(
            $interfaces,
            fn (array $interface): bool => $interface['type'] === 'bridge',
        ));
        $routerMacAddress = count($bridgeInterfaces) === 1
            ? $this->validRouterMac((string) ($bridgeInterfaces[0]['macAddress'] ?? ''))
            : null;

        return [
            'schemaVersion' => 1,
            'snapshotId' => $payload['snapshot_id'],
            'observedAt' => $payload['observed_at'],
            'system' => [
                'identity' => $system['identity'],
                'version' => $system['version'],
                'boardName' => $system['board_name'],
                'architecture' => $system['architecture'],
                'cpuLoad' => (float) $system['cpu_load'],
                'uptime' => $system['uptime'],
                'totalMemory' => (int) $system['total_memory'],
                'freeMemory' => (int) $system['free_memory'],
                'totalHddSpace' => (int) $system['total_hdd_space'],
                'freeHddSpace' => (int) $system['free_hdd_space'],
            ],
            'interfaces' => $interfaces,
            'interfacesCollectionSucceeded' => $interfacesCollectionSucceeded,
            'interfacesComplete' => $interfacesComplete,
            'interfaceCount' => isset($payload['interface_count']) ? (int) $payload['interface_count'] : null,
            'interfacesCollectedCount' => isset($payload['interfaces_collected_count'])
                ? (int) $payload['interfaces_collected_count']
                : $interfacesReceivedCount,
            'interfacesReceivedCount' => $interfacesReceivedCount,
            'interfacesObservedAt' => null,
            'interfacesReceivedAt' => null,
            'interfacesFresh' => false,
            'routerMacAddress' => $routerMacAddress,
            'ipAddresses' => $ipAddresses,
            'ipAddressesCollectionSucceeded' => $ipAddressesCollectionSucceeded,
            'ipAddressesComplete' => $ipAddressesComplete,
            'ipAddressCount' => isset($payload['ip_address_count']) ? (int) $payload['ip_address_count'] : null,
            'ipAddressesCollectedCount' => isset($payload['ip_addresses_collected_count'])
                ? (int) $payload['ip_addresses_collected_count']
                : $ipAddressesReceivedCount,
            'ipAddressesReceivedCount' => $ipAddressesReceivedCount,
            'ipAddressesObservedAt' => null,
            'ipAddressesReceivedAt' => null,
            'ipAddressesFresh' => false,
            'dhcpLeases' => $dhcpLeases,
            'dhcpLeaseDynamicSupported' => $dhcpLeaseDynamicSupported,
            'dhcpLeasesCollectionSucceeded' => $dhcpLeasesCollectionSucceeded,
            'dhcpLeasesComplete' => $dhcpLeasesComplete,
            'dhcpLeaseCount' => $dhcpLeaseCount,
            'dhcpCollectedCount' => $dhcpCollectedCount,
            'dhcpReceivedCount' => $dhcpReceivedCount,
            'dhcpLeasesObservedAt' => null,
            'dhcpLeasesReceivedAt' => null,
            'dhcpLeasesFresh' => false,
            'routes' => $routes,
            'gatewayRoutes' => $gatewayRoutes,
            'routesCollectionSucceeded' => $routesCollectionSucceeded,
            'routesComplete' => $routesComplete,
            'routeCount' => isset($payload['route_count']) ? (int) $payload['route_count'] : null,
            'routesCollectedCount' => isset($payload['routes_collected_count'])
                ? (int) $payload['routes_collected_count']
                : $routesReceivedCount,
            'routesReceivedCount' => $routesReceivedCount,
            'routesObservedAt' => null,
            'routesReceivedAt' => null,
            'routesFresh' => false,
            'wirelessClients' => $wirelessClients,
            'wirelessClientsLastReport' => $wirelessClients,
            'wirelessCollectionSucceeded' => $wirelessCollectionSucceeded,
            'wirelessClientsComplete' => $wirelessClientsComplete,
            'wirelessRegistrationCount' => $wirelessRegistrationCount,
            'wirelessCollectedCount' => $wirelessCollectedCount ?? $wirelessReceivedCount,
            'wirelessReceivedCount' => $wirelessReceivedCount,
            'wirelessClientsObservedAt' => null,
            'wirelessClientsReceivedAt' => null,
            'wirelessClientsFresh' => false,
            'wirelessClientsUsingLastGoodSnapshot' => false,
            'wirelessInventoryFresh' => false,
            'bridgeHosts' => $bridgeHosts,
            'bridgeHostsLastReport' => $bridgeHosts,
            'bridgeHostsCollectionSucceeded' => $bridgeHostsCollectionSucceeded,
            'bridgeHostsComplete' => $bridgeHostsComplete,
            'bridgeHostCount' => $bridgeHostCount,
            'bridgeHostsCollectedCount' => $bridgeHostsCollectedCount,
            'bridgeHostsReceivedCount' => $bridgeHostReceivedCount,
            'bridgeHostsObservedAt' => null,
            'bridgeHostsReceivedAt' => null,
            'bridgeHostsFresh' => false,
            'bridgeHostsUsingLastGoodSnapshot' => false,
            'arpNeighbors' => $arpNeighbors,
            'arpNeighborsLastReport' => $arpNeighbors,
            'arpNeighborsCollectionSucceeded' => $arpNeighborsCollectionSucceeded,
            'arpNeighborsComplete' => $arpNeighborsComplete,
            'arpNeighborCount' => $arpNeighborCount,
            'arpNeighborsCollectedCount' => $arpNeighborsCollectedCount,
            'arpNeighborsReceivedCount' => $arpNeighborsReceivedCount,
            'arpNeighborsObservedAt' => null,
            'arpNeighborsReceivedAt' => null,
            'arpNeighborsFresh' => false,
            'arpNeighborsUsingLastGoodSnapshot' => false,
            'rates' => ['rxMbps' => null, 'txMbps' => null],
        ];
    }

    private function sourceFlag(array $payload, string $successField, string $completeField): ?bool
    {
        if (array_key_exists($successField, $payload)) {
            return $this->flag($payload[$successField]);
        }

        return array_key_exists($completeField, $payload)
            ? $this->flag($payload[$completeField])
            : null;
    }

    private function sourceComplete(
        mixed $complete,
        ?bool $succeeded,
        mixed $expected,
        mixed $collected,
        int $received,
    ): ?bool {
        if ($complete === null || $succeeded === null) {
            return null;
        }
        if (! $this->flag($complete) || ! $succeeded) {
            return false;
        }

        return $expected !== null
            && $collected !== null
            && (int) $expected === (int) $collected
            && (int) $collected === $received;
    }

    private function preserveIncompleteSource(
        array &$current,
        array $previous,
        string $source,
        string $dataKey,
        \DateTimeInterface $observedAt,
        \DateTimeInterface $receivedAt,
    ): void {
        $completeKey = match ($source) {
            'interfaces' => 'interfacesComplete',
            'ipAddresses' => 'ipAddressesComplete',
            'routes' => 'routesComplete',
            'dhcpLeases' => 'dhcpLeasesComplete',
            'wirelessClients' => 'wirelessClientsComplete',
            'bridgeHosts' => 'bridgeHostsComplete',
            'arpNeighbors' => 'arpNeighborsComplete',
        };
        $succeededKey = match ($source) {
            'interfaces' => 'interfacesCollectionSucceeded',
            'ipAddresses' => 'ipAddressesCollectionSucceeded',
            'routes' => 'routesCollectionSucceeded',
            'dhcpLeases' => 'dhcpLeasesCollectionSucceeded',
            'wirelessClients' => 'wirelessCollectionSucceeded',
            'bridgeHosts' => 'bridgeHostsCollectionSucceeded',
            'arpNeighbors' => 'arpNeighborsCollectionSucceeded',
        };
        $observedKey = $source.'ObservedAt';
        $receivedKey = $source.'ReceivedAt';
        $usingLastGoodKey = $source.'UsingLastGoodSnapshot';
        $currentIsComplete = ($current[$completeKey] ?? null) === true
            && ($current[$succeededKey] ?? null) === true;
        $current[$usingLastGoodKey] = false;

        if ($currentIsComplete) {
            $current[$observedKey] = $observedAt->format(DATE_ATOM);
            $current[$receivedKey] = $receivedAt->format(DATE_ATOM);

            return;
        }

        if (($previous[$completeKey] ?? null) === true
            && is_array($previous[$dataKey] ?? null)
            && is_string($previous[$observedKey] ?? null)) {
            $current[$dataKey] = $previous[$dataKey];
            $current[$observedKey] = $previous[$observedKey];
            $current[$receivedKey] = $previous[$receivedKey] ?? null;
            $current[$usingLastGoodKey] = true;
            if ($source === 'routes') {
                $current['gatewayRoutes'] = $previous['gatewayRoutes'] ?? [];
            }
            if ($source === 'interfaces') {
                $current['routerMacAddress'] = $previous['routerMacAddress'] ?? null;
            }

            return;
        }

        $current[$observedKey] = ($current[$succeededKey] ?? null) === true
            ? $observedAt->format(DATE_ATOM)
            : null;
        $current[$receivedKey] = $current[$observedKey] === null
            ? null
            : $receivedAt->format(DATE_ATOM);
    }

    private function isObservationFresh(\DateTimeInterface $observedAt, \DateTimeInterface $now): bool
    {
        return abs($now->getTimestamp() - $observedAt->getTimestamp()) <= max(
            15,
            (int) config('mikrotik.telemetry_stale_seconds', 15),
        );
    }

    private function sourceIsFresh(mixed $observedAt): bool
    {
        return is_string($observedAt)
            && $observedAt !== ''
            && $this->isObservationFresh(new \DateTimeImmutable($observedAt), now());
    }

    private function validRouterMac(string $macAddress): ?string
    {
        $macAddress = MacAddress::normalize($macAddress);

        return $macAddress === null || $macAddress === '00:00:00:00:00:00'
            ? null
            : $macAddress;
    }

    private function counterRates(
        array $previous,
        array $current,
        ?\DateTimeInterface $previousAt,
        \DateTimeInterface $currentAt,
    ): array {
        if ($previousAt === null) {
            return ['rxMbps' => null, 'txMbps' => null];
        }
        $seconds = $currentAt->getTimestamp() - $previousAt->getTimestamp();
        if ($seconds <= 0 || $seconds > 3600) {
            return ['rxMbps' => null, 'txMbps' => null];
        }

        $previousById = [];
        foreach ($previous as $interface) {
            $previousById[$interface['id']] = $interface;
        }
        $rx = 0;
        $tx = 0;
        foreach ($current as $interface) {
            $before = $previousById[$interface['id']] ?? null;
            if ($before === null) {
                continue;
            }
            $rx += max(0, $interface['rxBytes'] - $before['rxBytes']);
            $tx += max(0, $interface['txBytes'] - $before['txBytes']);
        }

        return [
            'rxMbps' => round(($rx * 8 / $seconds) / 1_000_000, 3),
            'txMbps' => round(($tx * 8 / $seconds) / 1_000_000, 3),
        ];
    }

    private function percentage(int $total, int $free): ?float
    {
        return $total > 0 ? round((($total - $free) / $total) * 100, 1) : null;
    }

    private function flag(bool|string|int $value): bool
    {
        return $value === true || in_array(strtolower((string) $value), ['true', 'yes', '1'], true);
    }

    private function normalizeMac(string $macAddress): string
    {
        return MacAddress::normalize($macAddress)
            ?? throw new RouterApiException(
                'invalid_telemetry_mac',
                'Telemetry contains an invalid MAC address.',
                httpStatus: 422,
            );
    }

    private function interfaceType(string $type): string
    {
        return match ($type) {
            'ether' => 'ethernet',
            'wlan', 'wifi', 'wireless' => 'wireless',
            'bridge' => 'bridge',
            'vlan' => 'vlan',
            'ppp', 'pppoe', 'pppoe-client' => 'ppp',
            default => 'ethernet',
        };
    }

    private function uptimeSeconds(string $uptime): ?int
    {
        preg_match_all('/(\d+)([wdhms])/', $uptime, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return null;
        }

        $factors = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];

        return array_sum(array_map(fn (array $match): int => (int) $match[1] * $factors[$match[2]], $matches));
    }

    private function isStale(Router $router): bool
    {
        return $router->telemetry_received_at === null
            || $router->telemetry_received_at->lt(now()->subSeconds(max(
                15,
                (int) config('mikrotik.telemetry_stale_seconds', 15),
            )));
    }
}

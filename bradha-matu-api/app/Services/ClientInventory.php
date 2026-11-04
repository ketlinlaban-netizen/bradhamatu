<?php

namespace App\Services;

use App\Models\NetworkClient;
use App\Models\Router;
use App\Support\IpAddress;
use App\Support\MacAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientInventory
{
    /**
     * Save a successful router snapshot and mark previously known, missing
     * clients offline. A failed router query must never call this method.
     */
    public function recordSnapshot(
        Router $router,
        array $snapshot,
        bool $complete = true,
        ?\DateTimeInterface $observedAt = null,
    ): Collection {
        return DB::transaction(function () use ($router, $snapshot, $complete, $observedAt): Collection {
            Router::query()->whereKey($router->id)->lockForUpdate()->firstOrFail();

            return $this->persistSnapshot($router, $snapshot, $complete, $observedAt, 'wireless');
        });
    }

    public function recordTelemetrySnapshot(
        Router $router,
        array $snapshot,
        bool $complete,
        bool $sourceConfirmed,
        ?\DateTimeInterface $observedAt = null,
        array $wiredSnapshot = [],
        bool $wiredComplete = false,
        bool $wiredSourceConfirmed = false,
        array $arpSnapshot = [],
        bool $arpSourceConfirmed = false,
        array $dhcpLeases = [],
        bool $dhcpLeaseSnapshotComplete = false,
        bool $dhcpLeaseDynamicSupported = false,
    ): array {
        return DB::transaction(function () use (
            $router,
            $snapshot,
            $complete,
            $sourceConfirmed,
            $observedAt,
            $wiredSnapshot,
            $wiredComplete,
            $wiredSourceConfirmed,
            $arpSnapshot,
            $arpSourceConfirmed,
            $dhcpLeases,
            $dhcpLeaseSnapshotComplete,
            $dhcpLeaseDynamicSupported,
        ): array {
            Router::query()->whereKey($router->id)->lockForUpdate()->firstOrFail();

            return $this->reconcileTelemetrySnapshot(
                $router,
                $snapshot,
                $complete,
                $sourceConfirmed,
                $observedAt,
                $wiredSnapshot,
                $wiredComplete,
                $wiredSourceConfirmed,
                $arpSnapshot,
                $arpSourceConfirmed,
                $dhcpLeases,
                $dhcpLeaseSnapshotComplete,
                $dhcpLeaseDynamicSupported,
            );
        });
    }

    private function reconcileTelemetrySnapshot(
        Router $router,
        array $snapshot,
        bool $complete,
        bool $sourceConfirmed,
        ?\DateTimeInterface $observedAt,
        array $wiredSnapshot,
        bool $wiredComplete,
        bool $wiredSourceConfirmed,
        array $arpSnapshot,
        bool $arpSourceConfirmed,
        array $dhcpLeases,
        bool $dhcpLeaseSnapshotComplete,
        bool $dhcpLeaseDynamicSupported,
    ): array {
        $wirelessSnapshot = array_values(array_filter(
            $snapshot,
            fn (array $client): bool => ($client['connectionType'] ?? 'wireless') === 'wireless',
        ));
        $wirelessMacs = array_fill_keys(array_filter(array_map(
            fn (array $client): ?string => MacAddress::normalize((string) ($client['macAddress'] ?? '')),
            $wirelessSnapshot,
        )), true);
        $wiredSnapshot = array_values(array_filter(
            $wiredSnapshot,
            fn (array $client): bool => ($client['connectionType'] ?? null) === 'wired'
                && ! isset($wirelessMacs[MacAddress::normalize((string) ($client['macAddress'] ?? '')) ?? '']),
        ));
        $arpSnapshot = array_values(array_filter(
            $arpSnapshot,
            fn (array $client): bool => ($client['connectionType'] ?? null) === 'arp',
        ));
        $receivedCount = count($wirelessSnapshot);
        $normalizedMacs = [];
        $rejectedCount = 0;
        $leaseMetadataMergedCount = 0;

        foreach ($wirelessSnapshot as $client) {
            $mac = MacAddress::normalize((string) ($client['macAddress'] ?? ''));
            if ($mac === null) {
                $rejectedCount++;

                continue;
            }

            $normalizedMacs[$mac] = true;
            if (trim((string) ($client['ipAddress'] ?? '')) !== '') {
                $leaseMetadataMergedCount++;
            }
        }

        $beforeCount = NetworkClient::query()->where('router_id', $router->id)->count();
        $clients = $this->persistSnapshot(
            $router,
            $sourceConfirmed ? $wirelessSnapshot : [],
            $complete && $sourceConfirmed,
            $observedAt,
            'wireless',
        );
        $clients = $this->persistSnapshot(
            $router,
            $wiredSourceConfirmed ? $wiredSnapshot : [],
            $wiredComplete && $wiredSourceConfirmed,
            $observedAt,
            'wired',
        );
        if ($arpSourceConfirmed) {
            $this->persistArpNeighbors($router, $arpSnapshot, $observedAt);
        }
        $staticLeaseAssignmentsUpdated = $this->reconcileStaticLeaseAddresses(
            $router,
            $dhcpLeases,
            $dhcpLeaseSnapshotComplete,
            $dhcpLeaseDynamicSupported,
        );
        $clients = NetworkClient::query()
            ->where('router_id', $router->id)
            ->orderByRaw('LOWER(COALESCE(hostname, mac_address))')
            ->get();
        $afterCount = $clients->count();
        $acceptedCount = count($normalizedMacs);
        $insertedCount = max(0, $afterCount - $beforeCount);
        $retainedHistoricalCount = $clients
            ->filter(fn (NetworkClient $client): bool => ! isset(
                $normalizedMacs[MacAddress::normalize($client->mac_address) ?? ''],
            ))
            ->count();

        return [
            'clients' => $clients,
            'counts' => [
                'received' => $receivedCount,
                'accepted' => $acceptedCount,
                'confirmed' => $sourceConfirmed ? $acceptedCount : 0,
                'unconfirmed' => $sourceConfirmed ? 0 : $acceptedCount,
                'inserted' => $insertedCount,
                'updated' => $sourceConfirmed ? max(0, $acceptedCount - $insertedCount) : 0,
                'mergedDuplicateRecords' => max(0, $receivedCount - $rejectedCount - $acceptedCount),
                'leaseMetadataMerged' => $leaseMetadataMergedCount,
                'rejected' => $rejectedCount,
                'retainedHistorical' => $retainedHistoricalCount,
                'persistedTotal' => $afterCount,
                'wiredReceived' => count($wiredSnapshot),
                'wiredConfirmed' => $wiredSourceConfirmed ? count($wiredSnapshot) : 0,
                'wiredCollectionComplete' => $wiredComplete && $wiredSourceConfirmed,
                'arpNeighborsReceived' => count($arpSnapshot),
                'arpNeighborsDiscovered' => $arpSourceConfirmed ? count($arpSnapshot) : 0,
                'staticLeaseAssignmentsUpdated' => $staticLeaseAssignmentsUpdated,
            ],
        ];
    }

    private function reconcileStaticLeaseAddresses(
        Router $router,
        array $leases,
        bool $complete,
        bool $dynamicFlagSupported,
    ): int {
        if (! $dynamicFlagSupported) {
            return 0;
        }

        $addressesByMac = [];
        $conflictingMacs = [];
        $staticLeaseMacs = [];
        foreach ($leases as $lease) {
            if (($lease['dynamic'] ?? null) !== false) {
                continue;
            }

            $mac = MacAddress::normalize((string) ($lease['macAddress'] ?? ''));
            if ($mac !== null) {
                $staticLeaseMacs[$mac] = true;
            }
            $address = IpAddress::normalize($lease['address'] ?? null);
            if ($mac === null || $address === null) {
                continue;
            }
            if (isset($addressesByMac[$mac]) && $addressesByMac[$mac] !== $address) {
                $conflictingMacs[$mac] = true;
                unset($addressesByMac[$mac]);

                continue;
            }
            if (! isset($conflictingMacs[$mac])) {
                $addressesByMac[$mac] = $address;
            }
        }

        $updated = 0;
        foreach (NetworkClient::query()->where('router_id', $router->id)->get() as $client) {
            $mac = MacAddress::normalize($client->mac_address);
            if ($mac === null || isset($conflictingMacs[$mac])) {
                continue;
            }
            $configuredAddress = $addressesByMac[$mac] ?? null;
            if ($configuredAddress === null && isset($staticLeaseMacs[$mac])) {
                continue;
            }
            if (! $complete && $configuredAddress === null) {
                continue;
            }
            if ($client->configured_ip_address !== $configuredAddress) {
                $client->forceFill(['configured_ip_address' => $configuredAddress])->save();
                $updated++;
            }
        }

        return $updated;
    }

    private function persistArpNeighbors(
        Router $router,
        array $snapshot,
        ?\DateTimeInterface $observedAt,
    ): void {
        $observedAt ??= now();

        foreach ($snapshot as $neighbor) {
            $mac = MacAddress::normalize((string) ($neighbor['macAddress'] ?? ''));
            $ipAddress = IpAddress::normalize($neighbor['ipAddress'] ?? null);
            if ($mac === null || MacAddress::isBridgePortArtifact($mac)) {
                continue;
            }

            $record = $this->findByMac($router, $mac);
            $record ??= new NetworkClient([
                'router_id' => $router->id,
                'mac_address' => $mac,
                'connection_type' => 'arp',
                'status' => 'unknown',
                'first_seen_at' => $observedAt,
            ]);
            if ($record->exists && $record->last_seen_at !== null && $record->last_seen_at->gt($observedAt)) {
                continue;
            }

            if ($record->connection_type === 'arp') {
                $record->fill([
                    'hostname' => trim((string) ($neighbor['hostname'] ?? '')) ?: $record->hostname,
                    'interface_id' => trim((string) ($neighbor['interfaceId'] ?? '')) ?: $record->interface_id,
                    'interface_name' => trim((string) ($neighbor['interfaceName'] ?? '')) ?: $record->interface_name,
                    'signal' => null,
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                    'last_seen_at' => $observedAt,
                    'status' => 'unknown',
                    'offline_reason' => null,
                ]);
            }
            if ($ipAddress !== null
                && (! $record->ip_address_observed_at
                    || $record->ip_address_observed_at->lessThanOrEqualTo($observedAt))) {
                $record->ip_address = $ipAddress;
                $record->ip_address_observed_at = $observedAt;
            }
            if (! $record->exists) {
                $record->id = (string) Str::uuid();
            }
            $record->save();
        }
    }

    private function persistSnapshot(
        Router $router,
        array $snapshot,
        bool $complete,
        ?\DateTimeInterface $observedAt,
        string $connectionType,
    ): Collection {
        $snapshot = array_values(array_filter(
            $snapshot,
            fn (array $client): bool => ($client['connectionType'] ?? null) === $connectionType,
        ));

        $observedMacs = [];
        $observedAt ??= now();

        foreach ($snapshot as $client) {
            $mac = MacAddress::normalize((string) ($client['macAddress'] ?? ''));
            if ($mac === null || MacAddress::isBridgePortArtifact($mac)) {
                continue;
            }

            $observedMacs[] = $mac;
            $record = $this->findByMac($router, $mac);
            $record ??= new NetworkClient([
                'router_id' => $router->id,
                'mac_address' => $mac,
            ]);
            if ($record->exists
                && $record->last_seen_at !== null
                && $record->last_seen_at->greaterThanOrEqualTo($observedAt)) {
                continue;
            }

            $hostname = trim((string) ($client['hostname'] ?? ''));
            $ipAddress = IpAddress::normalize($client['ipAddress'] ?? null);
            $interfaceId = trim((string) ($client['interfaceId'] ?? ''));
            $interfaceName = trim((string) ($client['interfaceName'] ?? ''));
            $record->fill([
                'mac_address' => $mac,
                'connection_type' => $connectionType,
                'hostname' => $hostname !== '' ? $hostname : $record->hostname,
                'interface_id' => $interfaceId !== '' ? $interfaceId : $record->interface_id,
                'interface_name' => $interfaceName !== '' ? $interfaceName : $record->interface_name,
                'signal' => $connectionType === 'wireless'
                    && isset($client['signal'])
                    && (int) $client['signal'] !== 0
                    ? (int) $client['signal']
                    : null,
                'rx_bytes' => (int) ($client['rxBytes'] ?? 0),
                'tx_bytes' => (int) ($client['txBytes'] ?? 0),
                'last_seen_at' => $observedAt,
                'status' => 'online',
                'offline_reason' => null,
            ]);
            if ($ipAddress !== null
                && (! $record->ip_address_observed_at
                    || $record->ip_address_observed_at->lessThanOrEqualTo($observedAt))) {
                $record->ip_address = $ipAddress;
                $record->ip_address_observed_at = $observedAt;
            }

            if (! $record->exists) {
                $record->id = (string) Str::uuid();
                $record->first_seen_at = $observedAt;
            }

            $record->save();
        }

        if ($complete) {
            $missingClients = NetworkClient::query()
                ->where('router_id', $router->id)
                ->where('connection_type', $connectionType)
                ->where('status', 'online');

            if ($observedMacs !== []) {
                $missingClients->whereNotIn('mac_address', array_unique($observedMacs));
            }

            $missingClients->where(function ($query) use ($observedAt): void {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $observedAt);
            });
            $missingClients->update([
                'status' => 'offline',
                'offline_reason' => $connectionType === 'wireless'
                    ? "Not present in the router's active wireless registration table during the latest successful poll."
                    : "Not present in the router's dynamic bridge host table during the latest successful report.",
                'updated_at' => now(),
            ]);
        }

        $clients = NetworkClient::query()
            ->where('router_id', $router->id)
            ->orderByRaw('LOWER(COALESCE(hostname, mac_address))')
            ->get();

        return $clients;
    }

    private function findByMac(Router $router, string $mac): ?NetworkClient
    {
        return NetworkClient::query()
            ->where('router_id', $router->id)
            ->whereIn('mac_address', MacAddress::variants($mac))
            ->first();
    }

    public function forRouter(Router $router): Collection
    {
        return NetworkClient::query()
            ->where('router_id', $router->id)
            ->orderByRaw('LOWER(COALESCE(hostname, mac_address))')
            ->get();
    }

    public function present(NetworkClient $client, ?string $routerError = null): array
    {
        $router = $client->router;
        $routerError ??= $router?->last_error_category;
        $storedStatus = $client->status ?? 'unknown';
        $isTelemetry = $router?->api_transport === 'telemetry';
        $isArpNeighbor = $client->connection_type === 'arp';
        $staleSeconds = max(15, (int) config('mikrotik.telemetry_stale_seconds', 15));
        $telemetry = $router?->latest_telemetry ?? [];
        $telemetryStale = $isTelemetry
            && ($router->telemetry_received_at === null
                || $router->telemetry_received_at->lt(now()->subSeconds($staleSeconds)));
        $wirelessClients = $telemetry['wirelessClientsLastReport'] ?? [];
        $bridgeHosts = $telemetry['bridgeHostsLastReport'] ?? [];
        $arpNeighbors = $telemetry['arpNeighborsLastReport'] ?? [];
        $clientMac = MacAddress::normalize($client->mac_address);
        $hasMac = fn (array $rows): bool => $clientMac !== null && collect($rows)->contains(
            fn (array $row): bool => MacAddress::normalize((string) ($row['macAddress'] ?? '')) === $clientMac,
        );
        $wirelessSucceeded = ($telemetry['wirelessCollectionSucceeded'] ?? null) === true;
        $wirelessComplete = ($telemetry['wirelessClientsComplete'] ?? false) === true;
        $wirelessFresh = ($telemetry['wirelessInventoryFresh'] ?? false) === true;
        $bridgeSucceeded = ($telemetry['bridgeHostsCollectionSucceeded'] ?? null) === true;
        $bridgeComplete = ($telemetry['bridgeHostsComplete'] ?? false) === true;
        $bridgeFresh = ($telemetry['bridgeHostsFresh'] ?? false) === true;
        $arpSucceeded = ($telemetry['arpNeighborsCollectionSucceeded'] ?? null) === true;
        $arpComplete = ($telemetry['arpNeighborsComplete'] ?? false) === true;
        $arpFresh = ($telemetry['arpNeighborsFresh'] ?? false) === true;
        $observedWireless = $wirelessSucceeded && $wirelessFresh && $hasMac($wirelessClients);
        $observedWired = $bridgeSucceeded && $bridgeFresh && $hasMac($bridgeHosts);
        $sourceName = match ($client->connection_type) {
            'wired' => 'dynamic bridge-host table',
            'arp' => 'ARP neighbor table',
            'dhcp' => 'active wireless and bridge tables',
            default => 'active wireless registration table',
        };
        $observationSource = match ($client->connection_type) {
            'wired' => 'dynamic-bridge-host',
            'arp' => 'arp-neighbor',
            'dhcp' => 'dhcp-lease',
            default => 'wireless-registration',
        };
        $sourceConfirmed = match ($client->connection_type) {
            'wired' => $observedWired,
            'arp' => $arpSucceeded && $arpFresh && $hasMac($arpNeighbors),
            'dhcp' => $observedWireless || $observedWired,
            default => $observedWireless,
        };
        $sourceComplete = match ($client->connection_type) {
            'wired' => $bridgeSucceeded && $bridgeFresh && $bridgeComplete,
            'arp' => $arpSucceeded && $arpFresh && $arpComplete,
            'dhcp' => $wirelessSucceeded && $wirelessFresh && $wirelessComplete
                && $bridgeSucceeded && $bridgeFresh && $bridgeComplete,
            default => $wirelessSucceeded && $wirelessFresh && $wirelessComplete,
        };
        $status = $storedStatus;
        if ($isTelemetry) {
            if ($isArpNeighbor) {
                $status = 'unknown';
            } elseif ($sourceConfirmed) {
                $status = 'online';
                $observationSource = $observedWireless
                    ? 'wireless-registration'
                    : 'dynamic-bridge-host';
            } elseif ($sourceComplete) {
                $status = 'offline';
            } else {
                $status = 'unknown';
            }
        }
        $positivelyObservedOnline = $sourceConfirmed && ! $isArpNeighbor;
        $monitoringStale = $routerError !== null
            || $router?->status_override === 'offline'
            || $telemetryStale
            || ($isTelemetry && ! $sourceComplete && ! $positivelyObservedOnline);
        if ($isArpNeighbor) {
            $monitoringIssue = 'An ARP neighbor cache entry identifies this device but cannot confirm that it is online.';
        } elseif ($routerError !== null) {
            $monitoringIssue = "Router status is unconfirmed ({$routerError}); last confirmed status was {$storedStatus}.";
        } elseif ($telemetryStale) {
            $monitoringIssue = "Router telemetry is stale; last confirmed status was {$storedStatus}.";
        } elseif ($monitoringStale) {
            $monitoringIssue = "The latest router report did not provide a fresh, complete {$sourceName}; current presence is unconfirmed.";
        } elseif ($client->connection_type === 'dhcp' && ! $sourceConfirmed) {
            $monitoringIssue = 'A DHCP lease indicates address assignment but does not confirm current device presence.';
        } else {
            $monitoringIssue = null;
        }

        $applicationConfiguredIpAddress = IpAddress::normalize($client->application_configured_ip_address);
        $routerStaticLeaseIpAddress = IpAddress::normalize($client->configured_ip_address);
        $configuredIpAddress = $applicationConfiguredIpAddress ?? $routerStaticLeaseIpAddress;
        $routerObservedIpAddress = IpAddress::normalize($client->ip_address);
        $addressObservedAt = $client->ip_address_observed_at;
        $addressFresh = $addressObservedAt !== null
            && $addressObservedAt->gte(now()->subSeconds($staleSeconds));

        // A current bound lease is a fallback for a device whose ARP entry has
        // already aged out; it must never outrank the ARP table, because a lease
        // keeps naming its last assignment after the device moves to another
        // address (for example a manually configured one on the client).
        $leasesLive = $isTelemetry && ! $telemetryStale
            && ($telemetry['dhcpLeasesCollectionSucceeded'] ?? null) === true
            && ($telemetry['dhcpLeasesFresh'] ?? false) === true;
        $boundLeaseAddress = null;
        if ($leasesLive && $clientMac !== null) {
            foreach ($telemetry['dhcpLeases'] ?? [] as $lease) {
                $leaseMac = MacAddress::normalize((string) ($lease['macAddress'] ?? ''));
                $leaseAddress = IpAddress::normalize($lease['address'] ?? null);
                if ($leaseMac === $clientMac && ($lease['status'] ?? null) === 'bound' && $leaseAddress !== null) {
                    $boundLeaseAddress = (string) $leaseAddress;
                }
            }
        }

        // The router's live ARP table decides the displayed address. One MAC can
        // hold several rows while old entries age out, and RouterOS lists them in
        // table order with the newest last, so the last dynamic row is current.
        $arpTableLive = $isTelemetry && ! $telemetryStale && $arpSucceeded && $arpFresh && $arpComplete;
        $arpAddressesByMac = [];
        if ($arpTableLive) {
            foreach ($arpNeighbors as $neighbor) {
                $neighborMac = MacAddress::normalize((string) ($neighbor['macAddress'] ?? ''));
                $neighborAddress = IpAddress::normalize($neighbor['address'] ?? null);
                if ($neighborMac === null || $neighborAddress === null || ($neighbor['dynamic'] ?? null) !== true) {
                    continue;
                }
                $arpAddressesByMac[$neighborMac][] = $neighborAddress;
            }
        }
        $liveArpAddresses = $clientMac === null ? [] : ($arpAddressesByMac[$clientMac] ?? []);
        $liveArpAddress = $liveArpAddresses === [] ? null : (string) end($liveArpAddresses);

        $ipAddress = $liveArpAddress ?? $boundLeaseAddress ?? $configuredIpAddress ?? $routerObservedIpAddress;
        $addressSource = match (true) {
            $liveArpAddress !== null => 'arp-neighbor',
            $boundLeaseAddress !== null => 'dhcp-lease',
            $configuredIpAddress !== null => 'configured',
            $ipAddress !== null => 'last-observed',
            default => 'none',
        };
        // A mismatch means the router's live evidence disagrees with the address
        // the console or a static lease assigns, so it is compared against that
        // evidence rather than whatever address happens to be displayed.
        $liveEvidenceAddress = $liveArpAddress
            ?? $boundLeaseAddress
            ?? ($addressFresh ? $routerObservedIpAddress : null);
        $addressMismatch = $configuredIpAddress !== null
            && $liveEvidenceAddress !== null
            && $configuredIpAddress !== $liveEvidenceAddress;
        $lastConfirmedStatus = $isTelemetry && ! $telemetryStale && $routerError === null
            && in_array($status, ['online', 'offline'], true)
            ? $status
            : ($isArpNeighbor || $client->connection_type === 'dhcp' ? null : $storedStatus);

        return [
            'id' => $client->id,
            'routerId' => $client->router_id,
            'routerName' => $router?->name ?? '',
            'deviceIdentity' => $client->router_id.':'.(MacAddress::normalize($client->mac_address) ?? $client->mac_address),
            'interfaceId' => $client->interface_id ?? '',
            'interfaceName' => $client->interface_name ?? '',
            'hostname' => $client->hostname ?? '',
            'alias' => $client->alias,
            'displayName' => $client->alias ?: ($client->hostname ?: 'Unknown Device'),
            'aliasEditable' => true,
            'ipAddress' => $ipAddress ?? '',
            'ipAddressSource' => $addressSource,
            'configuredIpAddress' => $configuredIpAddress,
            'applicationConfiguredIpAddress' => $applicationConfiguredIpAddress,
            'routerStaticLeaseIpAddress' => $routerStaticLeaseIpAddress,
            'routerObservedIpAddress' => $routerObservedIpAddress,
            'ipAddressObservedAt' => $addressObservedAt?->toIso8601String(),
            'ipConfigurationScope' => $applicationConfiguredIpAddress !== null
                ? 'application-only'
                : ($routerStaticLeaseIpAddress !== null ? 'router-static-dhcp-lease' : 'router-observation'),
            'addressMismatch' => $addressMismatch,
            'macAddress' => MacAddress::normalize($client->mac_address) ?? $client->mac_address,
            'connectionType' => $client->connection_type,
            'signal' => $client->signal === 0 ? null : $client->signal,
            'rxBytes' => $client->rx_bytes,
            'txBytes' => $client->tx_bytes,
            'connectedAt' => $client->first_seen_at?->toIso8601String(),
            'firstSeenAt' => $client->first_seen_at?->toIso8601String(),
            'lastSeenAt' => $client->last_seen_at?->toIso8601String(),
            'routerTelemetryEnabled' => $client->router?->api_transport === 'telemetry',
            'routerTelemetryObservedAt' => $client->router?->telemetry_observed_at?->toIso8601String(),
            'routerTelemetryReceivedAt' => $client->router?->telemetry_received_at?->toIso8601String(),
            'routerLastSuccessfulReportAt' => $router?->telemetry_received_at?->toIso8601String()
                ?? $router?->last_seen_at?->toIso8601String(),
            'observationSource' => $observationSource,
            'status' => $routerError !== null || $telemetryStale ? 'unknown' : $status,
            'lastConfirmedStatus' => $lastConfirmedStatus,
            'monitoringStale' => $monitoringStale,
            'monitoringFresh' => ! $monitoringStale,
            'monitoringIssue' => $monitoringIssue,
            'offlineReason' => $client->offline_reason,
        ];
    }
}

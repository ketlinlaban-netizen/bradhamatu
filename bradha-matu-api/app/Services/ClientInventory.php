<?php

namespace App\Services;

use App\Models\NetworkClient;
use App\Models\Router;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ClientInventory
{
    /**
     * Save a successful router snapshot and mark previously known, missing
     * clients offline. A failed router query must never call this method.
     */
    public function recordSnapshot(Router $router, array $snapshot): Collection
    {
        // A DHCP lease can remain bound after a station has disconnected.
        // Only the active wireless registration table confirms Wi-Fi presence.
        $snapshot = array_values(array_filter(
            $snapshot,
            fn (array $client): bool => ($client['connectionType'] ?? 'dhcp') === 'wireless',
        ));

        $observedMacs = [];
        $now = now();

        foreach ($snapshot as $client) {
            $mac = strtoupper(trim((string) ($client['macAddress'] ?? '')));
            if ($mac === '') {
                continue;
            }

            $observedMacs[] = $mac;
            $record = NetworkClient::firstOrNew([
                'router_id' => $router->id,
                'mac_address' => $mac,
            ]);

            $record->fill([
                'hostname' => $client['hostname'] ?? null,
                'ip_address' => $client['ipAddress'] ?? null,
                'interface_id' => $client['interfaceId'] ?? null,
                'interface_name' => $client['interfaceName'] ?? null,
                'signal' => isset($client['signal']) ? (int) $client['signal'] : null,
                'rx_bytes' => (int) ($client['rxBytes'] ?? 0),
                'tx_bytes' => (int) ($client['txBytes'] ?? 0),
                'last_seen_at' => $now,
                'status' => 'online',
                'offline_reason' => null,
            ]);

            if (! $record->exists) {
                $record->id = (string) Str::uuid();
                $record->first_seen_at = $now;
            }

            $record->save();
        }

        $missingClients = NetworkClient::query()
            ->where('router_id', $router->id)
            ->where('status', 'online');

        if ($observedMacs !== []) {
            $missingClients->whereNotIn('mac_address', array_unique($observedMacs));
        }

        $missingClients->update([
            'status' => 'offline',
            'offline_reason' => "Not present in the router's active wireless registration table during the latest successful poll.",
            'updated_at' => $now,
        ]);

        return NetworkClient::query()
            ->where('router_id', $router->id)
            ->orderByRaw('LOWER(COALESCE(hostname, mac_address))')
            ->get();
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
        $routerError ??= $client->router?->last_error_category;
        $storedStatus = $client->status ?? 'offline';
        $routerOffline = ($routerError !== null || $client->router?->status_override === 'offline');
        $unknown = $routerOffline && $storedStatus === 'online';
        $status = $unknown ? 'unknown' : $storedStatus;

        return [
            'id' => $client->id,
            'routerId' => $client->router_id,
            'routerName' => $client->router?->name ?? '',
            'interfaceId' => $client->interface_id ?? '',
            'interfaceName' => $client->interface_name ?? '',
            'hostname' => $client->hostname ?? '',
            'alias' => $client->alias,
            'displayName' => $client->alias ?: ($client->hostname ?: 'Unknown Device'),
            'aliasEditable' => true,
            'ipAddress' => $client->ip_address ?? '',
            'macAddress' => $client->mac_address,
            'signal' => $client->signal ?? 0,
            'rxBytes' => $client->rx_bytes,
            'txBytes' => $client->tx_bytes,
            'connectedAt' => $client->first_seen_at?->toIso8601String(),
            'firstSeenAt' => $client->first_seen_at?->toIso8601String(),
            'lastSeenAt' => $client->last_seen_at?->toIso8601String(),
            'status' => $status,
            'offlineReason' => $unknown
                ? "Router status is unconfirmed ({$routerError}); client status could not be refreshed."
                : $client->offline_reason,
        ];
    }
}

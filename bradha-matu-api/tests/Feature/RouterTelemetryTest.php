<?php

namespace Tests\Feature;

use App\Models\NetworkClient;
use App\Models\Router;
use App\Models\RouterMetric;
use App\Models\User;
use App\Services\MikroTikRouterProvider;
use App\Services\RouterReadTransport;
use App\Services\RouterTelemetryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_unauthenticated_or_unknown_router_telemetry_is_rejected(): void
    {
        $router = $this->createTelemetryRouter();

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $this->payload($router))
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="router-telemetry"');

        $payload = $this->payload($router);
        $payload['router_id'] = (string) Str::uuid();
        $this->withToken('untrusted-token')
            ->postJson('/api/v1/internal/mikrotik/telemetry', $payload)
            ->assertUnauthorized();
    }

    public function test_malformed_overspecified_and_invalid_nested_payloads_are_rejected(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN, 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', '/api/v1/internal/mikrotik/telemetry', [], [], [], $headers, '{')
            ->assertBadRequest();

        $extraField = $this->payload($router);
        $extraField['command'] = '/system reboot';
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $extraField, $headers)
            ->assertUnprocessable()
            ->assertHeader('X-Telemetry-Error', 'unsupported_fields');

        $invalidNestedRow = $this->payload($router);
        $invalidNestedRow['interfaces'] = ['not-an-interface-object'];
        $response = $this->postJson('/api/v1/internal/mikrotik/telemetry', $invalidNestedRow, $headers)
            ->assertUnprocessable();
        $this->assertStringStartsWith('validation:', $response->headers->get('X-Telemetry-Error'));
    }

    public function test_route_and_address_validation_runs_when_no_interfaces_are_collected(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['interfaces'] = [];
        $payload['interface_count'] = 0;
        $payload['interfaces_collected_count'] = 0;
        $payload['ip_addresses'] = [[
            'address' => '192.168.88.1',
            'network' => '192.168.88.0',
            'interface' => 'bridge',
            'disabled' => false,
            'dynamic' => false,
        ]];
        $payload['ip_address_count'] = 1;
        $payload['ip_addresses_collected_count'] = 1;
        $payload['routes'] = [[
            'id' => '*default',
            'dst_address' => '192.168.40.1',
            'gateway' => '192.168.40.1',
            'immediate_gateway' => '192.168.40.1%ether1',
            'distance' => 1,
            'active' => true,
            'dynamic' => false,
            'static' => true,
            'disabled' => false,
            'routing_table' => 'main',
        ]];
        $payload['route_count'] = 1;
        $payload['routes_collected_count'] = 1;

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, [
            'Authorization' => 'Bearer '.self::TOKEN,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'ip_addresses.0.address',
                'routes.0.dst_address',
            ]);
    }

    public function test_legacy_report_does_not_count_empty_interface_ip_as_a_configured_address(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['snapshot_id'] = 'legacy-empty-interface-ip';
        $payload['interfaces'][0]['ip_address'] = '';
        unset(
            $payload['ip_addresses'],
            $payload['ip_addresses_collection_succeeded'],
            $payload['ip_addresses_complete'],
            $payload['ip_address_count'],
            $payload['ip_addresses_collected_count'],
        );

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, [
            'Authorization' => 'Bearer '.self::TOKEN,
        ])->assertOk();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('ipAddresses', [])
            ->assertJsonPath('ipAddressReceivedCount', 0)
            ->assertJsonPath('ipAddressCollectedCount', 0)
            ->assertJsonPath('ipAddressCollectionSucceeded', null)
            ->assertJsonPath('ipAddressCollectionComplete', null);
    }

    public function test_valid_reports_are_authenticated_persisted_and_duplicate_posts_are_idempotent(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['system']['uptime'] = '2d00:32:22';
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('snapshotId', $payload['snapshot_id'])
            ->assertJsonPath('inventory.received', 1)
            ->assertJsonPath('inventory.accepted', 1)
            ->assertJsonPath('inventory.inserted', 1)
            ->assertJsonPath('inventory.updated', 0)
            ->assertJsonPath('inventory.leaseMetadataMerged', 1)
            ->assertJsonPath('inventory.retainedHistorical', 0);

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $router->refresh();
        $this->assertSame($payload['snapshot_id'], $router->telemetry_snapshot_id);
        $this->assertSame('TEST-RB951-TELEMETRY', $router->latest_telemetry['system']['identity']);
        $this->assertSame('TEST-RB951-TELEMETRY', $router->identity);
        $this->assertNotNull($router->telemetry_observed_at);
        $this->assertNotNull($router->telemetry_received_at);
        $this->assertSame(
            $payload['snapshot_id'],
            DB::table('routers')->where('id', $router->id)->value('telemetry_snapshot_id'),
        );
    }

    public function test_router_local_gmt_offset_is_stored_as_utc_and_does_not_replay_later_reports(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];

        // The generated RouterOS collector stamps observed_at with the board's own
        // offset, while every other test in this file has always sent UTC.
        $nairobi = now()->timezone('Africa/Nairobi')->format(DATE_ATOM);
        $first = $this->payload($router, $nairobi);
        $second = $this->payload($router, Carbon::parse($nairobi)->addSeconds(5)->format(DATE_ATOM));
        $second['snapshot_id'] = 'offset-follow-up';

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $first, $headers)->assertOk();
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $second, $headers)
            ->assertOk()
            ->assertJsonPath('duplicate', false);

        $router->refresh();
        $this->assertSame(
            Carbon::parse($second['observed_at'])->timezone('UTC')->toIso8601String(),
            $router->telemetry_observed_at->toIso8601String(),
        );
        $this->assertLessThanOrEqual(
            10,
            $router->telemetry_observed_at->diffInSeconds($router->telemetry_received_at),
        );
    }

    public function test_routeros_7_25_numeric_version_and_unset_route_flags_are_accepted(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];

        // ":serialize" emits an unquoted number for a version with no channel label, which is what
        // RouterOS 7.25 returns, and a flag the board does not compute arrives as an empty value.
        $payload = $this->payload($router);
        $payload['system']['version'] = 7.25;
        $payload['routes'] = [
            [
                'id' => '*A',
                'dst_address' => '0.0.0.0/0',
                'gateway' => '192.168.40.1',
                'immediate_gateway' => '192.168.40.1%ether1',
                'distance' => 1,
                'active' => true,
                'dynamic' => false,
                'static' => true,
                'disabled' => false,
                'routing_table' => 'main',
            ],
            [
                'id' => '*B',
                'dst_address' => '0.0.0.0/0',
                'gateway' => '10.10.10.1',
                'immediate_gateway' => '10.10.10.1%ether2',
                'distance' => 2,
                'active' => '',
                'dynamic' => 'no',
                'static' => 'yes',
                'disabled' => 0,
                'routing_table' => 'main',
            ],
        ];
        $payload['route_count'] = 2;
        $payload['routes_collected_count'] = 2;

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)->assertOk();

        $router->refresh();
        $this->assertSame('7.25', $router->last_routeros_version);
        $this->assertSame('7.25', $router->latest_telemetry['system']['version']);
        $this->assertSame(
            [['active' => true, 'disabled' => false], ['active' => false, 'disabled' => false]],
            array_map(fn (array $route): array => [
                'active' => $route['active'],
                'disabled' => $route['disabled'],
            ], $router->latest_telemetry['routes']),
        );
    }

    /**
     * Production signature from communitygroup on 2026-11-04: a second default route whose "active"
     * flag RouterOS answers with "nothing", which ":serialize" emits as JSON null. 10,271 consecutive
     * reports were rejected on routes.1.active with raw_representations empty, because the normalizer
     * treated null as already resolved. The sibling case of a key the board omits entirely is covered
     * by the same route entry, since that fails the "array:..." key rule on the parent field.
     */
    public function test_route_flag_reported_as_null_or_omitted_is_stored_as_false_instead_of_rejected(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];

        $payload = $this->payload($router);
        $payload['routes'] = [
            [
                'id' => '*A',
                'dst_address' => '0.0.0.0/0',
                'gateway' => '192.168.1.1',
                'immediate_gateway' => '192.168.1.1%bridge1',
                'distance' => 1,
                'active' => true,
                'dynamic' => true,
                'static' => false,
                'disabled' => null,
                'routing_table' => 'main',
            ],
            [
                'id' => '*B',
                'dst_address' => '0.0.0.0/0',
                'gateway' => '10.10.10.1',
                'immediate_gateway' => '10.10.10.1%ether2',
                'distance' => 2,
                'active' => null,
                'dynamic' => 'no',
                'static' => 'yes',
                // "disabled" intentionally omitted, as a build that never reports the property would.
                'routing_table' => 'main',
            ],
        ];
        $payload['route_count'] = 2;
        $payload['routes_collected_count'] = 2;

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('duplicate', false);

        $router->refresh();
        $this->assertSame(
            [['active' => true, 'disabled' => false], ['active' => false, 'disabled' => false]],
            array_map(fn (array $route): array => [
                'active' => $route['active'],
                'disabled' => $route['disabled'],
            ], $router->latest_telemetry['routes']),
        );
    }

    public function test_static_dhcp_lease_updates_saved_client_ip_without_confirming_presence(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $initial = $this->payload($router);

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $initial, $headers)->assertOk();
        $client = NetworkClient::query()->where('mac_address', 'AA:BB:CC:DD:EE:01')->sole();
        $this->assertSame('online', $client->status);
        $this->assertSame('192.168.88.10', $client->ip_address);

        $static = $this->payload(
            $router,
            Carbon::parse($initial['observed_at'])->addSeconds(5)->toIso8601String(),
        );
        $static['snapshot_id'] = 'static-lease-address-updated';
        $static['dhcp_leases'][0]['dynamic'] = false;
        $static['dhcp_leases'][0]['address'] = '192.168.88.77';
        $static['dhcp_leases'][0]['status'] = 'waiting';
        $static['wireless_clients'] = [];
        $static['wireless_registration_count'] = 0;
        $static['wireless_collected_count'] = 0;

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $static, $headers)
            ->assertOk()
            ->assertJsonPath('inventory.staticLeaseAssignmentsUpdated', 1);

        $client->refresh();
        $this->assertSame('192.168.88.77', $client->configured_ip_address);
        $this->assertSame('192.168.88.10', $client->ip_address);
        $this->assertSame('offline', $client->status);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('0.ipAddress', '192.168.88.77')
            ->assertJsonPath('0.configuredIpAddress', '192.168.88.77')
            ->assertJsonPath('0.routerObservedIpAddress', '192.168.88.10')
            ->assertJsonPath('0.addressMismatch', true)
            ->assertJsonPath('0.status', 'offline');
    }

    public function test_fresh_arp_report_overrides_static_lease_for_displayed_ip(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $initial = $this->payload($router);

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $initial, $headers)->assertOk();

        $static = $this->payload(
            $router,
            Carbon::parse($initial['observed_at'])->addSeconds(5)->toIso8601String(),
        );
        $static['snapshot_id'] = 'arp-live-address-static-lease';
        $static['dhcp_leases'][0]['dynamic'] = false;
        $static['dhcp_leases'][0]['address'] = '192.168.88.77';
        $static['dhcp_leases'][0]['status'] = 'waiting';
        $static['arp_neighbors'] = [[
            'address' => '192.168.88.123',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'interface' => 'bridge',
            'dynamic' => true,
        ]];
        $static['arp_neighbors_collection_succeeded'] = true;
        $static['arp_neighbors_complete'] = true;
        $static['arp_neighbor_count'] = 1;
        $static['arp_neighbors_collected_count'] = 1;

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $static, $headers)->assertOk();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('0.ipAddress', '192.168.88.123')
            ->assertJsonPath('0.ipAddressSource', 'arp-neighbor')
            ->assertJsonPath('0.configuredIpAddress', '192.168.88.77')
            ->assertJsonPath('0.addressMismatch', true);

        $absent = $this->payload(
            $router,
            Carbon::parse($static['observed_at'])->addSeconds(5)->toIso8601String(),
        );
        $absent['snapshot_id'] = 'arp-live-address-absent';
        $absent['dhcp_leases'][0]['dynamic'] = false;
        $absent['dhcp_leases'][0]['address'] = '192.168.88.77';
        $absent['dhcp_leases'][0]['status'] = 'waiting';
        $absent['wireless_clients'] = [];
        $absent['wireless_registration_count'] = 0;
        $absent['wireless_collected_count'] = 0;
        $absent['arp_neighbors'] = [];
        $absent['arp_neighbors_collection_succeeded'] = true;
        $absent['arp_neighbors_complete'] = true;
        $absent['arp_neighbor_count'] = 0;
        $absent['arp_neighbors_collected_count'] = 0;

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $absent, $headers)->assertOk();

        // Absent from a live ARP table, the client still shows its best-known
        // address instead of a blank, and is not claimed to be live.
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('0.ipAddress', '192.168.88.77')
            ->assertJsonPath('0.ipAddressSource', 'configured')
            ->assertJsonPath('0.configuredIpAddress', '192.168.88.77');
    }

    public function test_legacy_report_without_static_lease_metadata_does_not_clear_known_assignment(): void
    {
        $router = $this->createTelemetryRouter();
        $client = NetworkClient::query()->create([
            'id' => (string) Str::uuid(),
            'router_id' => $router->id,
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'connection_type' => 'wireless',
            'configured_ip_address' => '192.168.88.77',
            'ip_address' => '192.168.88.10',
            'status' => 'offline',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now()->subMinutes(30),
        ]);
        $payload = $this->payload($router);
        unset($payload['dhcp_lease_dynamic_supported'], $payload['dhcp_leases'][0]['dynamic']);

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, [
            'Authorization' => 'Bearer '.self::TOKEN,
        ])->assertOk();

        $this->assertSame('192.168.88.77', $client->fresh()->configured_ip_address);
    }

    public function test_incomplete_wireless_report_preserves_last_good_inventory_without_refreshing_omitted_clients(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $complete = $this->payload($router);
        $complete['snapshot_id'] = 'complete-wireless-inventory';
        $complete['wireless_registration_count'] = 2;
        $complete['wireless_collected_count'] = 2;
        $complete['wireless_clients'][] = [
            'id' => '*2',
            'interface' => 'wlan1',
            'mac_address' => 'AA:BB:CC:DD:EE:02',
            'signal_strength' => -61,
            'bytes' => '300,400',
        ];
        $complete['dhcp_leases'][] = [
            'mac_address' => 'AA:BB:CC:DD:EE:02',
            'host_name' => 'Second device',
            'address' => '192.168.88.11',
            'interface' => 'bridge',
            'status' => 'bound',
            'dynamic' => true,
            'dynamic' => true,
            'expires_after' => '1h',
        ];
        $complete['dhcp_lease_count'] = 2;
        $complete['dhcp_collected_count'] = 2;
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $complete, $headers)
            ->assertOk()
            ->assertJsonPath('inventory.accepted', 2)
            ->assertJsonPath('inventory.inserted', 2);

        $partial = $this->payload(
            $router,
            Carbon::parse($complete['observed_at'])->addSeconds(5)->toIso8601String(),
        );
        $partial['snapshot_id'] = 'partial-wireless-inventory';
        $partial['wireless_registration_count'] = 2;
        $partial['wireless_collected_count'] = 1;
        $partial['wireless_clients_collection_succeeded'] = true;

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $partial, $headers)
            ->assertOk()
            ->assertJsonPath('inventory.received', 1)
            ->assertJsonPath('inventory.accepted', 1);

        $router->refresh();
        $snapshot = $router->latest_telemetry;
        $this->assertCount(2, $snapshot['wirelessClients']);
        $this->assertCount(1, $snapshot['wirelessClientsLastReport']);
        $this->assertFalse($snapshot['wirelessClientsComplete']);
        $this->assertTrue($snapshot['wirelessClientsUsingLastGoodSnapshot']);
        $this->assertSame($complete['observed_at'], $snapshot['wirelessClientsObservedAt']);
        $this->assertTrue($snapshot['wirelessInventoryFresh']);
        $wireless = app(MikroTikRouterProvider::class)->getWireless($router->id);
        $this->assertSame(1, $wireless['connectedClients']);
        $this->assertFalse($wireless['collectionComplete']);
        $this->assertSame($partial['observed_at'], $wireless['observedAt']);
        $this->assertFalse($wireless['collectionComplete']);
        $this->assertTrue($wireless['usingLastGoodSnapshot']);
        $this->assertSame(1, $wireless['receivedCount']);

        $firstClient = NetworkClient::query()
            ->where('router_id', $router->id)
            ->where('mac_address', 'AA:BB:CC:DD:EE:01')
            ->firstOrFail();
        $secondClient = NetworkClient::query()
            ->where('router_id', $router->id)
            ->where('mac_address', 'AA:BB:CC:DD:EE:02')
            ->firstOrFail();
        $this->assertSame($partial['observed_at'], $firstClient->last_seen_at?->toIso8601String());
        $this->assertSame($complete['observed_at'], $secondClient->last_seen_at?->toIso8601String());

        $status = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('wirelessCollectionComplete', false)
            ->assertJsonPath('wirelessUsingLastGoodSnapshot', true)
            ->assertJsonPath('wirelessObservedAt', $complete['observed_at']);
        $this->assertTrue($status->json('wirelessFresh'));

        $clients = collect($this->getJson('/api/clients')->assertOk()->json())->keyBy('macAddress');
        $this->assertSame('online', $clients['AA:BB:CC:DD:EE:01']['status']);
        $this->assertSame('unknown', $clients['AA:BB:CC:DD:EE:02']['status']);
        $this->assertSame('192.168.88.11', $clients['AA:BB:CC:DD:EE:02']['ipAddress']);
    }

    public function test_legacy_wireless_rows_without_collection_success_are_not_confirmed_online(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $confirmed = $this->payload($router);
        $confirmed['snapshot_id'] = 'confirmed-wireless-report';
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $confirmed, $headers)
            ->assertOk()
            ->assertJsonPath('inventory.confirmed', 1);

        $legacy = $this->payload(
            $router,
            Carbon::parse($confirmed['observed_at'])->addSeconds(30)->toIso8601String(),
        );
        $legacy['snapshot_id'] = 'legacy-wireless-report-without-source-flags';
        unset(
            $legacy['wireless_clients_collection_succeeded'],
            $legacy['wireless_clients_complete'],
            $legacy['wireless_registration_count'],
            $legacy['wireless_collected_count'],
        );

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $legacy, $headers)
            ->assertOk()
            ->assertJsonPath('inventory.received', 1)
            ->assertJsonPath('inventory.accepted', 1)
            ->assertJsonPath('inventory.confirmed', 0)
            ->assertJsonPath('inventory.unconfirmed', 1)
            ->assertJsonPath('inventory.updated', 0);

        $router->refresh();
        $this->assertSame(
            $legacy['observed_at'],
            $router->latest_telemetry['observedAt'],
        );
        $this->assertNull($router->latest_telemetry['wirelessCollectionSucceeded']);
        $this->assertFalse($router->latest_telemetry['wirelessClientsComplete']);
        $this->assertNull(app(MikroTikRouterProvider::class)->getWireless($router->id)['connectedClients']);

        $client = NetworkClient::query()->where('router_id', $router->id)->firstOrFail();
        $this->assertSame($confirmed['observed_at'], $client->last_seen_at?->toIso8601String());
        $this->assertSame('192.168.88.10', $client->ip_address);

        $clients = collect($this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->json())->keyBy('macAddress');
        $this->assertSame('unknown', $clients['AA:BB:CC:DD:EE:01']['status']);
        $this->assertSame('online', $clients['AA:BB:CC:DD:EE:01']['lastConfirmedStatus']);
        $this->assertSame($confirmed['observed_at'], $clients['AA:BB:CC:DD:EE:01']['lastSeenAt']);
    }

    public function test_four_router_confirmed_wireless_clients_and_dhcp_leases_are_reconciled_once(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['snapshot_id'] = 'rb951-four-client-inventory';
        $payload['interfaces'] = [
            [
                'id' => '*1',
                'name' => 'bridge',
                'type' => 'bridge',
                'running' => true,
                'disabled' => false,
                'mac_address' => '00:00:00:00:00:00',
                'rx_bytes' => 1000,
                'tx_bytes' => 1000,
                'ip_address' => '',
            ],
            [
                'id' => '*2',
                'name' => 'ether1',
                'type' => 'ether',
                'running' => true,
                'disabled' => false,
                'mac_address' => '00:00:00:00:00:00',
                'rx_bytes' => 2000,
                'tx_bytes' => 2000,
                'ip_address' => '',
            ],
            [
                'id' => '*3',
                'name' => 'wlan1',
                'type' => 'wlan',
                'running' => true,
                'disabled' => false,
                'mac_address' => 'D4:CA:6D:A5:BC:67',
                'rx_bytes' => 3000,
                'tx_bytes' => 3000,
                'ip_address' => '',
            ],
        ];
        $payload['interface_count'] = 3;
        $payload['interfaces_collected_count'] = 3;
        $payload['ip_addresses'] = [
            [
                'address' => '192.168.88.1/24',
                'network' => '192.168.88.0',
                'interface' => 'bridge',
                'disabled' => false,
                'dynamic' => false,
            ],
            [
                'address' => '192.168.40.100/24',
                'network' => '192.168.40.0',
                'interface' => 'ether1',
                'disabled' => false,
                'dynamic' => false,
            ],
        ];
        $payload['ip_address_count'] = 2;
        $payload['ip_addresses_collected_count'] = 2;
        $payload['routes'] = [[
            'id' => '*A',
            'dst_address' => '0.0.0.0/0',
            'gateway' => '192.168.40.1',
            'immediate_gateway' => '192.168.40.1%ether1',
            'distance' => 1,
            'active' => true,
            'dynamic' => false,
            'static' => true,
            'disabled' => false,
            'routing_table' => 'main',
        ]];
        $payload['route_count'] = 1;
        $payload['routes_collected_count'] = 1;
        $payload['dhcp_leases'] = [
            [
                'mac_address' => '9C:1C:37:56:5B:35',
                'host_name' => null,
                'address' => '192.168.88.248',
                'interface' => 'bridge',
                'status' => 'bound',
                'expires_after' => '1h',
            ],
            [
                'mac_address' => '90:E4:68:9C:02:DD',
                'host_name' => null,
                'address' => '192.168.88.252',
                'interface' => 'bridge',
                'status' => 'bound',
                'expires_after' => '1h',
            ],
            [
                'mac_address' => '2A:05:C5:6F:CE:69',
                'host_name' => 'HONOR-Play10',
                'address' => '192.168.88.247',
                'interface' => 'bridge',
                'status' => 'bound',
                'expires_after' => '1h',
            ],
            [
                'mac_address' => 'D8:F2:CA:ED:13:31',
                'host_name' => 'PANDATECHS',
                'address' => '192.168.88.253',
                'interface' => 'bridge',
                'status' => 'bound',
                'expires_after' => '1h',
            ],
        ];
        $payload['dhcp_leases_complete'] = true;
        $payload['dhcp_leases_collection_succeeded'] = true;
        $payload['dhcp_lease_count'] = 4;
        $payload['dhcp_collected_count'] = 4;
        $payload['wireless_clients'] = [
            [
                'id' => '*1',
                'interface' => 'wlan1',
                'mac_address' => '9C:1C:37:56:5B:35',
                'signal_strength' => -51,
                'bytes' => '1000,2000',
            ],
            [
                'id' => '*2',
                'interface' => 'wlan1',
                'mac_address' => '90:E4:68:9C:02:DD',
                'signal_strength' => -57,
                'bytes' => '3000,4000',
            ],
            [
                'id' => '*3',
                'interface' => 'wlan1',
                'mac_address' => '2A:05:C5:6F:CE:69',
                'signal_strength' => -63,
                'bytes' => '5000,6000',
            ],
            [
                'id' => '*4',
                'interface' => 'wlan1',
                'mac_address' => 'D8:F2:CA:ED:13:31',
                'signal_strength' => -48,
                'bytes' => '7000,8000',
            ],
        ];
        $payload['wireless_clients_complete'] = true;
        $payload['wireless_clients_collection_succeeded'] = true;
        $payload['wireless_registration_count'] = 4;
        $payload['wireless_collected_count'] = 4;
        unset($payload['wireless_clients'][1]['signal_strength']);
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', false);
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(4, NetworkClient::query()->where('router_id', $router->id)->count());
        $this->assertSame(4, NetworkClient::query()->where('router_id', $router->id)->where('status', 'online')->count());

        $user = User::factory()->create();
        $clientsResponse = $this->actingAs($user, 'sanctum')
            ->getJson("/api/routers/{$router->id}/clients")
            ->assertOk();
        $clients = collect($clientsResponse->json())->keyBy('macAddress');
        $this->assertCount(4, $clients);
        $networkClients = collect($this->getJson('/api/clients')->assertOk()->json())
            ->where('routerId', $router->id)
            ->keyBy('macAddress');
        $this->assertCount(4, $networkClients);
        foreach ([
            '9C:1C:37:56:5B:35' => ['192.168.88.248', 'Unknown Device'],
            '90:E4:68:9C:02:DD' => ['192.168.88.252', 'Unknown Device'],
            '2A:05:C5:6F:CE:69' => ['192.168.88.247', 'HONOR-Play10'],
            'D8:F2:CA:ED:13:31' => ['192.168.88.253', 'PANDATECHS'],
        ] as $mac => [$address, $displayName]) {
            $this->assertSame($address, $clients[$mac]['ipAddress']);
            $this->assertSame($displayName, $clients[$mac]['displayName']);
            $this->assertSame('wlan1', $clients[$mac]['interfaceName']);
            $this->assertSame('online', $clients[$mac]['status']);
            $this->assertSame('online', $networkClients[$mac]['lastConfirmedStatus']);
            $this->assertSame($payload['observed_at'], $clients[$mac]['lastSeenAt']);
        }

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('gatewayRoutes.0.gateway', '192.168.40.1')
            ->assertJsonPath('gatewayRoutes.0.immediateGateway', '192.168.40.1%ether1')
            ->assertJsonPath('gatewayRoutes.0.interface', 'ether1')
            ->assertJsonPath('gatewayRoutes.0.destination', '0.0.0.0/0')
            ->assertJsonPath('gatewayRoutes.0.active', true)
            ->assertJsonPath('interfaceCollectionSucceeded', true)
            ->assertJsonPath('interfaceCollectionComplete', true)
            ->assertJsonPath('interfaceReceivedCount', 3)
            ->assertJsonPath('interfaceObservedAt', $payload['observed_at'])
            ->assertJsonPath('interfaceFresh', true)
            ->assertJsonPath('wirelessCollectionSucceeded', true)
            ->assertJsonPath('wirelessCollectionComplete', true)
            ->assertJsonPath('wirelessExpectedCount', 4)
            ->assertJsonPath('wirelessReceivedCount', 4)
            ->assertJsonPath('wirelessObservedAt', $payload['observed_at'])
            ->assertJsonPath('wirelessFresh', true)
            ->assertJsonPath('routeCollectionSucceeded', true)
            ->assertJsonPath('routeCollectionComplete', true)
            ->assertJsonPath('gatewayObservedAt', $payload['observed_at'])
            ->assertJsonPath('telemetryObservedAt', $payload['observed_at'])
            ->assertJsonPath('ipAddresses.0', '192.168.88.1/24')
            ->assertJsonPath('ipAddresses.1', '192.168.40.100/24')
            ->assertJsonPath('ipAddressCollectionSucceeded', true)
            ->assertJsonPath('ipAddressCollectionComplete', true)
            ->assertJsonPath('ipAddressObservedAt', $payload['observed_at'])
            ->assertJsonPath('routerMacAddress', null)
            ->assertJsonMissing(['macAddresses' => ['00:00:00:00:00:00']]);

        $leases = $this->getJson("/api/routers/{$router->id}/dhcp-leases")
            ->assertOk()
            ->assertJsonPath('collectionComplete', true)
            ->assertJsonPath('expectedCount', 4)
            ->assertJsonPath('collectedCount', 4)
            ->assertJsonPath('receivedCount', 4)
            ->assertJsonPath('usingLastGoodSnapshot', false)
            ->json('leases');
        $this->assertCount(4, $leases);
        $leasesByMac = collect($leases)->keyBy('macAddress');
        foreach ([
            '9C:1C:37:56:5B:35' => '192.168.88.248',
            '90:E4:68:9C:02:DD' => '192.168.88.252',
            '2A:05:C5:6F:CE:69' => '192.168.88.247',
            'D8:F2:CA:ED:13:31' => '192.168.88.253',
        ] as $mac => $address) {
            $this->assertSame($address, $leasesByMac[$mac]['address']);
        }
        $this->assertNull($clients['90:E4:68:9C:02:DD']['signal']);

        $nextPayload = $payload;
        $nextPayload['snapshot_id'] = 'rb951-four-client-inventory-next';
        $nextPayload['observed_at'] = now()->addSecond()->format(DATE_ATOM);
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $nextPayload, $headers)
            ->assertOk()
            ->assertJsonPath('duplicate', false);
        $this->assertSame(4, NetworkClient::query()->where('router_id', $router->id)->count());

        $partialPayload = $nextPayload;
        $partialPayload['snapshot_id'] = 'rb951-four-client-inventory-partial';
        $partialPayload['observed_at'] = now()->addSeconds(2)->format(DATE_ATOM);
        $partialPayload['dhcp_leases'] = array_slice($partialPayload['dhcp_leases'], 0, 1);
        $partialPayload['dhcp_collected_count'] = 1;
        $partialPayload['wireless_clients'] = array_slice($partialPayload['wireless_clients'], 0, 1);
        $partialPayload['wireless_collected_count'] = 1;
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $partialPayload, $headers)->assertOk();
        $this->assertSame(4, NetworkClient::query()->where('router_id', $router->id)->count());
        $this->assertSame(
            4,
            NetworkClient::query()->where('router_id', $router->id)->where('status', 'online')->count(),
        );
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/clients")
            ->assertOk()
            ->assertJsonFragment([
                'macAddress' => 'D8:F2:CA:ED:13:31',
                'status' => 'unknown',
                'lastConfirmedStatus' => 'online',
                'monitoringStale' => true,
            ]);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/dhcp-leases")
            ->assertOk()
            ->assertJsonPath('collectionSucceeded', true)
            ->assertJsonPath('collectionComplete', false)
            ->assertJsonPath('expectedCount', 4)
            ->assertJsonPath('collectedCount', 1)
            ->assertJsonPath('receivedCount', 1)
            ->assertJsonPath('usingLastGoodSnapshot', true)
            ->assertJsonCount(4, 'leases');
    }

    public function test_dynamic_bridge_hosts_add_wired_clients_without_duplicating_wireless_clients(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['bridge_hosts'] = [
            [
                'id' => '*11',
                'mac_address' => 'AA:BB:CC:DD:EE:11',
                'bridge' => 'bridge',
                'on_interface' => 'ether2',
                'local' => false,
                'dynamic' => true,
            ],
            [
                'id' => '*12',
                'mac_address' => 'AA:BB:CC:DD:EE:01',
                'bridge' => 'bridge',
                'on_interface' => 'ether3',
                'local' => false,
                'dynamic' => true,
            ],
        ];
        $payload['bridge_hosts_collection_succeeded'] = true;
        $payload['bridge_hosts_complete'] = true;
        $payload['bridge_host_count'] = 2;
        $payload['bridge_hosts_collected_count'] = 2;
        $payload['snapshot_id'] = 'wired-bridge-host-report';

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, [
            'Authorization' => 'Bearer '.self::TOKEN,
        ])->assertOk()
            ->assertJsonPath('sources.bridgeHosts.complete', true)
            ->assertJsonPath('sources.bridgeHosts.expectedCount', 2)
            ->assertJsonPath('sources.bridgeHosts.receivedCount', 2);

        $clients = NetworkClient::query()->where('router_id', $router->id)->get()->keyBy('mac_address');
        $this->assertCount(2, $clients);
        $this->assertSame('wired', $clients['AA:BB:CC:DD:EE:11']->connection_type);
        $this->assertSame('online', $clients['AA:BB:CC:DD:EE:11']->status);
        $this->assertSame('ether2', $clients['AA:BB:CC:DD:EE:11']->interface_name);
        $this->assertSame('wireless', $clients['AA:BB:CC:DD:EE:01']->connection_type);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonFragment([
                'macAddress' => 'AA:BB:CC:DD:EE:11',
                'connectionType' => 'wired',
                'status' => 'online',
            ]);
    }

    public function test_bridge_host_row_with_unresolved_bridge_or_port_is_stored_instead_of_rejecting_the_report(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['snapshot_id'] = 'bridge-host-port-unresolved';
        $payload['bridge_hosts'] = [
            [
                'id' => '*236',
                'mac_address' => 'AA:BB:CC:DD:EE:41',
                'bridge' => '',
                'on_interface' => '',
                'local' => false,
                'dynamic' => true,
            ],
            [
                'id' => '*237',
                'mac_address' => 'AA:BB:CC:DD:EE:42',
                'bridge' => null,
                'on_interface' => null,
                'local' => false,
                'dynamic' => true,
            ],
        ];
        $payload['bridge_hosts_collection_succeeded'] = true;
        $payload['bridge_hosts_complete'] = true;
        $payload['bridge_host_count'] = 2;
        $payload['bridge_hosts_collected_count'] = 2;

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, [
            'Authorization' => 'Bearer '.self::TOKEN,
        ])->assertOk()
            ->assertJsonPath('sources.bridgeHosts.receivedCount', 2);

        $stored = array_map(
            fn (array $host): array => [$host['bridge'], $host['onInterface']],
            $router->fresh()->latest_telemetry['bridgeHostsLastReport'],
        );
        $this->assertSame([[null, null], [null, null]], $stored);

        $clients = NetworkClient::query()->where('router_id', $router->id)->get()->keyBy('mac_address');
        $this->assertSame('wired', $clients['AA:BB:CC:DD:EE:41']->connection_type);
        $this->assertEmpty($clients['AA:BB:CC:DD:EE:41']->interface_name);
    }

    public function test_bridge_row_learned_on_a_radio_port_is_not_wired_evidence(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $first = $this->payload($router);
        $first['interfaces'][] = [
            'id' => '*5',
            'name' => 'wlan1',
            'type' => 'wlan',
            'running' => true,
            'disabled' => false,
            'mac_address' => 'AA:BB:CC:DD:EE:F0',
            'rx_bytes' => 900,
            'tx_bytes' => 900,
            'ip_address' => null,
        ];
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $first, $headers)->assertOk();
        $station = NetworkClient::query()->where('mac_address', 'AA:BB:CC:DD:EE:01')->sole();
        $this->assertSame('wireless', $station->connection_type);

        // The station deauthed, so the registration table is empty, but RouterOS
        // keeps ageing its bridge row on the radio for minutes. That row is not
        // evidence of a wired device and must not hold the client online.
        $left = array_merge($first, [
            'snapshot_id' => 'radio-learned-bridge-row',
            'observed_at' => Carbon::parse($first['observed_at'])->addSeconds(5)->toIso8601String(),
            'wireless_clients' => [],
            'wireless_registration_count' => 0,
            'wireless_collected_count' => 0,
            'bridge_hosts' => [
                [
                    'id' => '*21',
                    'mac_address' => 'AA:BB:CC:DD:EE:01',
                    'bridge' => 'bridge',
                    'on_interface' => 'wlan1',
                    'local' => false,
                    'dynamic' => true,
                ],
                [
                    'id' => '*22',
                    'mac_address' => 'AA:BB:CC:DD:EE:31',
                    'bridge' => 'bridge',
                    'on_interface' => 'wlan1',
                    'local' => false,
                    'dynamic' => true,
                ],
            ],
            'bridge_host_count' => 2,
            'bridge_hosts_collected_count' => 2,
        ]);
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $left, $headers)->assertOk();

        $station->refresh();
        $this->assertSame('wireless', $station->connection_type);
        $this->assertNull(
            NetworkClient::query()->where('mac_address', 'AA:BB:CC:DD:EE:31')->first(),
            'A MAC learned only on a radio port must not be created as a wired client.',
        );

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonFragment([
                'macAddress' => 'AA:BB:CC:DD:EE:01',
                'connectionType' => 'wireless',
                'status' => 'offline',
            ]);
    }

    public function test_incomplete_bridge_host_report_does_not_mark_wired_clients_offline_or_change_wireless_status(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['wireless_clients'] = [[
            'id' => '*wifi',
            'interface' => 'wlan1',
            'mac_address' => 'AA:BB:CC:DD:EE:21',
            'signal_strength' => -55,
            'bytes' => '100,200',
        ]];
        $payload['wireless_clients_complete'] = true;
        $payload['wireless_clients_collection_succeeded'] = true;
        $payload['wireless_registration_count'] = 1;
        $payload['wireless_collected_count'] = 1;
        $payload['bridge_hosts'] = [[
            'id' => '*bridge',
            'mac_address' => 'AA:BB:CC:DD:EE:22',
            'bridge' => 'bridge',
            'on_interface' => 'ether2',
            'local' => false,
            'dynamic' => true,
        ]];
        $payload['bridge_hosts_collection_succeeded'] = true;
        $payload['bridge_hosts_complete'] = true;
        $payload['bridge_host_count'] = 1;
        $payload['bridge_hosts_collected_count'] = 1;
        $payload['snapshot_id'] = 'wired-complete-report';

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/internal/mikrotik/telemetry', $payload)
            ->assertOk();

        $incomplete = $payload;
        $incomplete['snapshot_id'] = 'wired-incomplete-report';
        $incomplete['observed_at'] = now()->addSecond()->format(DATE_ATOM);
        $incomplete['wireless_clients'] = [];
        $incomplete['wireless_registration_count'] = 0;
        $incomplete['wireless_collected_count'] = 0;
        $incomplete['bridge_hosts'] = [];
        $incomplete['bridge_hosts_complete'] = false;
        $incomplete['bridge_host_count'] = 1;
        $incomplete['bridge_hosts_collected_count'] = 0;

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/internal/mikrotik/telemetry', $incomplete)
            ->assertOk()
            ->assertJsonPath('sources.bridgeHosts.complete', false)
            ->assertJsonPath('sources.wirelessClients.complete', true);

        $clients = NetworkClient::query()->where('router_id', $router->id)->get()->keyBy('mac_address');
        $this->assertSame('online', $clients['AA:BB:CC:DD:EE:22']->status);
        $this->assertSame('wired', $clients['AA:BB:CC:DD:EE:22']->connection_type);
        $this->assertSame('offline', $clients['AA:BB:CC:DD:EE:21']->status);
        $this->assertSame('wireless', $clients['AA:BB:CC:DD:EE:21']->connection_type);
    }

    public function test_dynamic_arp_neighbors_are_listed_as_unknown_and_never_confirm_online_presence(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['wireless_clients'] = [[
            'id' => '*wifi',
            'interface' => 'wlan1',
            'mac_address' => 'AA:BB:CC:DD:EE:31',
            'signal_strength' => -55,
            'bytes' => '100,200',
        ]];
        $payload['wireless_clients_complete'] = true;
        $payload['wireless_clients_collection_succeeded'] = true;
        $payload['wireless_registration_count'] = 1;
        $payload['wireless_collected_count'] = 1;
        $payload['bridge_hosts'] = [];
        $payload['bridge_hosts_collection_succeeded'] = true;
        $payload['bridge_hosts_complete'] = true;
        $payload['bridge_host_count'] = 0;
        $payload['bridge_hosts_collected_count'] = 0;
        $payload['arp_neighbors'] = [
            [
                'address' => '192.168.88.31',
                'mac_address' => 'AA:BB:CC:DD:EE:31',
                'interface' => 'bridge',
                'dynamic' => true,
            ],
            [
                'address' => '192.168.88.32',
                'mac_address' => 'AA:BB:CC:DD:EE:32',
                'interface' => 'bridge',
                'dynamic' => true,
            ],
        ];
        $payload['arp_neighbors_collection_succeeded'] = true;
        $payload['arp_neighbors_complete'] = true;
        $payload['arp_neighbor_count'] = 2;
        $payload['arp_neighbors_collected_count'] = 2;
        $payload['snapshot_id'] = 'arp-neighbor-discovery';

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/internal/mikrotik/telemetry', $payload)
            ->assertOk()
            ->assertJsonPath('sources.arpNeighbors.complete', true)
            ->assertJsonPath('sources.arpNeighbors.expectedCount', 2)
            ->assertJsonPath('sources.arpNeighbors.receivedCount', 2);

        $clients = NetworkClient::query()->where('router_id', $router->id)->get()->keyBy('mac_address');
        $this->assertCount(2, $clients);
        $this->assertSame('wireless', $clients['AA:BB:CC:DD:EE:31']->connection_type);
        $this->assertSame('arp', $clients['AA:BB:CC:DD:EE:32']->connection_type);
        $this->assertSame('unknown', $clients['AA:BB:CC:DD:EE:32']->status);
        $this->assertSame('192.168.88.32', $clients['AA:BB:CC:DD:EE:32']->ip_address);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonFragment([
                'macAddress' => 'AA:BB:CC:DD:EE:32',
                'connectionType' => 'arp',
                'status' => 'unknown',
                'lastConfirmedStatus' => null,
            ]);

        $incomplete = $payload;
        $incomplete['snapshot_id'] = 'arp-neighbor-incomplete';
        $incomplete['observed_at'] = now()->addSecond()->format(DATE_ATOM);
        $incomplete['arp_neighbors'] = [];
        $incomplete['arp_neighbors_complete'] = false;
        $incomplete['arp_neighbor_count'] = 1;
        $incomplete['arp_neighbors_collected_count'] = 0;

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/internal/mikrotik/telemetry', $incomplete)
            ->assertOk()
            ->assertJsonPath('sources.arpNeighbors.complete', false);
        $this->assertSame(
            'unknown',
            NetworkClient::query()
                ->where('router_id', $router->id)
                ->where('mac_address', 'AA:BB:CC:DD:EE:32')
                ->sole()
                ->status,
        );
    }

    public function test_router_mac_is_taken_from_the_unique_bridge_interface_not_the_reported_client_mac(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['interfaces'] = [
            [
                'id' => '*bridge',
                'name' => 'bridge',
                'type' => 'bridge',
                'running' => true,
                'disabled' => false,
                'mac_address' => 'D4:CA:6D:A5:BC:67',
                'rx_bytes' => 1000,
                'tx_bytes' => 500,
                'ip_address' => '',
            ],
            $payload['interfaces'][0],
        ];
        $payload['interface_count'] = 2;
        $payload['interfaces_collected_count'] = 2;
        $payload['router_mac_address'] = '9C:1C:37:56:5B:35';

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/internal/mikrotik/telemetry', $payload)
            ->assertOk();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('routerMacAddress', 'D4:CA:6D:A5:BC:67')
            ->assertJsonMissing(['routerMacAddress' => '9C:1C:37:56:5B:35']);
    }

    public function test_complete_empty_route_snapshot_differs_from_failed_collection_and_keeps_last_good_values(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $payload = $this->payload($router);
        $payload['snapshot_id'] = 'route-source-available-zero';

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('sources.routes.succeeded', true)
            ->assertJsonPath('sources.routes.complete', true)
            ->assertJsonPath('sources.routes.expectedCount', 0)
            ->assertJsonPath('sources.routes.receivedCount', 0);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('gatewayRoutes', [])
            ->assertJsonPath('routeCollectionSucceeded', true)
            ->assertJsonPath('routeCollectionComplete', true)
            ->assertJsonPath('gatewayObservedAt', $payload['observed_at']);

        $payload['snapshot_id'] = 'route-source-known-route';
        $payload['observed_at'] = now()->addSecond()->format(DATE_ATOM);
        $payload['ip_addresses'] = [
            [
                'address' => '192.168.88.1/24',
                'network' => '192.168.88.0',
                'interface' => 'bridge',
                'disabled' => false,
                'dynamic' => false,
            ],
            [
                'address' => '192.168.40.100/24',
                'network' => '192.168.40.0',
                'interface' => 'ether1',
                'disabled' => false,
                'dynamic' => false,
            ],
        ];
        $payload['ip_addresses_collection_succeeded'] = true;
        $payload['ip_addresses_complete'] = true;
        $payload['ip_address_count'] = 2;
        $payload['ip_addresses_collected_count'] = 2;
        $payload['routes'] = [[
            'id' => '*default',
            'dst_address' => '0.0.0.0/0',
            'gateway' => '192.168.40.1',
            'immediate_gateway' => '192.168.40.1%ether1',
            'distance' => 1,
            'active' => true,
            'dynamic' => false,
            'static' => true,
            'disabled' => false,
            'routing_table' => 'main',
        ]];
        $payload['route_count'] = 1;
        $payload['routes_collected_count'] = 1;
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)->assertOk();

        $failed = $payload;
        $failed['snapshot_id'] = 'route-source-failed';
        $failed['observed_at'] = now()->addSeconds(2)->format(DATE_ATOM);
        $failed['routes'] = [];
        $failed['routes_collection_succeeded'] = false;
        $failed['routes_complete'] = false;
        $failed['route_count'] = 0;
        $failed['routes_collected_count'] = 0;
        $failed['ip_addresses'] = [];
        $failed['ip_addresses_collection_succeeded'] = false;
        $failed['ip_addresses_complete'] = false;
        $failed['ip_address_count'] = 0;
        $failed['ip_addresses_collected_count'] = 0;
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $failed, $headers)
            ->assertOk()
            ->assertJsonPath('sources.routes.succeeded', false)
            ->assertJsonPath('sources.routes.complete', false);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('routeCollectionSucceeded', false)
            ->assertJsonPath('routeCollectionComplete', false)
            ->assertJsonPath('gatewayRoutes.0.gateway', '192.168.40.1')
            ->assertJsonPath('gatewayObservedAt', $payload['observed_at'])
            ->assertJsonPath('ipAddressCollectionSucceeded', false)
            ->assertJsonPath('ipAddresses', ['192.168.88.1/24', '192.168.40.100/24'])
            ->assertJsonPath('ipAddressObservedAt', $payload['observed_at']);
    }

    public function test_source_freshness_expires_after_the_observation_window(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['ip_addresses'] = [[
            'address' => '192.168.88.1/24',
            'network' => '192.168.88.0',
            'interface' => 'bridge',
            'disabled' => false,
            'dynamic' => false,
        ]];
        $payload['ip_address_count'] = 1;
        $payload['ip_addresses_collected_count'] = 1;
        $payload['routes'] = [[
            'id' => '*default',
            'dst_address' => '0.0.0.0/0',
            'gateway' => '192.168.40.1',
            'immediate_gateway' => '192.168.40.1%ether1',
            'distance' => 1,
            'active' => true,
            'dynamic' => false,
            'static' => true,
            'disabled' => false,
            'routing_table' => 'main',
        ]];
        $payload['route_count'] = 1;
        $payload['routes_collected_count'] = 1;
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)
            ->assertOk();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('interfaceFresh', true)
            ->assertJsonPath('wirelessFresh', true)
            ->assertJsonPath('routeFresh', true)
            ->assertJsonPath('ipAddressFresh', true);
        $this->getJson("/api/routers/{$router->id}/dhcp-leases")
            ->assertOk()
            ->assertJsonPath('stale', false);

        Carbon::setTestNow(now()->addMinutes(4));
        try {
            $this->actingAs(User::factory()->create(), 'sanctum')
                ->getJson("/api/routers/{$router->id}/status")
                ->assertOk()
                ->assertJsonPath('interfaceFresh', false)
                ->assertJsonPath('wirelessFresh', false)
                ->assertJsonPath('routeFresh', false)
                ->assertJsonPath('ipAddressFresh', false);
            $this->getJson("/api/routers/{$router->id}/dhcp-leases")
                ->assertOk()
                ->assertJsonPath('stale', true);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mac_format_variants_join_leases_and_wireless_and_zero_signal_stays_null(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['snapshot_id'] = 'mac-format-normalization';
        $payload['dhcp_leases'][0]['mac_address'] = 'aa-bb-cc-dd-ee-01';
        $payload['wireless_clients'][0]['mac_address'] = 'AA:BB:CC:DD:EE:01';
        $payload['wireless_clients'][0]['signal_strength'] = 0;
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('inventory.inserted', 1)
            ->assertJsonPath('inventory.leaseMetadataMerged', 1);

        $client = NetworkClient::query()->sole();
        $this->assertSame('AA:BB:CC:DD:EE:01', $client->mac_address);
        $this->assertSame('192.168.88.10', $client->ip_address);
        $this->assertNull($client->signal);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/dhcp-leases")
            ->assertOk()
            ->assertJsonPath('leases.0.macAddress', 'AA:BB:CC:DD:EE:01');

        $next = $payload;
        $next['snapshot_id'] = 'mac-format-normalization-next';
        $next['observed_at'] = now()->addSecond()->format(DATE_ATOM);
        $next['dhcp_leases'][0]['mac_address'] = 'AABB.CCDD.EE01';
        $next['wireless_clients'][0]['mac_address'] = 'aa-bb-cc-dd-ee-01';
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $next, $headers)
            ->assertOk()
            ->assertJsonPath('inventory.inserted', 0)
            ->assertJsonPath('inventory.updated', 1);

        $this->assertSame(1, NetworkClient::query()->where('router_id', $router->id)->count());
    }

    public function test_missing_lease_and_signal_data_preserve_identity_without_fabricating_signal(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $payload = $this->payload($router);
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)->assertOk();

        $nextPayload = $payload;
        $nextPayload['snapshot_id'] = 'rb951-client-without-lease';
        $nextPayload['observed_at'] = now()->addSecond()->format(DATE_ATOM);
        $nextPayload['dhcp_leases'] = [];
        unset($nextPayload['wireless_clients'][0]['signal_strength']);
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $nextPayload, $headers)->assertOk();

        $client = NetworkClient::query()->where('router_id', $router->id)->sole();
        $this->assertSame('Test device', $client->hostname);
        $this->assertSame('192.168.88.10', $client->ip_address);
        $this->assertNull($client->signal);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/clients")
            ->assertOk()
            ->assertJsonPath('0.signal', null);
    }

    public function test_incomplete_wireless_collection_does_not_mark_unreported_clients_offline(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $payload = $this->payload($router);
        $payload['wireless_clients'] = [
            $payload['wireless_clients'][0],
            [
                'id' => '*2',
                'interface' => 'wlan1',
                'mac_address' => 'AA:BB:CC:DD:EE:02',
                'signal_strength' => -60,
                'bytes' => '300,400',
            ],
            [
                'id' => '*3',
                'interface' => 'wlan1',
                'mac_address' => 'AA:BB:CC:DD:EE:03',
                'signal_strength' => -65,
                'bytes' => '500,600',
            ],
        ];
        $payload['wireless_clients_complete'] = true;
        $payload['wireless_registration_count'] = 3;
        $payload['wireless_collected_count'] = 3;
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)->assertOk();
        $this->assertSame(3, DB::table('network_clients')->where('router_id', $router->id)->where('status', 'online')->count());

        DB::table('network_clients')
            ->where('router_id', $router->id)
            ->where('mac_address', 'AA:BB:CC:DD:EE:01')
            ->update(['status' => 'offline']);
        DB::table('network_clients')
            ->where('router_id', $router->id)
            ->where('mac_address', 'AA:BB:CC:DD:EE:03')
            ->update(['status' => 'offline']);
        $partial = $this->payload($router);
        $partial['observed_at'] = now()->addSecond()->format(DATE_ATOM);
        $partial['wireless_clients_complete'] = false;
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $partial, $headers)->assertOk();

        $this->assertSame('online', DB::table('network_clients')->where('router_id', $router->id)->where('mac_address', 'AA:BB:CC:DD:EE:01')->value('status'));
        $this->assertSame('online', DB::table('network_clients')->where('router_id', $router->id)->where('mac_address', 'AA:BB:CC:DD:EE:02')->value('status'));
        $this->assertSame('offline', DB::table('network_clients')->where('router_id', $router->id)->where('mac_address', 'AA:BB:CC:DD:EE:03')->value('status'));
        $router->refresh();
        $this->assertFalse($router->latest_telemetry['wirelessClientsComplete']);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonFragment([
                'macAddress' => 'AA:BB:CC:DD:EE:01',
                'status' => 'online',
                'monitoringStale' => false,
            ])
            ->assertJsonFragment([
                'macAddress' => 'AA:BB:CC:DD:EE:02',
                'status' => 'unknown',
                'lastConfirmedStatus' => 'online',
                'monitoringStale' => true,
            ]);

        $complete = $payload;
        $complete['snapshot_id'] = 'snapshot-complete-'.Str::random(12);
        $complete['observed_at'] = now()->addSeconds(2)->format(DATE_ATOM);
        $complete['wireless_registration_count'] = 3;
        $complete['wireless_collected_count'] = 3;
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $complete, $headers)->assertOk();
        $this->assertSame(3, DB::table('network_clients')->where('router_id', $router->id)->where('status', 'online')->count());
    }

    public function test_complete_but_old_wireless_observation_does_not_mark_absent_clients_offline(): void
    {
        $router = $this->createTelemetryRouter();
        $firstSeen = now()->subHour();
        foreach (['AA:BB:CC:DD:EE:01', 'AA:BB:CC:DD:EE:02'] as $mac) {
            NetworkClient::query()->create([
                'id' => (string) Str::uuid(),
                'router_id' => $router->id,
                'mac_address' => $mac,
                'hostname' => 'saved-client',
                'connection_type' => 'wireless',
                'status' => 'online',
                'last_seen_at' => $firstSeen,
                'first_seen_at' => $firstSeen,
            ]);
        }
        $payload = $this->payload($router, now()->subSeconds(240)->format(DATE_ATOM));
        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/internal/mikrotik/telemetry', $payload)
            ->assertOk();

        $router->refresh();
        $this->assertTrue($router->latest_telemetry['wirelessClientsComplete']);
        $this->assertFalse($router->latest_telemetry['wirelessInventoryFresh']);
        $this->assertSame(
            'online',
            NetworkClient::query()->where('router_id', $router->id)->where('mac_address', 'AA:BB:CC:DD:EE:02')->value('status'),
        );
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonFragment([
                'macAddress' => 'AA:BB:CC:DD:EE:02',
                'status' => 'unknown',
                'lastConfirmedStatus' => 'online',
                'monitoringStale' => true,
            ]);
    }

    public function test_single_record_collections_are_normalized_to_lists(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $payload['system']['uptime'] = '2d03:04:05';
        $payload['interfaces'] = $payload['interfaces'][0];
        $payload['dhcp_leases'] = $payload['dhcp_leases'][0];
        $payload['wireless_clients'] = $payload['wireless_clients'][0];

        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, [
            'Authorization' => 'Bearer '.self::TOKEN,
        ])->assertOk()
            ->assertJsonPath('accepted', true);
        $this->assertNotNull(
            DB::table('routers')->where('id', $router->id)->value('telemetry_observed_at'),
        );
        $this->assertNotNull(
            DB::table('routers')->where('id', $router->id)->value('telemetry_received_at'),
        );
        $this->assertSame(1, RouterMetric::query()->where('router_id', $router->id)->count());
        $this->assertNotNull(RouterMetric::query()->where('router_id', $router->id)->value('recorded_at'));

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('dataSource', 'telemetry')
            ->assertJsonPath('systemIdentity', 'TEST-RB951-TELEMETRY');
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/dhcp-leases")
            ->assertOk()
            ->assertJsonCount(1, 'leases')
            ->assertJsonPath('leases.0.address', '192.168.88.10');
    }

    public function test_stored_snapshot_becomes_stale_and_only_super_admin_can_issue_a_credential(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $payload = $this->payload($router);
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $payload, $headers)->assertOk();

        $router->refresh();
        $router->forceFill(['telemetry_received_at' => now()->subMinutes(4)])->save();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('dataSource', 'stale')
            ->assertJsonPath('telemetryStatus', 'stale');

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->postJson("/api/mikrotik-configs/{$router->id}/telemetry-credential")
            ->assertForbidden();

        config([
            'app.url' => 'https://console.example',
            'mikrotik.telemetry_endpoint' => 'https://console.example/api/v1/internal/mikrotik/telemetry',
        ]);
        $credentialResponse = $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'sanctum')
            ->postJson("/api/mikrotik-configs/{$router->id}/telemetry-credential")
            ->assertOk()
            ->assertJsonPath('endpoint', 'https://console.example/api/v1/internal/mikrotik/telemetry')
            ->assertJsonMissingPath('api_password');
        $rotatedToken = $credentialResponse->json('token');
        $this->assertIsString($rotatedToken);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rotatedToken);

        $router->refresh();
        $this->assertSame(hash('sha256', $rotatedToken), $router->telemetry_token_hash);
        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/internal/mikrotik/telemetry', $payload)
            ->assertUnauthorized();
        $this->withToken($rotatedToken)
            ->postJson('/api/v1/internal/mikrotik/telemetry', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);
    }

    public function test_stale_and_never_reported_telemetry_are_not_claimed_offline_or_unreachable(): void
    {
        config()->set('mikrotik.telemetry_stale_seconds', 15);
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/internal/mikrotik/telemetry', $payload)
            ->assertOk();
        $router->refresh();
        $router->forceFill(['telemetry_received_at' => now()->subSeconds(16)])->save();

        $neverReported = $this->createTelemetryRouter(hash('sha256', Str::random(64)));
        $neverReported->update(['name' => 'Waiting for first report']);

        $provider = new MikroTikRouterProvider(
            app(RouterReadTransport::class),
            app(RouterTelemetryService::class),
        );
        $routersById = collect($provider->getRouters())->keyBy('id');
        $this->assertSame('unknown', $routersById[$router->id]['status']);
        $this->assertSame('stale', $routersById[$router->id]['dataSource']);
        $this->assertSame('unknown', $routersById[$neverReported->id]['status']);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'sanctum')
            ->getJson('/api/mikrotik-configs')
            ->assertOk()
            ->assertJsonFragment(['id' => $router->id, 'healthStatus' => 'stale'])
            ->assertJsonFragment(['id' => $neverReported->id, 'healthStatus' => 'unknown']);
        $this->postJson("/api/mikrotik-configs/{$router->id}/connect")
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('state', 'waiting')
            ->assertJsonPath('message', 'No recent telemetry report was received; reachability is unconfirmed.');

        $incidents = collect($provider->getIncidents())->keyBy('routerId');
        $this->assertSame('warning', $incidents[$router->id]['severity']);
        $this->assertStringContainsString('reachability is unconfirmed', $incidents[$router->id]['message']);
        $this->assertStringNotContainsString('unreachable', $incidents[$router->id]['message']);
        $this->assertSame('info', $incidents[$neverReported->id]['severity']);
        $this->assertSame('waiting', $provider->connect($router->id)['state']);
        $this->assertSame('waiting', $provider->connect($neverReported->id)['state']);

        $summary = $provider->getDashboardSummary();
        $this->assertSame(1, $summary['stale']);
        $this->assertSame(1, $summary['unknown']);
        $this->assertSame(0, $summary['offline']);
        $this->assertSame(0, $summary['totalClients']);
    }

    public function test_database_failure_rolls_back_snapshot_client_and_metric_updates(): void
    {
        $router = $this->createTelemetryRouter();
        $payload = $this->payload($router);
        DB::unprepared(
            'CREATE TRIGGER fail_router_metric_insert BEFORE INSERT ON router_metrics '
            ."BEGIN SELECT RAISE(ABORT, 'simulated metric persistence failure'); END",
        );

        try {
            app(RouterTelemetryService::class)->receive($router, $payload);
            $this->fail('Expected metric persistence to fail.');
        } catch (QueryException) {
            $router->refresh();
            $this->assertNull($router->telemetry_received_at);
            $this->assertSame(0, NetworkClient::query()->where('router_id', $router->id)->count());
            $this->assertSame(0, RouterMetric::query()->where('router_id', $router->id)->count());
        }
    }

    public function test_stale_invalid_identity_and_replayed_reports_are_rejected(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $stale = $this->payload($router);
        $stale['observed_at'] = now()->subMinutes(10)->format(DATE_ATOM);
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $stale, $headers)
            ->assertUnprocessable()
            ->assertJsonPath('category', 'stale_telemetry')
            ->assertHeader('X-Telemetry-Error', 'stale_telemetry');

        $wrongIdentity = $this->payload($router);
        $wrongIdentity['system']['identity'] = 'another-router';
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $wrongIdentity, $headers)
            ->assertUnprocessable()
            ->assertJsonPath('category', 'router_identity_mismatch');

        $accepted = $this->payload($router);
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $accepted, $headers)->assertOk();
        $replay = $this->payload($router, now()->subSeconds(30)->format(DATE_ATOM));
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $replay, $headers)
            ->assertStatus(409)
            ->assertJsonPath('category', 'replayed_telemetry');
    }

    public function test_invalid_metrics_are_rejected_and_counter_resets_never_create_negative_rates(): void
    {
        $router = $this->createTelemetryRouter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $invalid = $this->payload($router);
        $invalid['system']['cpu_load'] = 101;
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $invalid, $headers)
            ->assertUnprocessable();

        $first = $this->payload($router);
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $first, $headers)->assertOk();
        $router->refresh();
        $this->assertNull(app(RouterTelemetryService::class)->traffic($router)['currentRxMbps']);
        $this->assertNull(app(RouterTelemetryService::class)->traffic($router)['currentTxMbps']);

        $second = $this->payload($router, now()->addSeconds(60)->format(DATE_ATOM));
        $second['snapshot_id'] = 'snapshot-second';
        $second['interfaces'][0]['rx_bytes'] = 1;
        $second['interfaces'][0]['tx_bytes'] = 1;
        $this->postJson('/api/v1/internal/mikrotik/telemetry', $second, $headers)->assertOk();

        $router->refresh();
        $this->assertEquals(0, app(RouterTelemetryService::class)->traffic($router)['currentRxMbps']);
        $this->assertEquals(0, app(RouterTelemetryService::class)->traffic($router)['currentTxMbps']);
        $this->assertEquals(0, $router->latest_telemetry['rates']['rxMbps']);
        $this->assertEquals(0, $router->latest_telemetry['rates']['txMbps']);
        $this->assertSame(2, RouterMetric::query()->where('router_id', $router->id)->count());
    }

    public function test_server_scheduled_collection_does_not_poll_telemetry_routers(): void
    {
        $router = $this->createTelemetryRouter();
        $this->artisan('routers:collect')->assertSuccessful();

        $router->refresh();
        $this->assertNull($router->last_attempted_at);
        $this->assertSame(0, RouterMetric::query()->where('router_id', $router->id)->count());
    }

    private function createTelemetryRouter(?string $tokenHash = null): Router
    {
        return Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'TEST-RB951-TELEMETRY',
            'identity' => 'TEST-RB951-TELEMETRY',
            'ip_address' => '192.0.2.10',
            'api_transport' => 'telemetry',
            'api_username' => 'telemetry-only',
            'api_password' => 'non-router-test-placeholder',
            'monitoring_enabled' => true,
            'telemetry_token_hash' => $tokenHash ?? hash('sha256', self::TOKEN),
        ]);
    }

    private function payload(Router $router, ?string $observedAt = null): array
    {
        return [
            'schema_version' => 1,
            'router_id' => $router->id,
            'snapshot_id' => 'snapshot-'.Str::random(20),
            'observed_at' => $observedAt ?? now()->format(DATE_ATOM),
            'system' => [
                'identity' => 'TEST-RB951-TELEMETRY',
                'version' => '7.23.1 (stable)',
                'board_name' => 'RB951-2n',
                'architecture' => 'mipsbe',
                'cpu_load' => 10,
                'uptime' => '3h35m9s',
                'total_memory' => 67108864,
                'free_memory' => 23697817,
                'total_hdd_space' => 134217728,
                'free_hdd_space' => 113665843,
            ],
            'interfaces' => [[
                'id' => '*1',
                'name' => 'ether1',
                'type' => 'ether',
                'running' => true,
                'disabled' => false,
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'rx_bytes' => 1000,
                'tx_bytes' => 500,
                'ip_address' => '192.168.40.100',
            ]],
            'interfaces_collection_succeeded' => true,
            'interfaces_complete' => true,
            'interface_count' => 1,
            'interfaces_collected_count' => 1,
            'router_mac_address' => '00:00:00:00:00:00',
            'ip_addresses' => [],
            'ip_addresses_collection_succeeded' => true,
            'ip_addresses_complete' => true,
            'ip_address_count' => 0,
            'ip_addresses_collected_count' => 0,
            'routes' => [],
            'routes_collection_succeeded' => true,
            'routes_complete' => true,
            'route_count' => 0,
            'routes_collected_count' => 0,
            'dhcp_leases' => [[
                'mac_address' => 'AA:BB:CC:DD:EE:01',
                'host_name' => 'Test device',
                'address' => '192.168.88.10',
                'interface' => 'bridge',
                'status' => 'bound',
                'dynamic' => true,
                'expires_after' => '1h',
            ]],
            'dhcp_lease_dynamic_supported' => true,
            'dhcp_leases_collection_succeeded' => true,
            'dhcp_leases_complete' => true,
            'dhcp_lease_count' => 1,
            'dhcp_collected_count' => 1,
            'wireless_clients_complete' => true,
            'wireless_clients_collection_succeeded' => true,
            'wireless_registration_count' => 1,
            'wireless_collected_count' => 1,
            'wireless_clients' => [[
                'id' => '*1',
                'interface' => 'wlan1',
                'mac_address' => 'AA:BB:CC:DD:EE:01',
                'signal_strength' => -55,
                'bytes' => '100,200',
            ]],
        ];
    }
}

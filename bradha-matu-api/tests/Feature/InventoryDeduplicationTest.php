<?php

namespace Tests\Feature;

use App\Models\NetworkClient;
use App\Models\Router;
use App\Models\User;
use App\Services\ClientInventory;
use App\Support\MacAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private function createRouter(string $name = 'Test Router'): Router
    {
        return Router::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'identity' => $name,
            'ip_address' => '192.168.88.1',
            'api_username' => 'monitor',
            'api_password' => 'test-password',
            'api_port' => 8728,
            'api_use_tls' => false,
        ]);
    }

    private function arpNeighbor(string $mac, ?string $ip, string $interface = 'bridge'): array
    {
        return [
            'macAddress' => $mac,
            'hostname' => '',
            'ipAddress' => (string) $ip,
            'interfaceId' => $interface,
            'interfaceName' => $interface,
            'connectionType' => 'arp',
            'signal' => null,
            'rxBytes' => 0,
            'txBytes' => 0,
            'connectedAt' => null,
            'lastSeenAt' => null,
        ];
    }

    private function recordArp(Router $router, array $neighbors, \DateTimeInterface $observedAt): array
    {
        return app(ClientInventory::class)->recordTelemetrySnapshot(
            router: $router,
            snapshot: [],
            complete: false,
            sourceConfirmed: false,
            observedAt: $observedAt,
            arpSnapshot: $neighbors,
            arpSourceConfirmed: true,
        );
    }

    public function test_arp_observation_updates_ip_on_the_existing_device_record(): void
    {
        $router = $this->createRouter();
        $mac = 'AA:BB:CC:DD:EE:FF';
        $inventory = app(ClientInventory::class);

        $inventory->recordSnapshot($router, [[
            'macAddress' => $mac,
            'hostname' => 'laptop-a',
            'ipAddress' => '192.168.88.210',
            'interfaceName' => 'wlan1',
            'connectionType' => 'wireless',
        ]], true, now());

        $beforeId = NetworkClient::query()->sole()->id;

        $this->recordArp($router, [$this->arpNeighbor($mac, '192.168.88.50')], now()->addSeconds(5));

        $client = NetworkClient::query()->sole();
        $this->assertSame($beforeId, $client->id, 'the ARP report must update the existing record, not create another');
        $this->assertSame('192.168.88.50', $client->ip_address);
        $this->assertSame('laptop-a', $client->hostname, 'a new IP must not reset the observed device name');
        $this->assertSame('wireless', $client->connection_type, 'ARP is an observation, it must not reclassify the authoritative connection');
    }

    public function test_api_exposes_the_latest_valid_arp_observed_ip(): void
    {
        $router = $this->createRouter();
        $mac = 'AA:BB:CC:DD:EE:FF';
        $this->recordArp($router, [$this->arpNeighbor($mac, '192.168.88.50')], now());

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonFragment([
                'macAddress' => $mac,
                'ipAddress' => '192.168.88.50',
                'routerObservedIpAddress' => '192.168.88.50',
                'connectionType' => 'arp',
            ]);
    }

    public function test_saved_display_name_and_observed_hostname_survive_an_arp_ip_change(): void
    {
        $router = $this->createRouter();
        $mac = 'AA:BB:CC:DD:EE:FF';
        $inventory = app(ClientInventory::class);
        $inventory->recordSnapshot($router, [[
            'macAddress' => $mac,
            'hostname' => 'printer-4f',
            'ipAddress' => '192.168.88.210',
            'connectionType' => 'wireless',
        ]], true, now());
        $client = NetworkClient::query()->sole();
        $client->forceFill(['alias' => 'Front Desk Printer'])->save();

        $this->recordArp($router, [$this->arpNeighbor($mac, '192.168.88.50')], now()->addSeconds(5));

        $client->refresh();
        $this->assertSame('192.168.88.50', $client->ip_address);
        $this->assertSame('printer-4f', $client->hostname);
        $this->assertSame('Front Desk Printer', $client->alias);

        $presented = $inventory->present($client->fresh('router'));
        $this->assertSame('Front Desk Printer', $presented['displayName']);
    }

    public function test_stale_arp_observation_cannot_overwrite_a_newer_valid_address(): void
    {
        $router = $this->createRouter();
        $mac = 'AA:BB:CC:DD:EE:FF';
        $this->recordArp($router, [$this->arpNeighbor($mac, '192.168.88.70')], now()->addSeconds(30));

        $this->recordArp($router, [$this->arpNeighbor($mac, '192.168.88.20')], now());

        $client = NetworkClient::query()->sole();
        $this->assertSame('192.168.88.70', $client->ip_address, 'an older observation must not replace the newer ARP address');
    }

    public function test_missing_arp_observation_never_fabricates_an_address(): void
    {
        $router = $this->createRouter();
        $inventory = app(ClientInventory::class);
        $inventory->recordSnapshot($router, [[
            'macAddress' => 'AA:BB:CC:DD:EE:09',
            'hostname' => 'no-lease-no-arp',
            'ipAddress' => null,
            'connectionType' => 'wireless',
        ]], true, now());

        $client = NetworkClient::query()->sole();
        $this->assertNull($client->ip_address);

        $presented = $inventory->present($client->fresh('router'));
        $this->assertNull($presented['routerObservedIpAddress']);
        $this->assertSame('', $presented['ipAddress'], 'a missing observation must not invent an address');
    }

    public function test_bridge_port_artifact_mac_is_never_persisted_as_a_device(): void
    {
        $router = $this->createRouter();
        $artifact = '00:00:01:00:00:00';
        $arpGenuine = 'AA:BB:CC:DD:EE:11';
        $wiredGenuine = 'AA:BB:CC:DD:EE:22';

        $this->recordArp($router, [
            $this->arpNeighbor($artifact, '192.168.88.1'),
            $this->arpNeighbor($arpGenuine, '192.168.88.123'),
        ], now());

        $inventory = app(ClientInventory::class);
        $inventory->recordTelemetrySnapshot(
            router: $router,
            snapshot: [],
            complete: false,
            sourceConfirmed: false,
            observedAt: now()->addSeconds(2),
            wiredSnapshot: [[
                'macAddress' => $artifact,
                'hostname' => '',
                'ipAddress' => '',
                'interfaceId' => 'ether4',
                'interfaceName' => 'ether4',
                'connectionType' => 'wired',
                'signal' => null,
                'rxBytes' => 0,
                'txBytes' => 0,
            ], [
                'macAddress' => $wiredGenuine,
                'hostname' => 'real-switch-uplink',
                'ipAddress' => '192.168.88.124',
                'interfaceId' => 'ether5',
                'interfaceName' => 'ether5',
                'connectionType' => 'wired',
                'signal' => null,
                'rxBytes' => 0,
                'txBytes' => 0,
            ]],
            wiredComplete: true,
            wiredSourceConfirmed: true,
        );

        $this->assertFalse(
            NetworkClient::query()->where('router_id', $router->id)->where('mac_address', $artifact)->exists(),
            'a MikroTik bridge switch port pseudo-MAC must not create an inventory row',
        );
        $this->assertSame(2, NetworkClient::query()->where('router_id', $router->id)->count());
        $this->assertTrue(
            NetworkClient::query()->where('mac_address', $wiredGenuine)->exists(),
            'a genuine wired client in the same report must still be persisted',
        );
    }

    public function test_locally_administered_mac_is_valid_and_not_shared_across_routers(): void
    {
        $routerA = $this->createRouter('Router A');
        $routerB = $this->createRouter('Router B');
        $randomized = '2A:05:C5:6F:CE:69';

        $this->recordArp($routerA, [$this->arpNeighbor($randomized, '192.168.88.247')], now());
        $this->recordArp($routerB, [$this->arpNeighbor($randomized, '192.168.10.247')], now());

        $rowA = NetworkClient::query()->where('router_id', $routerA->id)->sole();
        $rowB = NetworkClient::query()->where('router_id', $routerB->id)->sole();

        $this->assertSame($randomized, $rowA->mac_address, 'a valid locally administered MAC must remain a device identity');
        $this->assertNotSame($rowA->id, $rowB->id, 'the same MAC on different routers must not merge across network scopes');
        $this->assertNotSame($rowA->ip_address, $rowB->ip_address);
    }

    public function test_devices_with_different_macs_are_not_merged_by_shared_hostname_or_ip(): void
    {
        $router = $this->createRouter();
        $inventory = app(ClientInventory::class);

        foreach ([['AA:BB:CC:DD:EE:A1', '192.168.88.5'], ['AA:BB:CC:DD:EE:A2', '192.168.88.5']] as $row) {
            $inventory->recordSnapshot($router, [[
                'macAddress' => $row[0],
                'hostname' => 'desktp-lcs6kgv',
                'ipAddress' => $row[1],
                'connectionType' => 'wireless',
            ]], true, now());
        }

        $this->assertSame(2, NetworkClient::query()->where('router_id', $router->id)->count(), 'distinct valid MACs must remain distinct devices');
    }

    public function test_repeated_identical_reports_do_not_recreate_duplicates(): void
    {
        $router = $this->createRouter();
        $mac = 'AA:BB:CC:DD:EE:FF';
        for ($i = 0; $i < 4; $i++) {
            $this->recordArp($router, [$this->arpNeighbor($mac, '192.168.88.99')], now()->addSeconds($i));
        }
        $this->assertSame(1, NetworkClient::query()->where('router_id', $router->id)->count());

        $inventory = app(ClientInventory::class);
        $inventory->recordSnapshot($router, [[
            'macAddress' => $mac,
            'hostname' => 'phone',
            'ipAddress' => '192.168.88.99',
            'connectionType' => 'wireless',
        ]], true, now()->addSeconds(10));
        $this->assertSame(1, NetworkClient::query()->where('router_id', $router->id)->count());
    }

    public function test_quarantine_migration_backs_up_and_removes_artifacts_while_keeping_genuine_clients(): void
    {
        $router = $this->createRouter();
        $genuine = NetworkClient::query()->create([
            'id' => (string) Str::uuid(),
            'router_id' => $router->id,
            'mac_address' => '84:7B:EB:17:37:7B',
            'connection_type' => 'wired',
            'hostname' => 'DESKTOP-LCS6KGV',
            'ip_address' => '192.168.88.242',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
            'status' => 'online',
        ]);

        $artifactIds = [];
        foreach (['00:00:01:00:00:00', '00:00:02:00:00:00', '00:00:0A:00:00:00'] as $mac) {
            $artifactIds[] = NetworkClient::query()->create([
                'id' => (string) Str::uuid(),
                'router_id' => $router->id,
                'mac_address' => $mac,
                'connection_type' => 'wired',
                'first_seen_at' => now()->subHour(),
                'last_seen_at' => now(),
                'status' => 'offline',
            ])->id;
        }

        $migration = require database_path('migrations/2026_10_25_000001_quarantine_bridge_port_inventory_artifacts.php');
        $migration->up();

        $this->assertSame(
            0,
            NetworkClient::query()->where('router_id', $router->id)->where('mac_address', 'like', '00:00:%')->count(),
        );
        $this->assertTrue(NetworkClient::query()->whereKey($genuine->id)->exists(), 'genuine clients must survive the repair');
        $this->assertTrue(Schema::hasTable('network_clients_artifact_backup'));
        $this->assertCount(3, DB::table('network_clients_artifact_backup')->get());
        foreach ($artifactIds as $id) {
            $this->assertTrue(
                DB::table('network_clients_artifact_backup')->where('id', $id)->exists(),
                'each removed artifact must be recoverable from the backup table',
            );
        }
    }

    public function test_mac_helper_flags_only_bridge_port_artifacts(): void
    {
        foreach (['00:00:01:00:00:00', '00:00:0A:00:00:00', '00:00:36:00:00:00', '00-00-2D-00-00-00', '0000.1C.0000.00'] as $artifact) {
            $this->assertTrue(MacAddress::isBridgePortArtifact($artifact), "{$artifact} is a bridge port artifact");
        }

        foreach (['AA:BB:CC:DD:EE:FF', '2A:05:C5:6F:CE:69', '02:00:00:00:01:01', '00:00:00:00:00:00', '00:01:00:00:00:00', '10:02:B5:F0:CF:67'] as $legit) {
            $this->assertFalse(MacAddress::isBridgePortArtifact($legit), "{$legit} is not a bridge port artifact");
        }

        $this->assertFalse(MacAddress::isBridgePortArtifact('not-a-mac'));
    }
}

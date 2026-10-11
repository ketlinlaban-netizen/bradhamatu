<?php

namespace Tests\Feature;

use App\Models\NetworkClient;
use App\Models\Router;
use App\Models\User;
use App\Services\ClientInventory;
use App\Services\RouterApiException;
use App\Services\RouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function createRouter(): Router
    {
        return Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Router',
            'ip_address' => '192.168.88.1',
            'api_username' => 'monitor',
            'api_password' => 'test-password',
            'api_port' => 8728,
            'api_use_tls' => false,
        ]);
    }

    public function test_successful_snapshots_persist_clients_and_mark_missing_clients_offline(): void
    {
        $router = $this->createRouter();
        $inventory = app(ClientInventory::class);
        $snapshot = [[
            'macAddress' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'phone',
            'ipAddress' => '192.168.88.50',
            'interfaceId' => 'bridge',
            'interfaceName' => 'bridge-lan',
            'connectionType' => 'wireless',
            'signal' => -55,
            'rxBytes' => 1234,
            'txBytes' => 5678,
        ]];

        $inventory->recordSnapshot($router, $snapshot);
        $client = NetworkClient::query()->sole();
        $this->assertSame('online', $client->status);
        $firstSeenAt = $client->first_seen_at->toIso8601String();

        $offlineClients = $inventory->recordSnapshot($router, []);
        $client->refresh();

        $this->assertCount(1, $offlineClients);
        $this->assertSame('offline', $client->status);
        $this->assertNotNull($client->offline_reason);
        $this->assertSame($firstSeenAt, $client->first_seen_at->toIso8601String());
    }

    public function test_client_coming_back_online_clears_offline_reason_without_creating_duplicate(): void
    {
        $router = $this->createRouter();
        $inventory = app(ClientInventory::class);
        $client = [
            'macAddress' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'phone',
            'ipAddress' => '192.168.88.50',
            'connectionType' => 'wireless',
        ];

        $inventory->recordSnapshot($router, [$client]);
        $inventory->recordSnapshot($router, []);
        $inventory->recordSnapshot($router, [$client]);

        $this->assertSame(1, NetworkClient::query()->count());
        $this->assertSame('online', NetworkClient::query()->sole()->status);
        $this->assertNull(NetworkClient::query()->sole()->offline_reason);
    }

    public function test_dhcp_lease_without_an_active_wireless_registration_is_not_online_presence(): void
    {
        $router = $this->createRouter();

        $clients = app(ClientInventory::class)->recordSnapshot($router, [[
            'macAddress' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'phone',
            'connectionType' => 'dhcp',
        ]]);

        $this->assertCount(0, $clients);
        $this->assertSame(0, NetworkClient::query()->count());
    }

    public function test_stale_dhcp_lease_does_not_keep_a_previously_registered_wireless_client_online(): void
    {
        $router = $this->createRouter();
        $inventory = app(ClientInventory::class);
        $wirelessClient = [
            'macAddress' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'phone',
            'connectionType' => 'wireless',
        ];

        $inventory->recordSnapshot($router, [$wirelessClient]);
        $inventory->recordSnapshot($router, [[
            'macAddress' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'phone',
            'connectionType' => 'dhcp',
        ]]);

        $this->assertSame('offline', NetworkClient::query()->sole()->status);
    }

    public function test_unreachable_router_marks_online_client_unknown_instead_of_offline(): void
    {
        $router = $this->createRouter();
        $client = app(ClientInventory::class)->recordSnapshot($router, [[
            'macAddress' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'phone',
            'connectionType' => 'wireless',
        ]])->sole();

        $presented = app(ClientInventory::class)->present($client, 'Router connection timed out.');

        $this->assertSame('online', $client->status);
        $this->assertSame('unknown', $presented['status']);
        $this->assertStringContainsString('Router connection timed out.', $presented['offlineReason']);
    }

    public function test_missing_status_is_not_treated_as_online_when_router_is_unconfirmed(): void
    {
        $router = $this->createRouter();
        $router->forceFill(['status_override' => 'offline', 'last_error_category' => 'connection_failed'])->save();
        $client = NetworkClient::query()->create([
            'id' => (string) Str::uuid(),
            'router_id' => $router->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'hostname' => 'stale-phone',
            'connection_type' => 'wireless',
            'status' => 'online',
            'last_seen_at' => now()->subMinutes(5),
            'first_seen_at' => now()->subMinutes(30),
        ]);

        $presented = app(ClientInventory::class)->present($client);

        $this->assertSame('unknown', $presented['status']);
        $this->assertStringContainsString('status is unconfirmed', $presented['offlineReason']);
    }

    public function test_authenticated_global_clients_endpoint_keeps_saved_clients_visible(): void
    {
        config()->set('mikrotik.provider', 'mikrotik');
        $router = $this->createRouter();
        $client = app(ClientInventory::class)->recordSnapshot($router, [[
            'macAddress' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'saved-phone',
            'connectionType' => 'wireless',
        ]])->sole();
        $client->update([
            'status' => 'offline',
            'offline_reason' => 'Disconnected from the access point.',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('0.macAddress', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonPath('0.routerName', 'Test Router')
            ->assertJsonPath('0.status', 'offline')
            ->assertJsonPath('0.offlineReason', 'Disconnected from the access point.');
    }

    public function test_admin_can_save_edit_and_clear_router_scoped_alias_without_changing_identifiers(): void
    {
        config()->set('mikrotik.provider', 'mikrotik');
        $router = $this->createRouter();
        $client = app(ClientInventory::class)->recordSnapshot($router, [[
            'macAddress' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'android-device',
            'ipAddress' => '192.168.88.50',
            'connectionType' => 'wireless',
        ]])->sole();
        $admin = User::factory()->create(['role' => 'admin']);
        Http::fake();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/routers/{$router->id}/clients/{$client->id}/alias", [
                'alias' => "  Joseph's Phone  ",
            ])
            ->assertOk()
            ->assertJsonPath('alias', "Joseph's Phone")
            ->assertJsonPath('displayName', "Joseph's Phone")
            ->assertJsonPath('hostname', 'android-device')
            ->assertJsonPath('ipAddress', '192.168.88.50')
            ->assertJsonPath('macAddress', 'AA:BB:CC:DD:EE:FF');

        $client->refresh();
        $this->assertSame("Joseph's Phone", $client->alias);
        $this->assertSame('192.168.88.50', $client->ip_address);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $client->mac_address);

        app(ClientInventory::class)->recordSnapshot($router, [[
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'hostname' => 'renamed-by-router',
            'ipAddress' => '192.168.88.51',
            'connectionType' => 'wireless',
        ]]);
        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('0.alias', "Joseph's Phone")
            ->assertJsonPath('0.displayName', "Joseph's Phone")
            ->assertJsonPath('0.hostname', 'renamed-by-router')
            ->assertJsonPath('0.ipAddress', '192.168.88.51')
            ->assertJsonPath('0.macAddress', 'AA:BB:CC:DD:EE:FF');

        $this->patchJson("/api/routers/{$router->id}/clients/{$client->id}/alias", [
            'alias' => 'Joseph phone - edited',
        ])->assertOk()->assertJsonPath('displayName', 'Joseph phone - edited');

        $this->deleteJson("/api/routers/{$router->id}/clients/{$client->id}/alias")
            ->assertOk()
            ->assertJsonPath('alias', null)
            ->assertJsonPath('displayName', 'renamed-by-router')
            ->assertJsonPath('ipAddress', '192.168.88.51')
            ->assertJsonPath('macAddress', 'AA:BB:CC:DD:EE:FF');

        $this->assertSame(1, NetworkClient::query()->count());
        $this->assertNull($client->fresh()->alias);
        Http::assertNothingSent();
    }

    public function test_alias_updates_reject_invalid_names_and_prevent_cross_router_access(): void
    {
        $router = $this->createRouter();
        $otherRouter = Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'Other Router',
            'ip_address' => '192.168.89.1',
            'api_username' => 'monitor',
            'api_password' => 'test-password',
            'api_port' => 8728,
            'api_use_tls' => false,
        ]);
        $client = app(ClientInventory::class)->recordSnapshot($otherRouter, [[
            'macAddress' => 'aa:bb:cc:dd:ee:01',
            'hostname' => 'tablet',
            'ipAddress' => '192.168.89.50',
            'connectionType' => 'wireless',
        ]])->sole();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'sanctum');

        // Test validation: empty alias
        $this->patchJson("/api/routers/{$otherRouter->id}/clients/{$client->id}/alias", [
            'alias' => '    ',
        ])->assertUnprocessable()->assertJsonValidationErrors('alias');

        // Test validation: alias too long
        $this->patchJson("/api/routers/{$otherRouter->id}/clients/{$client->id}/alias", [
            'alias' => str_repeat('x', 81),
        ])->assertUnprocessable()->assertJsonValidationErrors('alias');

        // Test: valid alias is accepted (and XSS is stored)
        $this->patchJson("/api/routers/{$otherRouter->id}/clients/{$client->id}/alias", [
            'alias' => '<img src=x onerror=alert(1)>',
        ])->assertOk()->assertJsonPath('alias', '<img src=x onerror=alert(1)>');

        $this->assertSame('<img src=x onerror=alert(1)>', $client->fresh()->alias);
        $this->assertSame('192.168.89.50', $client->fresh()->ip_address);

        // Test cross-router access prevention: cannot access client from other router
        $this->patchJson("/api/routers/{$router->id}/clients/{$client->id}/alias", [
            'alias' => 'Should Fail',
        ])->assertNotFound();
    }

    public function test_viewers_cannot_modify_device_aliases_and_unauthenticated_users_cannot_access_them(): void
    {
        $router = $this->createRouter();
        $client = app(ClientInventory::class)->recordSnapshot($router, [[
            'macAddress' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'phone',
            'connectionType' => 'wireless',
        ]])->sole();
        $path = "/api/routers/{$router->id}/clients/{$client->id}/alias";

        $this->patchJson($path, ['alias' => 'Friendly name'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'viewer']), 'sanctum')
            ->patchJson($path, ['alias' => 'Friendly name'])
            ->assertForbidden();
        $this->assertNull($client->fresh()->alias);
    }

    public function test_refresh_endpoint_reports_unknown_when_router_is_unreachable(): void
    {
        config()->set('mikrotik.provider', 'mikrotik');
        $router = $this->createRouter();
        app(ClientInventory::class)->recordSnapshot($router, [[
            'macAddress' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'saved-phone',
            'connectionType' => 'wireless',
        ]]);

        $service = \Mockery::mock(RouterService::class);
        $service->shouldReceive('getClients')
            ->once()
            ->with($router->id)
            ->andThrow(new RouterApiException('connection_timeout', 'Router connection timed out.'));
        $this->app->instance(RouterService::class, $service);

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->getJson('/api/clients/refresh')
            ->assertOk()
            ->assertJsonPath('0.status', 'unknown')
            ->assertJsonPath('0.offlineReason', 'Router status is unconfirmed (Router connection timed out.); client status could not be refreshed.');
    }
}

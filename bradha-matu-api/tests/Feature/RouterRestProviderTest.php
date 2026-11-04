<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterRestProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_rest_provider_preserves_authenticated_router_status_api_contract(): void
    {
        config()->set('mikrotik.provider', 'rest');
        config()->set('mikrotik.rest_port', 443);
        config()->set('mikrotik.rest_allowed_ports', [443]);
        config()->set('mikrotik.allowed_hosts', '');

        $router = Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Router',
            'ip_address' => '192.168.88.1',
            'api_username' => 'rest-monitor',
            'api_password' => 'test-secret',
            'api_transport' => 'rest',
            'api_port' => 8729,
            'api_use_tls' => true,
            'verify_cert' => true,
            'monitoring_enabled' => true,
        ]);

        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), '/system/resource') => Http::response([[
                    'cpu-load' => '12',
                    'total-memory' => '1000',
                    'free-memory' => '400',
                    'total-hdd-space' => '2000',
                    'free-hdd-space' => '1000',
                    'uptime' => '1h',
                    'board-name' => 'TestBoard',
                    'architecture' => 'arm',
                    'version' => '7.20.1',
                    'serial-number' => 'SERIAL-1',
                ]]),
                str_contains($request->url(), '/system/identity') => Http::response([[
                    'name' => 'Test Router Identity',
                ]]),
                str_contains($request->url(), '/interface?') => Http::response([[
                    'name' => 'ether1',
                    'mac-address' => 'AA:BB:CC:DD:EE:FF',
                    'type' => 'ether',
                ]]),
                str_contains($request->url(), '/ip/address?') => Http::response([[
                    'address' => '192.168.88.1/24',
                    'interface' => 'ether1',
                ]]),
                str_contains($request->url(), '/ip/route?') => Http::response([
                    [
                        '.id' => '*1',
                        'dst-address' => '0.0.0.0/0',
                        'gateway' => '192.168.40.1',
                        'immediate-gw' => '192.168.40.1%ether1',
                        'distance' => '1',
                        'active' => true,
                        'static' => true,
                        'routing-table' => 'main',
                    ],
                    [
                        '.id' => '*2',
                        'dst-address' => '0.0.0.0/0',
                        'gateway' => '192.168.1.1',
                        'immediate-gw' => '192.168.1.1%ether1',
                        'distance' => '1',
                        'active' => true,
                        'dynamic' => true,
                        'routing-table' => 'main',
                    ],
                    [
                        '.id' => '*3',
                        'dst-address' => '10.0.0.0/8',
                        'gateway' => '192.168.88.254',
                        'distance' => '1',
                        'active' => true,
                        'routing-table' => 'main',
                    ],
                ]),
                default => Http::response([], 404),
            };
        });

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('routerId', $router->id)
            ->assertJsonPath('cpuUsage', 12)
            ->assertJsonPath('memoryUsage', 60)
            ->assertJsonPath('storageUsage', 50)
            ->assertJsonPath('uptimeSeconds', 3600)
            ->assertJsonPath('systemIdentity', 'Test Router Identity')
            ->assertJsonPath('macAddresses.0', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonPath('ipAddresses.0', '192.168.88.1/24')
            ->assertJsonCount(2, 'gatewayRoutes')
            ->assertJsonPath('gatewayRoutes.0.gateway', '192.168.1.1')
            ->assertJsonPath('gatewayRoutes.0.interface', 'ether1')
            ->assertJsonPath('gatewayRoutes.0.active', true)
            ->assertJsonPath('gatewayRoutes.0.ecmp', true)
            ->assertJsonPath('gatewayRoutes.1.gateway', '192.168.40.1')
            ->assertJsonPath('gatewayRoutes.1.static', true)
            ->assertJsonPath('gatewayRoutes.1.ecmp', true)
            ->assertJsonPath('dataSource', 'live');

        $router->refresh();
        $this->assertSame('192.168.88.1', $router->ip_address);
        $this->assertSame('online', $router->status_override);
        $this->assertNotNull($router->last_seen_at);
        $this->assertCount(2, $router->gateway_observations);
        $this->assertNotNull($router->gateway_observed_at);
        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://192.168.88.1/')
            && ! str_contains($request->url(), 'test-secret'));
    }

    public function test_router_inventory_does_not_mark_rest_router_offline_for_an_unsupported_type_filter(): void
    {
        config()->set('mikrotik.provider', 'rest');
        config()->set('mikrotik.rest_port', 443);
        config()->set('mikrotik.rest_allowed_ports', [443]);
        config()->set('mikrotik.allowed_hosts', '');

        $router = Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'RB951',
            'identity' => 'RB951',
            'ip_address' => '192.168.88.1',
            'api_username' => 'rest-monitor',
            'api_password' => 'test-secret',
            'api_transport' => 'rest',
            'api_port' => 443,
            'api_use_tls' => true,
            'verify_cert' => true,
            'monitoring_enabled' => true,
        ]);

        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), '/system/identity') => Http::response([['name' => 'RB951']]),
                str_contains($request->url(), '/system/resource') => Http::response([[
                    'version' => '7.23.1 (stable)',
                    'board-name' => 'RB951-2n',
                    'architecture-name' => 'mipsbe',
                    'cpu-load' => '20',
                    'uptime' => '1h4m',
                ]]),
                str_contains($request->url(), '/interface?') => Http::response([
                    ['type' => 'bridge', 'mac-address' => '11:11:11:11:11:11'],
                    ['type' => 'ether', 'mac-address' => 'AA:BB:CC:DD:EE:FF'],
                ]),
                default => Http::response([], 404),
            };
        });

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/routers')
            ->assertOk()
            ->assertJsonPath('0.id', $router->id)
            ->assertJsonPath('0.identity', 'RB951')
            ->assertJsonPath('0.status', 'online')
            ->assertJsonPath('0.macAddress', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonPath('0.routerosVersion', '7.23.1 (stable)')
            ->assertJsonPath('0.dataSource', 'live');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/interface?.proplist=mac-address%2Ctype'));
    }

    public function test_rest_traffic_samples_named_running_interfaces_and_returns_live_counters(): void
    {
        config()->set('mikrotik.provider', 'rest');
        config()->set('mikrotik.rest_port', 443);
        config()->set('mikrotik.rest_allowed_ports', [443]);
        config()->set('mikrotik.allowed_hosts', '');

        $router = Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'RB951',
            'identity' => 'RB951',
            'ip_address' => '192.168.88.1',
            'api_username' => 'rest-monitor',
            'api_password' => 'test-secret',
            'api_transport' => 'rest',
            'api_port' => 443,
            'api_use_tls' => true,
            'verify_cert' => true,
            'monitoring_enabled' => true,
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/interface?')) {
                return Http::response([
                    ['name' => 'ether1', 'running' => true, 'disabled' => false, 'rx-byte' => '1000', 'tx-byte' => '2000'],
                    ['name' => 'wlan1', 'running' => true, 'disabled' => false, 'rx-byte' => '3000', 'tx-byte' => '4000'],
                    ['name' => 'ether2', 'running' => false, 'disabled' => false, 'rx-byte' => '9000', 'tx-byte' => '9000'],
                    ['name' => 'lo', 'running' => true, 'disabled' => false, 'rx-byte' => '5000', 'tx-byte' => '5000'],
                ]);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/interface/monitor-traffic')) {
                return match ($request->data()['interface'] ?? null) {
                    'ether1' => Http::response([['rx-bits-per-second' => '1000000', 'tx-bits-per-second' => '2000000']]),
                    'wlan1' => Http::response([['rx-bits-per-second' => '3000000', 'tx-bits-per-second' => '4000000']]),
                    default => Http::response([], 400),
                };
            }

            return Http::response([], 404);
        });

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/traffic")
            ->assertOk()
            ->assertJsonPath('totalRxBytes', 4000)
            ->assertJsonPath('totalTxBytes', 6000)
            ->assertJsonPath('currentRxMbps', 4)
            ->assertJsonPath('currentTxMbps', 6)
            ->assertJsonCount(1, 'history');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->data() === ['interface' => 'ether1', 'once' => '']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->data() === ['interface' => 'wlan1', 'once' => '']);
    }
}

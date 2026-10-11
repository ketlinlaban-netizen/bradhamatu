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
                default => Http::response([], 404),
            };
        });

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
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
            ->assertJsonPath('dataSource', 'live');

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://192.168.88.1/')
            && ! str_contains($request->url(), 'test-secret'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterStatusFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_poll_failure_returns_last_saved_routes_and_metrics_as_stale(): void
    {
        config()->set('mikrotik.provider', 'rest');
        config()->set('mikrotik.rest_port', 443);
        config()->set('mikrotik.rest_allowed_ports', [443]);
        config()->set('mikrotik.allowed_hosts', '');

        $router = Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'Saved router',
            'ip_address' => '192.168.88.1',
            'api_transport' => 'rest',
            'api_username' => 'monitor',
            'api_password' => 'private-password',
            'monitoring_enabled' => true,
            'gateway_observations' => [[
                'routeId' => '*1',
                'gateway' => '192.168.40.1',
                'immediateGateway' => '192.168.40.1%ether1',
                'interface' => 'ether1',
                'distance' => 1,
                'routingTable' => 'main',
                'active' => true,
                'dynamic' => false,
                'static' => true,
                'disabled' => false,
                'ecmp' => false,
            ]],
            'gateway_observed_at' => now()->subMinutes(2),
            'last_seen_at' => now()->subMinutes(2),
        ]);
        RouterMetric::query()->create([
            'id' => (string) Str::uuid(),
            'router_id' => $router->id,
            'cpu_usage' => 12,
            'memory_usage' => 60,
            'storage_usage' => 50,
            'temperature' => 0,
            'rx_rate' => 1.25,
            'tx_rate' => 2.5,
            'recorded_at' => now()->subMinutes(2),
        ]);
        Http::fake(fn (Request $request) => Http::response([], 503));

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/status")
            ->assertOk()
            ->assertJsonPath('dataSource', 'stale')
            ->assertJsonPath('cpuUsage', 12)
            ->assertJsonPath('gatewayRoutes.0.gateway', '192.168.40.1')
            ->assertJsonPath('gatewayObservedAt', $router->gateway_observed_at->toIso8601String());

        $router->refresh();
        $this->assertSame('offline', $router->status_override);
        $this->assertSame('router_unavailable', $router->last_error_category);
    }
}

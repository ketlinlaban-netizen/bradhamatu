<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterTransportMisconfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_transport_is_reported_without_falling_back_to_another_protocol(): void
    {
        config()->set('mikrotik.provider', 'mikrotik');

        $router = Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'Unconfigured Lab Router',
            'identity' => 'Unconfigured Lab Router',
            'ip_address' => '192.168.88.1',
            'api_transport' => 'unsupported',
            'api_port' => 8728,
            'api_use_tls' => false,
            'verify_cert' => true,
            'api_username' => 'read-only-monitor',
            'api_password' => 'test-router-password',
            'monitoring_enabled' => true,
        ]);

        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/mikrotik-configs')
            ->assertOk()
            ->assertJsonPath('0.transportConfigured', false)
            ->assertJsonPath('0.apiTransport', 'unsupported')
            ->assertJsonPath('0.isConnected', false)
            ->assertJsonPath('0.lastErrorCategory', 'transport_misconfigured')
            ->assertJsonPath('0.passwordMasked', '••••••••')
            ->assertJsonMissing(['password' => 'test-router-password']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/mikrotik-configs/{$router->id}/connect")
            ->assertUnprocessable()
            ->assertJsonPath('category', 'transport_misconfigured')
            ->assertDontSee('test-router-password');
    }

    public function test_router_health_is_stale_until_recent_real_health_check_succeeds(): void
    {
        $router = Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'Stale Router',
            'identity' => 'Stale Router',
            'ip_address' => '192.168.88.2',
            'api_transport' => 'rest',
            'api_port' => 443,
            'api_use_tls' => true,
            'verify_cert' => true,
            'api_username' => 'read-only-monitor',
            'api_password' => 'test-router-password',
            'monitoring_enabled' => true,
            'status_override' => 'online',
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'sanctum')
            ->getJson('/api/mikrotik-configs')
            ->assertOk()
            ->assertJsonPath('0.healthStatus', 'stale')
            ->assertJsonPath('0.isConnected', false)
            ->assertJsonPath('0.lastSuccessfulCheckAt', $router->last_seen_at->toIso8601String());
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackendApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_is_public_and_returns_status(): void
    {
        config()->set('mikrotik.provider', 'mock');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('provider', 'mock');
    }

    public function test_router_endpoints_require_authentication(): void
    {
        $this->get('/api/routers')->assertUnauthorized();
        $this->getJson('/api/routers')->assertUnauthorized();
    }

    public function test_user_can_login_and_access_authenticated_endpoints(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => 'secure-password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'secure-password',
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $this->withToken($response->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('role', 'admin');

        $this->withToken($response->json('token'))
            ->getJson('/api/routers')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_tls_router_configuration_defaults_to_api_ssl_port_and_certificate_verification(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/mikrotik-configs', [
                'name' => 'Office Router',
                'host' => '192.0.2.10',
                'useTls' => true,
                'username' => 'monitoring',
                'password' => 'router-password',
            ])
            ->assertCreated()
            ->assertJsonPath('apiPort', 8729)
            ->assertJsonPath('useTls', true)
            ->assertJsonPath('verifyCert', true);
    }
}

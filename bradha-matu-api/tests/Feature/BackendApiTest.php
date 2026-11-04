<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Services\EvilFreelancerRouterOsApiClientFactory;
use App\Services\MikroTikRouterProvider;
use App\Services\MockRouterProvider;
use App\Services\ReadOnlyGuardException;
use App\Services\RouterApiException;
use App\Services\RouterDestinationPolicy;
use App\Services\RouterOsApiClientFactory;
use App\Services\RouterOsApiTransport;
use App\Services\RouterProvider;
use App\Services\RouterReadOnlyGuard;
use App\Services\RouterRestProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function test_router_api_factory_is_registered_in_the_container(): void
    {
        $this->assertInstanceOf(
            EvilFreelancerRouterOsApiClientFactory::class,
            app(RouterOsApiClientFactory::class),
        );
    }

    public function test_provider_selection_is_explicit_and_rejects_unknown_modes(): void
    {
        config()->set('mikrotik.provider', 'mock');
        $this->assertInstanceOf(MockRouterProvider::class, app(RouterProvider::class));

        config()->set('mikrotik.provider', 'mikrotik');
        $this->assertInstanceOf(MikroTikRouterProvider::class, app(RouterProvider::class));

        config()->set('mikrotik.provider', 'rest');
        $this->assertInstanceOf(
            RouterRestProvider::class,
            app(RouterProvider::class),
        );

        config()->set('mikrotik.provider', 'agent');
        $this->expectException(\InvalidArgumentException::class);
        app(RouterProvider::class);
    }

    public function test_routeros_read_only_guard_rejects_mutating_commands_before_network_access(): void
    {
        $guard = new RouterReadOnlyGuard;
        $factory = \Mockery::mock(RouterOsApiClientFactory::class);
        $factory->shouldNotReceive('connect');
        $transport = new RouterOsApiTransport(
            $factory,
            new RouterDestinationPolicy,
            $guard,
        );

        foreach ([
            '/system/reboot',
            '/system/reset-configuration',
            '/user/add',
            '/ip/firewall/filter/add',
            '/system/script/run',
            '/ppp/secret/add',
            '/ppp/active/remove',
        ] as $command) {
            try {
                $transport->print(new Router([
                    'ip_address' => '192.168.88.1',
                    'api_port' => 8728,
                ]), $command);
                $this->fail("Expected {$command} to be rejected.");
            } catch (ReadOnlyGuardException) {
                $this->assertSame('WRITE', $guard->classify($command));
            }
        }

        $this->assertSame('READ', $guard->classify('/system/resource/print'));
        $this->assertSame('READ', $guard->classify('/ip/route/print'));
        $this->assertSame('READ', $guard->classify('/ppp/secret/print'));
        $this->assertSame('READ', $guard->classify('/ppp/active/print'));
    }

    public function test_router_transport_logs_safe_failure_metadata_without_credentials(): void
    {
        $password = 'router-password-must-not-appear-in-logs';
        $router = new Router([
            'id' => 'router-test',
            'ip_address' => '192.168.88.1',
            'api_port' => 8728,
            'api_use_tls' => false,
            'api_username' => 'monitor',
            'api_password' => $password,
        ]);
        $factory = \Mockery::mock(RouterOsApiClientFactory::class);
        $factory->shouldReceive('connect')
            ->once()
            ->andThrow(new RouterApiException(
                'connection_failed',
                'Router could not be reached.',
                new \RuntimeException("Rejected credentials: {$password}"),
            ));

        Log::shouldReceive('notice')
            ->once()
            ->with('RouterOS read request failed.', \Mockery::on(
                fn (array $context): bool => ! str_contains(json_encode($context), $password)
            ));

        try {
            (new RouterOsApiTransport(
                $factory,
                new RouterDestinationPolicy,
                new RouterReadOnlyGuard,
            ))->print($router, '/system/resource/print');
            $this->fail('Expected the connection error to be rethrown.');
        } catch (RouterApiException $exception) {
            $this->assertSame('connection_failed', $exception->category);
            $this->assertStringNotContainsString($password, $exception->safeMessage);
        }
    }

    public function test_production_cookie_configuration_is_secure_by_default(): void
    {
        $keys = ['APP_ENV', 'SESSION_SECURE_COOKIE'];
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = [
                'getenv' => getenv($key),
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        try {
            putenv('APP_ENV=production');
            putenv('SESSION_SECURE_COOKIE');
            $_ENV['APP_ENV'] = 'production';
            $_SERVER['APP_ENV'] = 'production';
            unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);

            $sessionConfig = require base_path('config/session.php');
            $this->assertTrue($sessionConfig['secure']);
        } finally {
            foreach ($previous as $key => $values) {
                if ($values['getenv'] === false) {
                    putenv($key);
                } else {
                    putenv("{$key}={$values['getenv']}");
                }
                if ($values['env'] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $values['env'];
                }
                if ($values['server'] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $values['server'];
                }
            }
        }
    }

    public function test_mock_provider_marks_dashboard_data_as_demo(): void
    {
        config()->set('mikrotik.provider', 'mock');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('dataSource', 'mock')
            ->assertJsonPath('totalRouters', 2);
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
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'sanctum')
            ->postJson('/api/mikrotik-configs', [
                'name' => 'Office Router',
                'host' => '192.168.2.10',
                'useTls' => true,
                'username' => 'monitoring',
                'password' => 'router-password',
            ])
            ->assertCreated()
            ->assertJsonPath('apiPort', 8729)
            ->assertJsonPath('useTls', true)
            ->assertJsonPath('verifyCert', true);
    }

    public function test_standard_binary_api_ports_reject_a_mismatched_tls_mode(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'sanctum')
            ->postJson('/api/mikrotik-configs', [
                'name' => 'Plain API mismatch',
                'host' => '192.168.2.10',
                'apiPort' => 8728,
                'useTls' => true,
                'username' => 'monitoring',
                'password' => 'router-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('apiPort');

        $this->postJson('/api/mikrotik-configs', [
            'name' => 'API-SSL mismatch',
            'host' => '192.168.2.10',
            'apiPort' => 8729,
            'useTls' => false,
            'username' => 'monitoring',
            'password' => 'router-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('apiPort');
    }

    public function test_router_credentials_are_encrypted_at_rest_and_never_returned(): void
    {
        config()->set('mikrotik.provider', 'mikrotik');
        $user = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/mikrotik-configs', [
                'name' => 'Office Router',
                'host' => '192.168.2.10',
                'useTls' => false,
                'username' => 'monitoring',
                'password' => 'router-password',
            ])
            ->assertCreated()
            ->assertJsonMissingPath('password');

        $encryptedPassword = DB::table('routers')->where('id', $response->json('id'))->value('api_password');
        $this->assertNotSame('router-password', $encryptedPassword);
        $this->assertSame('router-password', Crypt::decryptString($encryptedPassword));
        $this->assertSame('router-password', Router::findOrFail($response->json('id'))->api_password);
    }

    public function test_non_super_admin_cannot_manage_router_inventory(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->postJson('/api/mikrotik-configs', [
                'name' => 'Office Router',
                'host' => '192.168.2.10',
                'username' => 'monitoring',
                'password' => 'router-password',
            ])
            ->assertForbidden();
    }

    public function test_router_configuration_rejects_public_addresses(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'sanctum')
            ->postJson('/api/mikrotik-configs', [
                'name' => 'Public Target',
                'host' => '8.8.8.8',
                'username' => 'monitoring',
                'password' => 'router-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('host');
    }
}

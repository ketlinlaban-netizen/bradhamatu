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
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterProviderSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_router_api_factory_is_registered_and_provider_selection_is_explicit(): void
    {
        $this->assertInstanceOf(EvilFreelancerRouterOsApiClientFactory::class, app(RouterOsApiClientFactory::class));

        config()->set('mikrotik.provider', 'mock');
        $this->assertInstanceOf(MockRouterProvider::class, app(RouterProvider::class));

        config()->set('mikrotik.provider', 'mikrotik');
        $this->assertInstanceOf(MikroTikRouterProvider::class, app(RouterProvider::class));

        config()->set('mikrotik.provider', 'rest');
        $this->assertInstanceOf(RouterRestProvider::class, app(RouterProvider::class));

        config()->set('mikrotik.provider', 'unrecognized');
        $this->expectException(\InvalidArgumentException::class);
        app(RouterProvider::class);
    }

    public function test_mutating_routeros_commands_are_rejected_before_client_creation(): void
    {
        $factory = \Mockery::mock(RouterOsApiClientFactory::class);
        $factory->shouldNotReceive('connect');
        $transport = new RouterOsApiTransport(
            $factory,
            new RouterDestinationPolicy,
            new RouterReadOnlyGuard,
        );
        $router = new Router([
            'ip_address' => '192.168.88.1',
            'api_port' => 8728,
        ]);

        foreach ([
            '/system/reboot',
            '/system/reset-configuration',
            '/user/add',
            '/ip/firewall/filter/add',
            '/system/script/run',
        ] as $command) {
            try {
                $transport->print($router, $command);
                $this->fail("Expected {$command} to be rejected.");
            } catch (ReadOnlyGuardException) {
                $this->assertSame('WRITE', (new RouterReadOnlyGuard)->classify($command));
            }
        }

        $this->assertSame('READ', (new RouterReadOnlyGuard)->classify('/system/resource/print'));
    }

    public function test_mock_provider_is_explicitly_labeled_as_demo_data(): void
    {
        config()->set('mikrotik.provider', 'mock');

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('dataSource', 'mock')
            ->assertJsonPath('totalRouters', 2);
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

    public function test_production_cookie_configuration_defaults_to_secure(): void
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

    public function test_router_credentials_are_encrypted_at_rest_and_never_returned(): void
    {
        $password = 'router-password-must-not-appear-in-responses';
        $router = Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Router',
            'ip_address' => '192.0.2.10',
            'api_port' => 8728,
            'api_username' => 'monitor',
            'api_password' => $password,
        ]);

        $stored = DB::table('routers')->where('id', $router->id)->value('api_password');
        $this->assertNotSame($password, $stored);
        $this->assertSame($password, Crypt::decryptString($stored));

        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'sanctum')
            ->getJson('/api/mikrotik-configs')
            ->assertOk()
            ->assertDontSee($password)
            ->assertJsonPath('0.passwordMasked', '••••••••');
    }
}

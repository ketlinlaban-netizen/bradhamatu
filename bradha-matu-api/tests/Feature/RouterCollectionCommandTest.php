<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterMetric;
use App\Services\RouterApiException;
use App\Services\RouterProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RouterCollectionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_collection_persists_status_and_metrics(): void
    {
        config()->set('mikrotik.provider', 'mikrotik');
        $router = $this->createRouter();
        $provider = Mockery::mock(RouterProvider::class);
        $provider->shouldReceive('getStatus')->once()->with($router->id)->andReturn([
            'routerId' => $router->id,
            'cpuUsage' => 25,
            'memoryUsage' => 40,
            'storageUsage' => 30,
            'temperature' => null,
            'routerosVersion' => '7.20.1',
        ]);
        $provider->shouldReceive('getTraffic')->once()->with($router->id)->andReturn([
            'currentRxMbps' => 1.5,
            'currentTxMbps' => 0.75,
        ]);
        app()->instance(RouterProvider::class, $provider);

        $this->assertSame(0, Artisan::call('routers:collect'));

        $router->refresh();
        $this->assertSame('online', $router->status_override);
        $this->assertNotNull($router->last_attempted_at);
        $this->assertNotNull($router->last_seen_at);
        $this->assertSame('7.20.1', $router->last_routeros_version);
        $this->assertDatabaseHas('router_metrics', [
            'router_id' => $router->id,
            'cpu_usage' => 25,
            'rx_rate' => 1.5,
            'tx_rate' => 0.75,
        ]);
    }

    public function test_failed_status_collection_marks_router_offline_without_fabricating_metrics(): void
    {
        config()->set('mikrotik.provider', 'mikrotik');
        $router = $this->createRouter();
        $provider = Mockery::mock(RouterProvider::class);
        $provider->shouldReceive('getStatus')->once()->with($router->id)->andThrow(
            new RouterApiException('connection_failed', 'Router could not be reached.'),
        );
        $provider->shouldNotReceive('getTraffic');
        app()->instance(RouterProvider::class, $provider);

        $this->assertSame(0, Artisan::call('routers:collect'));

        $router->refresh();
        $this->assertSame('offline', $router->status_override);
        $this->assertSame('connection_failed', $router->last_error_category);
        $this->assertNotNull($router->last_attempted_at);
        $this->assertNull($router->last_seen_at);
        $this->assertSame(0, RouterMetric::query()->where('router_id', $router->id)->count());
    }

    private function createRouter(): Router
    {
        return Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'Collector router',
            'ip_address' => '192.168.88.1',
            'api_username' => 'monitor',
            'api_password' => 'private-password',
            'monitoring_enabled' => true,
        ]);
    }
}

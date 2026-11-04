<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Services\RouterApiException;
use App\Services\RouterConnectionTester;
use App\Services\RouterReadTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RouterConnectionTesterTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_read_only_health_check_returns_live_router_observations(): void
    {
        $router = $this->router();
        $transport = Mockery::mock(RouterReadTransport::class);

        $transport->shouldReceive('print')->ordered()->with($router, '/system/identity/print')->andReturn([['name' => 'RB951']]);
        $transport->shouldReceive('print')->ordered()->with($router, '/system/resource/print')->andReturn([[
            'version' => '7.23.1 (stable)',
            'board-name' => 'RB951-2n',
            'architecture-name' => 'mipsbe',
            'cpu-load' => '18',
            'uptime' => '58m35s',
            'total-memory' => '67108864',
            'free-memory' => '25120768',
            'total-hdd-space' => '134217728',
            'free-hdd-space' => '113627136',
        ]]);
        $transport->shouldReceive('print')->ordered()->with($router, '/interface/print', [
            '.proplist' => 'name,type,running,disabled,mac-address,rx-byte,tx-byte',
        ])->andReturn([['name' => 'ether1', 'type' => 'ether', 'running' => 'true', 'rx-byte' => '1000']]);
        $transport->shouldReceive('print')->ordered()->with($router, '/ip/address/print', [
            '.proplist' => 'address,interface',
        ])->andReturn([['address' => '192.168.88.1/24', 'interface' => 'bridge']]);
        $transport->shouldReceive('print')->ordered()->with($router, '/ip/route/print', [
            '.proplist' => '.id,dst-address,gateway,immediate-gw,distance,active,dynamic,static,disabled,routing-table',
        ])->andReturn([['dst-address' => '0.0.0.0/0', 'gateway' => '192.168.88.254']]);
        $transport->shouldReceive('print')->ordered()->with($router, '/ip/dhcp-server/lease/print', [
            '.proplist' => 'address,mac-address,host-name,interface,status,expires-after',
        ])->andReturn([['address' => '192.168.88.10', 'status' => 'bound']]);

        $result = (new RouterConnectionTester($transport))->test($router);

        $this->assertTrue($result['success']);
        $this->assertSame('7.23.1 (stable)', $result['router']['routerosVersion']);
        $this->assertSame('mipsbe', $result['router']['architecture']);
        $this->assertSame('ether1', $result['observations']['interfaces']['records'][0]['name']);
        $this->assertSame('192.168.88.1/24', $result['observations']['addresses']['records'][0]['address']);
        $this->assertSame('192.168.88.254', $result['observations']['routes']['records'][0]['gateway']);
        $this->assertSame('bound', $result['observations']['dhcpLeases']['records'][0]['status']);
        $this->assertNull($result['observations']['dhcpLeases']['error']);
        $this->assertSame('online', $router->fresh()->status_override);
        $this->assertNotNull($router->fresh()->last_seen_at);
    }

    public function test_optional_read_failure_is_reported_without_hiding_successful_health_check(): void
    {
        $router = $this->router();
        $transport = Mockery::mock(RouterReadTransport::class);
        $transport->shouldReceive('print')->ordered()->with($router, '/system/identity/print')->andReturn([['name' => 'RB951']]);
        $transport->shouldReceive('print')->ordered()->with($router, '/system/resource/print')->andReturn([['version' => '7.23.1']]);
        $transport->shouldReceive('print')->ordered()->with($router, '/interface/print', Mockery::type('array'))->andThrow(
            new RouterApiException('permission_denied', 'RouterOS denied this read.', httpStatus: 502),
        );
        $transport->shouldReceive('print')->ordered()->with($router, '/ip/address/print', Mockery::type('array'))->andReturn([]);
        $transport->shouldReceive('print')->ordered()->with($router, '/ip/route/print', Mockery::type('array'))->andReturn([]);
        $transport->shouldReceive('print')->ordered()->with($router, '/ip/dhcp-server/lease/print', Mockery::type('array'))->andReturn([]);

        $result = (new RouterConnectionTester($transport))->test($router);

        $this->assertTrue($result['success']);
        $this->assertSame('permission_denied', $result['observations']['interfaces']['error']['category']);
        $this->assertSame('online', $router->fresh()->status_override);
    }

    public function test_identity_mismatch_prevents_online_status_and_additional_reads(): void
    {
        $router = $this->router();
        $transport = Mockery::mock(RouterReadTransport::class);
        $transport->shouldReceive('print')->ordered()->with($router, '/system/identity/print')->andReturn([['name' => 'Unexpected Router']]);
        $transport->shouldReceive('print')->ordered()->with($router, '/system/resource/print')->andReturn([['version' => '7.23.1']]);
        $transport->shouldNotReceive('print')->with($router, '/interface/print', Mockery::any());

        try {
            (new RouterConnectionTester($transport))->test($router);
            $this->fail('Expected an identity mismatch.');
        } catch (RouterApiException $exception) {
            $this->assertSame('router_identity_mismatch', $exception->category);
        }

        $this->assertSame('offline', $router->fresh()->status_override);
        $this->assertSame('router_identity_mismatch', $router->fresh()->last_error_category);
    }

    private function router(): Router
    {
        return Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'RB951',
            'identity' => 'RB951',
            'ip_address' => '192.168.88.1',
            'api_transport' => 'rest',
            'api_port' => 443,
            'api_use_tls' => true,
            'verify_cert' => true,
            'api_username' => 'readonly-monitor',
            'api_password' => 'test-secret',
            'monitoring_enabled' => true,
        ]);
    }
}

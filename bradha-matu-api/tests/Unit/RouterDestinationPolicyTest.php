<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Services\ReadOnlyGuardException;
use App\Services\RouterApiException;
use App\Services\RouterDestinationPolicy;
use App\Services\RouterOsApiClientFactory;
use App\Services\RouterOsApiTransport;
use App\Services\RouterReadOnlyGuard;
use Mockery;
use Tests\TestCase;

class RouterDestinationPolicyTest extends TestCase
{
    public function test_rfc1918_router_addresses_are_allowed(): void
    {
        $destination = (new RouterDestinationPolicy)->resolve('192.168.88.1');

        $this->assertSame(['address' => '192.168.88.1', 'peer_name' => '192.168.88.1'], $destination);
    }

    public function test_public_router_addresses_are_rejected(): void
    {
        $this->expectException(RouterApiException::class);
        $this->expectExceptionMessage('Router targets must be approved private management addresses.');

        (new RouterDestinationPolicy)->resolve('8.8.8.8');
    }

    public function test_unapproved_router_commands_are_blocked_before_client_creation(): void
    {
        $factory = Mockery::mock(RouterOsApiClientFactory::class);
        $factory->shouldNotReceive('connect');
        $router = new Router(['ip_address' => '192.168.88.1', 'api_port' => 8728]);
        $transport = new RouterOsApiTransport(
            $factory,
            new RouterDestinationPolicy,
            new RouterReadOnlyGuard,
        );

        $this->expectException(ReadOnlyGuardException::class);

        $transport->print($router, '/system/reboot');
    }
}

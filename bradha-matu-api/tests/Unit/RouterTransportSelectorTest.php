<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Services\RouterApiException;
use App\Services\RouterOsApiTransport;
use App\Services\RouterRestTransport;
use App\Services\RouterTransportSelector;
use Mockery;
use Tests\TestCase;

class RouterTransportSelectorTest extends TestCase
{
    public function test_transport_is_selected_from_the_saved_router_configuration(): void
    {
        $binary = Mockery::mock(RouterOsApiTransport::class);
        $rest = Mockery::mock(RouterRestTransport::class);
        $router = new Router(['api_transport' => 'rest']);

        $binary->shouldNotReceive('print');
        $rest->shouldReceive('print')
            ->once()
            ->with($router, '/system/resource/print', [], [])
            ->andReturn([['version' => '7.20.1']]);

        $result = (new RouterTransportSelector($binary, $rest))
            ->print($router, '/system/resource/print');

        $this->assertSame([['version' => '7.20.1']], $result);
    }

    public function test_binary_api_profile_never_falls_back_to_rest(): void
    {
        $binary = Mockery::mock(RouterOsApiTransport::class);
        $rest = Mockery::mock(RouterRestTransport::class);
        $router = new Router(['api_transport' => 'binary']);

        $binary->shouldReceive('print')
            ->once()
            ->with($router, '/system/resource/print', [], [])
            ->andReturn([['version' => '7.23.1']]);
        $rest->shouldNotReceive('print');

        $result = (new RouterTransportSelector($binary, $rest))
            ->print($router, '/system/resource/print');

        $this->assertSame([['version' => '7.23.1']], $result);
    }

    public function test_unknown_saved_transport_fails_explicitly(): void
    {
        try {
            (new RouterTransportSelector(
                Mockery::mock(RouterOsApiTransport::class),
                Mockery::mock(RouterRestTransport::class),
            ))->print(new Router(['api_transport' => 'unknown']), '/system/resource/print');
            $this->fail('Expected a safe transport misconfiguration error.');
        } catch (RouterApiException $exception) {
            $this->assertSame('transport_misconfigured', $exception->category);
            $this->assertSame(422, $exception->httpStatus);
        }
    }

    public function test_missing_saved_transport_fails_instead_of_defaulting_to_a_protocol(): void
    {
        try {
            (new RouterTransportSelector(
                Mockery::mock(RouterOsApiTransport::class),
                Mockery::mock(RouterRestTransport::class),
            ))->print(new Router(['api_transport' => null]), '/system/resource/print');
            $this->fail('Expected a safe transport misconfiguration error.');
        } catch (RouterApiException $exception) {
            $this->assertSame('transport_misconfigured', $exception->category);
        }
    }
}

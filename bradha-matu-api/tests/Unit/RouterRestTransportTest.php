<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Services\ReadOnlyGuardException;
use App\Services\RouterApiException;
use App\Services\RouterDestinationPolicy;
use App\Services\RouterReadOnlyGuard;
use App\Services\RouterRestTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RouterRestTransportTest extends TestCase
{
    private RouterRestTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mikrotik.rest_port', 443);
        config()->set('mikrotik.rest_allowed_ports', [443]);
        config()->set('mikrotik.tls_ca_file', null);
        config()->set('mikrotik.connect_timeout', 3);
        config()->set('mikrotik.query_timeout', 5);
        config()->set('mikrotik.allowed_hosts', '');

        $this->transport = new RouterRestTransport(
            new RouterDestinationPolicy,
            new RouterReadOnlyGuard,
        );
    }

    public function test_print_uses_https_pinned_endpoint_and_normalizes_rest_data(): void
    {
        $requestOptions = null;
        Http::fake(function (Request $request, array $options) use (&$requestOptions) {
            $requestOptions = $options;

            return Http::response([[
                'name' => 'ether1',
                'running' => true,
                'rx-byte' => 12,
            ]]);
        });

        $records = $this->transport->print(
            $this->router(),
            '/interface/print',
            ['.proplist' => 'name,running,rx-byte'],
        );

        self::assertSame([[
            'name' => 'ether1',
            'running' => 'true',
            'rx-byte' => 12,
        ]], $records);
        self::assertSame(
            'https://192.168.88.1/rest/interface?.proplist=name%2Crunning%2Crx-byte',
            Http::recorded()->first()[0]->url(),
        );
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://192.168.88.1/rest/interface?')
            && $request->hasHeader('Accept', 'application/json'));
        self::assertSame(true, $requestOptions['verify'] ?? null);
        self::assertFalse($requestOptions['allow_redirects'] ?? true);
        self::assertTrue($requestOptions['stream'] ?? false);
        self::assertSame(3, $requestOptions['connect_timeout'] ?? null);
        self::assertSame(5, $requestOptions['timeout'] ?? null);
    }

    public function test_configured_hostname_is_pinned_to_the_validated_private_ip(): void
    {
        $options = null;
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response([]);
        });
        $transport = new RouterRestTransport(
            new class extends RouterDestinationPolicy
            {
                public function resolve(string $host): array
                {
                    return ['address' => '192.168.88.1', 'peer_name' => 'router.example.test'];
                }
            },
            new RouterReadOnlyGuard,
        );

        $transport->print($this->router('router.example.test'), '/system/resource/print');

        self::assertSame(['router.example.test:443:192.168.88.1'], $options['curl'][CURLOPT_RESOLVE] ?? null);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://router.example.test/rest/system/resource');
    }

    public function test_monitor_uses_the_fixed_bounded_one_shot_post(): void
    {
        Http::fake(fn () => Http::response([[
            'name' => 'ether1',
            'rx-bits-per-second' => '1200',
        ]]));

        $this->transport->monitor($this->router(), '/interface/monitor-traffic', [
            'interface' => 'all',
            '.proplist' => 'name,rx-bits-per-second,tx-bits-per-second',
            'once' => '',
        ]);

        self::assertSame('POST', Http::recorded()->first()[0]->method());
        self::assertSame(
            ['interface' => 'all', 'once' => ''],
            Http::recorded()->first()[0]->data(),
        );
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://192.168.88.1/rest/interface/monitor-traffic'
            && $request->data() === ['interface' => 'all', 'once' => '']);
    }

    public function test_authentication_failure_is_safely_reported_without_router_body(): void
    {
        Http::fake(fn () => Http::response(['error' => 'sensitive router detail'], 401));

        try {
            $this->transport->print($this->router(), '/system/resource/print');
            self::fail('Expected authentication failure.');
        } catch (RouterApiException $exception) {
            self::assertSame('authentication_failed', $exception->category);
            self::assertStringNotContainsString('sensitive', $exception->safeMessage);
        }
    }

    public function test_timeout_and_tls_failures_have_safe_error_messages(): void
    {
        Http::fake(fn () => throw new ConnectionException('SSL certificate problem: self-signed certificate'));

        try {
            $this->transport->print($this->router(), '/system/resource/print');
            self::fail('Expected TLS connection failure.');
        } catch (RouterApiException $exception) {
            self::assertSame('router_unavailable', $exception->category);
            self::assertStringNotContainsString('certificate problem', $exception->safeMessage);
        }

        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        try {
            $this->transport->print($this->router(), '/system/resource/print');
            self::fail('Expected request timeout.');
        } catch (RouterApiException $exception) {
            self::assertSame('timeout', $exception->category);
            self::assertSame('The RouterOS REST request timed out.', $exception->safeMessage);
        }
    }

    public function test_malformed_unavailable_and_oversized_responses_are_rejected(): void
    {
        Http::fake(fn () => Http::response('{not-json'));
        $this->assertCategory('malformed_response');

        Http::swap(new Factory);
        Http::fake(fn () => Http::response([], 503));
        $this->assertCategory('router_unavailable');

        Http::swap(new Factory);
        Http::fake(fn () => Http::response('[]', 200, ['Content-Length' => '1048577']));
        $this->assertCategory('response_too_large');

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(str_repeat('x', 1_048_577)));
        $this->assertCategory('response_too_large');
    }

    public function test_redirects_and_unapproved_destinations_are_rejected(): void
    {
        Http::fake(fn () => Http::response('', 302, ['Location' => 'https://8.8.8.8/']));
        $this->assertCategory('redirect_blocked');
        Http::assertSentCount(1);

        Http::swap(new Factory);
        Http::fake();
        try {
            $this->transport->print($this->router('8.8.8.8'), '/system/resource/print');
            self::fail('Expected public destination to be rejected.');
        } catch (RouterApiException $exception) {
            self::assertSame('destination_not_allowed', $exception->category);
        }
        Http::assertNothingSent();
    }

    public function test_hostname_and_unapproved_rest_port_are_rejected_before_http(): void
    {
        Http::fake();
        try {
            $this->transport->print($this->router('unapproved.example.test'), '/system/resource/print');
            self::fail('Expected an unapproved hostname to be rejected.');
        } catch (RouterApiException $exception) {
            self::assertSame('destination_not_allowed', $exception->category);
        }
        Http::assertNothingSent();

        config()->set('mikrotik.rest_port', 8443);
        try {
            $this->transport->print($this->router(), '/system/resource/print');
            self::fail('Expected an unapproved REST port to be rejected.');
        } catch (RouterApiException $exception) {
            self::assertSame('invalid_rest_port', $exception->category);
        }
        Http::assertNothingSent();
    }

    public function test_unapproved_routeros_commands_and_monitor_arguments_never_reach_http(): void
    {
        Http::fake();
        foreach ([
            '/system/reboot',
            '/system/reset-configuration',
            '/user/add',
            '/ip/firewall/filter/add',
            '/system/script/run',
        ] as $command) {
            try {
                $this->transport->print($this->router(), $command);
                self::fail("Expected {$command} to be rejected.");
            } catch (ReadOnlyGuardException) {
                self::assertTrue(true);
            }
        }

        try {
            $this->transport->monitor($this->router(), '/interface/monitor-traffic', [
                'interface' => 'ether1',
                'once' => '',
            ]);
            self::fail('Expected unsupported monitor target to be rejected.');
        } catch (RouterApiException $exception) {
            self::assertSame('invalid_read_parameters', $exception->category);
        }
        try {
            $this->transport->monitor($this->router(), '/system/resource/print', [
                'interface' => 'all',
                'once' => '',
            ]);
            self::fail('Expected a non-monitor command to be rejected.');
        } catch (RouterApiException $exception) {
            self::assertSame('invalid_read_parameters', $exception->category);
        }
        try {
            $this->transport->print($this->router(), '/interface/monitor-traffic');
            self::fail('Expected unbounded traffic monitoring to be rejected.');
        } catch (RouterApiException $exception) {
            self::assertSame('invalid_read_operation', $exception->category);
        }
        Http::assertNothingSent();
    }

    private function assertCategory(string $category): void
    {
        try {
            $this->transport->print($this->router(), '/system/resource/print');
            self::fail("Expected {$category}.");
        } catch (RouterApiException $exception) {
            self::assertSame($category, $exception->category);
        }
    }

    private function router(string $host = '192.168.88.1'): Router
    {
        return new Router([
            'id' => 'router-1',
            'ip_address' => $host,
            'api_port' => 8729,
            'api_username' => 'monitor',
            'api_password' => 'router-secret',
            'api_use_tls' => false,
            'verify_cert' => false,
        ]);
    }
}

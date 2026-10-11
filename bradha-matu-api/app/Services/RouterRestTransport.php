<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class RouterRestTransport implements RouterReadTransport
{
    private const MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private RouterDestinationPolicy $destinationPolicy,
        private RouterReadOnlyGuard $readOnlyGuard,
    ) {}

    public function print(Router $router, string $command, array $args = [], array $queries = []): array
    {
        $this->readOnlyGuard->enforceRouterOsCommand($command);
        if ($command === '/interface/monitor-traffic') {
            throw new RouterApiException(
                'invalid_read_operation',
                'Interface traffic monitoring must use its bounded one-shot request.',
                httpStatus: 422,
            );
        }
        $this->validatePrintArguments($args, $queries);

        $query = [];
        if (isset($args['.proplist'])) {
            $query['.proplist'] = $args['.proplist'];
        }
        foreach ($queries as $word) {
            $query['interface'] = rawurldecode(substr($word, strlen('?interface=')));
        }

        return $this->request($router, 'GET', $this->resourcePath($command), $query);
    }

    public function monitor(Router $router, string $command, array $args = []): array
    {
        $this->readOnlyGuard->enforceRouterOsCommand($command);

        if ($command !== '/interface/monitor-traffic'
            || array_diff(array_keys($args), ['interface', '.proplist', 'once']) !== []
            || ($args['interface'] ?? null) !== 'all'
            || ($args['once'] ?? null) !== ''
            || (isset($args['.proplist']) && ! $this->isValidPropertyList($args['.proplist']))) {
            throw new RouterApiException(
                'invalid_read_parameters',
                'Only the bounded one-shot interface traffic read is permitted.',
                httpStatus: 422,
            );
        }

        return $this->request($router, 'POST', $this->resourcePath($command), [
            'interface' => 'all',
            'once' => '',
        ]);
    }

    private function request(Router $router, string $method, string $resource, array $parameters): array
    {
        $destination = $this->destinationPolicy->resolve($router->ip_address);
        $port = (int) config('mikrotik.rest_port', 443);
        $allowedPorts = (array) config('mikrotik.rest_allowed_ports', [443]);
        if ($port < 1 || $port > 65535 || ! in_array($port, $allowedPorts, true)) {
            throw new RouterApiException(
                'invalid_rest_port',
                'The configured RouterOS REST port is not approved.',
                httpStatus: 500,
            );
        }

        $caFile = config('mikrotik.tls_ca_file');
        if ($caFile !== null && $caFile !== ''
            && (! is_string($caFile) || ! is_file($caFile) || ! is_readable($caFile))) {
            throw new RouterApiException(
                'tls_ca_unavailable',
                'The configured TLS trust bundle is unavailable.',
                httpStatus: 500,
            );
        }

        $connectTimeout = min(10, max(1, (int) config('mikrotik.connect_timeout', 3)));
        $requestTimeout = min(30, max($connectTimeout, (int) config('mikrotik.query_timeout', 5)));
        $host = $destination['peer_name'];
        $options = [
            'verify' => $caFile ?: true,
            'allow_redirects' => false,
            'stream' => true,
        ];

        if ($host !== $destination['address']) {
            if (! defined('CURLOPT_RESOLVE')) {
                throw new RouterApiException(
                    'transport_unavailable',
                    'The HTTPS client cannot pin the approved router address.',
                    httpStatus: 500,
                );
            }
            $options['curl'] = [
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$destination['address']}"],
            ];
        }

        $url = "https://{$host}:{$port}/rest/{$resource}";

        try {
            $pendingRequest = Http::withOptions($options)
                ->connectTimeout($connectTimeout)
                ->timeout($requestTimeout)
                ->withBasicAuth($router->api_username, $router->api_password)
                ->acceptJson();

            $response = $method === 'GET'
                ? $pendingRequest->get($url, $parameters)
                : $pendingRequest->post($url, $parameters);

            if ($response->status() >= 300 && $response->status() < 400) {
                throw new RouterApiException(
                    'redirect_blocked',
                    'RouterOS REST redirects are not permitted.',
                    httpStatus: 502,
                );
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new RouterApiException(
                    'authentication_failed',
                    'RouterOS rejected the configured read-only credentials.',
                    httpStatus: 502,
                );
            }

            if (! $response->successful()) {
                throw new RouterApiException(
                    $response->serverError() ? 'router_unavailable' : 'router_request_failed',
                    'The RouterOS REST request was rejected or unavailable.',
                    httpStatus: 502,
                );
            }

            $contentLength = $response->header('Content-Length');
            if (is_string($contentLength) && ctype_digit($contentLength)
                && (int) $contentLength > self::MAX_RESPONSE_BYTES) {
                throw new RouterApiException(
                    'response_too_large',
                    'The RouterOS response exceeded the permitted size.',
                    httpStatus: 502,
                );
            }

            $body = $this->readBoundedBody($response->toPsrResponse()->getBody());
            try {
                $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RouterApiException(
                    'malformed_response',
                    'RouterOS returned malformed REST data.',
                    $exception,
                    502,
                );
            }

            return $this->normalizeResponse($decoded);
        } catch (RouterApiException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out');
            throw new RouterApiException(
                $timedOut ? 'timeout' : 'router_unavailable',
                $timedOut
                    ? 'The RouterOS REST request timed out.'
                    : 'The RouterOS REST endpoint could not be reached or its TLS certificate was rejected.',
                $exception,
                502,
            );
        } catch (RuntimeException $exception) {
            throw new RouterApiException(
                'response_read_failed',
                'The RouterOS REST response could not be read safely.',
                $exception,
                502,
            );
        }
    }

    private function readBoundedBody(StreamInterface $body): string
    {
        $content = '';
        while (! $body->eof()) {
            $remaining = self::MAX_RESPONSE_BYTES + 1 - strlen($content);
            if ($remaining <= 0) {
                throw new RouterApiException(
                    'response_too_large',
                    'The RouterOS response exceeded the permitted size.',
                    httpStatus: 502,
                );
            }

            $chunk = $body->read(min(8192, $remaining));
            if ($chunk === '' && ! $body->eof()) {
                throw new RouterApiException(
                    'malformed_response',
                    'RouterOS returned an incomplete REST response.',
                    httpStatus: 502,
                );
            }
            $content .= $chunk;
        }

        if (strlen($content) > self::MAX_RESPONSE_BYTES) {
            throw new RouterApiException(
                'response_too_large',
                'The RouterOS response exceeded the permitted size.',
                httpStatus: 502,
            );
        }

        return $content;
    }

    private function resourcePath(string $command): string
    {
        $path = str_ends_with($command, '/print')
            ? substr($command, 0, -strlen('/print'))
            : $command;

        return ltrim($path, '/');
    }

    private function validatePrintArguments(array $args, array $queries): void
    {
        if (array_diff(array_keys($args), ['.proplist']) !== []
            || (isset($args['.proplist']) && ! $this->isValidPropertyList($args['.proplist']))
            || count($queries) > 1) {
            throw new RouterApiException(
                'invalid_read_parameters',
                'The RouterOS REST request contains unsupported read parameters.',
                httpStatus: 422,
            );
        }

        foreach ($queries as $word) {
            if (! is_string($word) || ! str_starts_with($word, '?interface=')) {
                throw new RouterApiException(
                    'invalid_read_parameters',
                    'The RouterOS REST request contains unsupported read parameters.',
                    httpStatus: 422,
                );
            }
            $interface = rawurldecode(substr($word, strlen('?interface=')));
            if ($interface === '' || strlen($interface) > 128 || preg_match('/[\x00-\x1F\x7F]/', $interface)) {
                throw new RouterApiException(
                    'invalid_read_parameters',
                    'The RouterOS REST request contains unsupported read parameters.',
                    httpStatus: 422,
                );
            }
        }
    }

    private function isValidPropertyList(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 2048
            && preg_match('/^[A-Za-z0-9,._-]+$/', $value) === 1;
    }

    private function normalizeResponse(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            throw new RouterApiException(
                'malformed_response',
                'RouterOS returned an unexpected REST data shape.',
                httpStatus: 502,
            );
        }

        $records = array_is_list($decoded) ? $decoded : [$decoded];
        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new RouterApiException(
                    'malformed_response',
                    'RouterOS returned an unexpected REST data shape.',
                    httpStatus: 502,
                );
            }
        }

        return array_map(
            fn (array $record): array => array_map(
                static fn (mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value,
                $record,
            ),
            $records,
        );
    }
}

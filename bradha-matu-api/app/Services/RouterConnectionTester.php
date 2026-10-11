<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

class RouterConnectionTester
{
    public function __construct(private RouterReadTransport $transport) {}

    public function test(Router $router): array
    {
        $startedAt = hrtime(true);
        $router->forceFill([
            'last_attempted_at' => now(),
            'last_error_category' => null,
        ])->save();

        try {
            $identity = $this->transport->print($router, '/system/identity/print');
            $resources = $this->transport->print($router, '/system/resource/print');
            if (! isset($identity[0]['name']) || $resources === []) {
                throw new RouterApiException(
                    'protocol_error',
                    'The router returned no usable identity or system resource data.',
                );
            }

            $resource = $resources[0];
            $elapsedMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);
            $router->forceFill([
                'last_seen_at' => now(),
                'last_routeros_version' => $resource['version'] ?? null,
                'last_error_category' => null,
                'status_override' => 'online',
            ])->save();

            return [
                'success' => true,
                'state' => 'established',
                'category' => null,
                'message' => 'Authenticated read-only RouterOS API test succeeded.',
                'readOnly' => true,
                'elapsedMs' => $elapsedMs,
                'router' => [
                    'id' => $router->id,
                    'name' => $router->name,
                    'identity' => $identity[0]['name'],
                    'host' => $router->ip_address,
                    'model' => $resource['board-name'] ?? null,
                    'serialNumber' => $resource['serial-number'] ?? null,
                    'routerosVersion' => $resource['version'] ?? null,
                    'architecture' => $resource['architecture'] ?? null,
                    'cpuLoad' => isset($resource['cpu-load']) ? (int) $resource['cpu-load'] : null,
                    'uptime' => $resource['uptime'] ?? null,
                    'totalMemory' => isset($resource['total-memory']) ? (int) $resource['total-memory'] : null,
                    'freeMemory' => isset($resource['free-memory']) ? (int) $resource['free-memory'] : null,
                    'totalHdd' => isset($resource['total-hdd-space']) ? (int) $resource['total-hdd-space'] : null,
                    'freeHdd' => isset($resource['free-hdd-space']) ? (int) $resource['free-hdd-space'] : null,
                    'cpuCount' => isset($resource['cpu-count']) ? (int) $resource['cpu-count'] : null,
                    'platform' => $resource['platform'] ?? null,
                ],
            ];
        } catch (RouterApiException $exception) {
            $router->forceFill([
                'last_error_category' => $exception->category,
                'status_override' => 'offline',
            ])->save();
            Log::notice('Router connection test failed.', [
                'router_id' => $router->id,
                'category' => $exception->category,
            ]);

            throw $exception;
        }
    }
}

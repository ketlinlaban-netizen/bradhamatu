<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\RouterMetric;
use App\Services\RouterApiException;
use App\Services\RouterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CollectRouterMetrics extends Command
{
    protected $signature = 'routers:collect';

    protected $description = 'Collect read-only router status and traffic observations.';

    public function handle(RouterService $service): int
    {
        if (config('mikrotik.provider') === 'mock') {
            $this->components->info('Router collection skipped while the provider is in mock mode.');

            return self::SUCCESS;
        }

        $routers = Router::query()
            ->where('monitoring_enabled', true)
            ->where('api_transport', '!=', 'telemetry')
            ->get();
        foreach ($routers as $router) {
            $lock = Cache::lock("router-metrics:{$router->id}", 70);
            if (! $lock->get()) {
                $this->components->warn("{$router->name}: skipped because another collection is active.");

                continue;
            }

            try {
                $this->collectRouter($router, $service);
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }

    private function collectRouter(Router $router, RouterService $service): void
    {
        $router->forceFill(['last_attempted_at' => now()])->save();

        try {
            $status = $service->getStatus($router->id);
            if ($status === null) {
                throw new RouterApiException(
                    'empty_response',
                    'Router returned no usable status data.',
                );
            }
        } catch (RouterApiException $exception) {
            $router->forceFill([
                'last_error_category' => $exception->category,
                'status_override' => 'offline',
            ])->save();
            Log::notice('Scheduled router status collection failed.', [
                'router_id' => $router->id,
                'category' => $exception->category,
            ]);
            $this->components->warn("{$router->name}: status collection failed ({$exception->category}).");

            return;
        }

        $router->forceFill([
            'last_seen_at' => now(),
            'last_routeros_version' => $status['routerosVersion'] ?? null,
            'last_error_category' => null,
            'status_override' => isset($status['cpuUsage']) && $status['cpuUsage'] > 90
                ? 'degraded'
                : 'online',
        ])->save();

        $traffic = [];
        try {
            $traffic = $service->getTraffic($router->id) ?? [];
        } catch (RouterApiException $exception) {
            Log::notice('Scheduled router traffic collection failed.', [
                'router_id' => $router->id,
                'category' => $exception->category,
            ]);
        }

        RouterMetric::query()->create([
            'id' => (string) Str::uuid(),
            'router_id' => $router->id,
            'cpu_usage' => $status['cpuUsage'] ?? null,
            'memory_usage' => $status['memoryUsage'] ?? null,
            'storage_usage' => $status['storageUsage'] ?? null,
            'temperature' => $status['temperature'] ?? null,
            'rx_rate' => $traffic['currentRxMbps'] ?? null,
            'tx_rate' => $traffic['currentTxMbps'] ?? null,
            'recorded_at' => now(),
        ]);

        RouterMetric::query()
            ->where('router_id', $router->id)
            ->where('recorded_at', '<', now()->subDays(30))
            ->delete();

        $this->components->info("{$router->name}: status and metrics collected.");
    }
}

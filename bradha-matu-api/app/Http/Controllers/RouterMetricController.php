<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Models\RouterMetric;
use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

class RouterMetricController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function index(string $router): JsonResponse
    {
        $routerModel = Router::query()->whereKey($router)->where('monitoring_enabled', true)->first();
        if (! $routerModel) {
            return response()->json(['error' => 'Router not found or monitoring is disabled.'], 404);
        }

        $metrics = RouterMetric::query()
            ->where('router_id', $routerModel->id)
            ->where('recorded_at', '>=', now()->subDay())
            ->orderBy('recorded_at')
            ->get()
            ->map(fn (RouterMetric $metric) => [
                'id' => $metric->id,
                'routerId' => $metric->router_id,
                'cpuUsage' => $metric->cpu_usage,
                'memoryUsage' => $metric->memory_usage,
                'storageUsage' => $metric->storage_usage,
                'temperature' => $metric->temperature,
                'rxRate' => $metric->rx_rate,
                'txRate' => $metric->tx_rate,
                'recordedAt' => $metric->recorded_at->toIso8601String(),
            ]);

        return response()->json($metrics->isNotEmpty() ? $metrics : $this->service->getMetrics($router));
    }
}

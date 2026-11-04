<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Models\RouterMetric;
use App\Services\RouterApiException;
use App\Services\RouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RouterStatusController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function show(string $router): JsonResponse
    {
        $routerModel = Router::query()->whereKey($router)->where('monitoring_enabled', true)->first();
        if (! $routerModel) {
            return response()->json(['error' => 'Router not found or monitoring is disabled.'], 404);
        }

        try {
            $status = $this->service->getStatus($router);
            if ($status !== null) {
                if ($routerModel->api_transport === 'telemetry') {
                    return response()->json($status);
                }

                $routerModel->forceFill([
                    'last_attempted_at' => now(),
                    'last_seen_at' => now(),
                    'last_routeros_version' => $status['routerosVersion'] ?? null,
                    'last_error_category' => null,
                    'status_override' => isset($status['cpuUsage']) && $status['cpuUsage'] > 90
                        ? 'degraded'
                        : 'online',
                ])->save();

                return response()->json($status);
            }

            throw new RouterApiException('empty_response', 'Router returned no usable status data.');
        } catch (RouterApiException $exception) {
            Log::notice('Router status polling failed.', [
                'router_id' => $routerModel->id,
                'category' => $exception->category,
            ]);
            $routerModel->forceFill([
                'last_attempted_at' => now(),
                'last_error_category' => $exception->category,
                'status_override' => 'offline',
            ])->save();

            $metric = RouterMetric::query()
                ->where('router_id', $routerModel->id)
                ->latest('recorded_at')
                ->first();
            if (! $metric && ! $routerModel->gateway_observed_at) {
                return response()->json([
                    'error' => $exception->safeMessage,
                    'category' => $exception->category,
                ], $exception->httpStatus);
            }

            return response()->json([
                'routerId' => $routerModel->id,
                'cpuUsage' => $metric?->cpu_usage,
                'memoryUsage' => $metric?->memory_usage,
                'storageUsage' => $metric?->storage_usage,
                'temperature' => $metric?->temperature,
                'uptimeSeconds' => null,
                'boardModel' => '',
                'architecture' => '',
                'routerosVersion' => $routerModel->last_routeros_version ?? '',
                'serialNumber' => '',
                'systemIdentity' => $routerModel->identity ?? $routerModel->name,
                'macAddresses' => [],
                'ipAddresses' => [],
                'gatewayRoutes' => $routerModel->gateway_observations ?? [],
                'gatewayObservedAt' => $routerModel->gateway_observed_at?->toIso8601String(),
                'lastCommunication' => $routerModel->last_seen_at?->toIso8601String()
                    ?? $routerModel->gateway_observed_at?->toIso8601String()
                    ?? now()->toIso8601String(),
                'connectionLatencyMs' => null,
                'dataSource' => 'stale',
                'staleReason' => $exception->safeMessage,
            ]);
        }
    }
}

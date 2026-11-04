<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\RouterTelemetryService;
use Illuminate\Http\JsonResponse;

class RouterLeaseController extends Controller
{
    public function __construct(private RouterTelemetryService $telemetry) {}

    public function index(string $router): JsonResponse
    {
        $routerModel = Router::query()
            ->whereKey($router)
            ->where('monitoring_enabled', true)
            ->first();
        if (! $routerModel) {
            return response()->json(['error' => 'Router not found or monitoring is disabled.'], 404);
        }
        if ($routerModel->api_transport !== 'telemetry') {
            return response()->json([
                'routerId' => $routerModel->id,
                'observedAt' => null,
                'receivedAt' => null,
                'leases' => [],
            ]);
        }

        return response()->json($this->telemetry->leases($routerModel));
    }
}

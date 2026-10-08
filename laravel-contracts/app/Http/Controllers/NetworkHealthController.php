<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

/**
 * NetworkHealthController — health metrics for all routers.
 * GET /api/network-health
 */
class NetworkHealthController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function index(): JsonResponse
    {
        $routers = $this->service->getRouters();
        $health = [];

        foreach ($routers as $router) {
            $status = $this->service->getStatus($router['id']);
            $health[] = [
                'routerId' => $router['id'],
                'routerName' => $router['name'],
                'location' => $router['location'],
                'status' => $router['status'],
                'cpuUsage' => $status['cpuUsage'] ?? 0,
                'memoryUsage' => $status['memoryUsage'] ?? 0,
                'storageUsage' => $status['storageUsage'] ?? 0,
                'temperature' => $status['temperature'] ?? 0,
                'uptimeSeconds' => $status['uptimeSeconds'] ?? 0,
                'lastCommunication' => $status['lastCommunication'] ?? $router['lastSeenAt'],
            ];
        }

        return response()->json($health);
    }
}

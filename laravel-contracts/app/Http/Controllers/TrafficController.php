<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

/**
 * TrafficController — aggregate traffic across all routers.
 * GET /api/traffic
 */
class TrafficController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function index(): JsonResponse
    {
        $routers = $this->service->getRouters();
        $aggregated = [
            'totalRxBytes' => 0,
            'totalTxBytes' => 0,
            'currentRxMbps' => 0,
            'currentTxMbps' => 0,
            'perRouter' => [],
        ];

        foreach ($routers as $router) {
            if ($router['status'] === 'offline') continue;
            $traffic = $this->service->getTraffic($router['id']);
            if ($traffic) {
                $aggregated['totalRxBytes'] += $traffic['totalRxBytes'];
                $aggregated['totalTxBytes'] += $traffic['totalTxBytes'];
                $aggregated['currentRxMbps'] += $traffic['currentRxMbps'];
                $aggregated['currentTxMbps'] += $traffic['currentTxMbps'];
                $aggregated['perRouter'][] = [
                    'routerId' => $router['id'],
                    'routerName' => $router['name'],
                    'currentRxMbps' => $traffic['currentRxMbps'],
                    'currentTxMbps' => $traffic['currentTxMbps'],
                    'totalRxBytes' => $traffic['totalRxBytes'],
                    'totalTxBytes' => $traffic['totalTxBytes'],
                ];
            }
        }

        return response()->json($aggregated);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

class RouterTrafficController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function show(string $router): JsonResponse
    {
        $traffic = $this->service->getTraffic($router);
        if (!$traffic) {
            return response()->json(['error' => 'Traffic data unavailable'], 404);
        }
        return response()->json($traffic);
    }
}

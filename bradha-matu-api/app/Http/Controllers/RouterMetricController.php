<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

class RouterMetricController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function index(string $router): JsonResponse
    {
        return response()->json(
            $this->service->getMetrics($router)
        );
    }
}

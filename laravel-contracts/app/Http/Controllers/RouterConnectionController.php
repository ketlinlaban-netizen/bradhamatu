<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

/**
 * RouterConnectionController — simulates a read-only session establishment.
 * GET /api/routers/{router}/connect
 *
 * This is NOT a mutation. It probes the router's REST API to verify
 * connectivity and returns a read-only session descriptor. No
 * configuration changes are made.
 */
class RouterConnectionController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function connect(string $router): JsonResponse
    {
        return response()->json(
            $this->service->connect($router)
        );
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

/**
 * RouterConnectionController — performs a one-shot read-only connectivity test.
 * GET /api/routers/{router}/connect
 *
 * The provider opens a short-lived native binary API connection and
 * verifies that identity and system resource reads succeed.
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

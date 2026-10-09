<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

class RouterStatusController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function show(string $router): JsonResponse
    {
        $status = $this->service->getStatus($router);
        if (! $status) {
            return response()->json(['error' => 'Status data unavailable — router may be offline'], 404);
        }

        return response()->json($status);
    }
}

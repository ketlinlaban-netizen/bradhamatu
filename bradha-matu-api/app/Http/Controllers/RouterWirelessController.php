<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

class RouterWirelessController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function show(string $router): JsonResponse
    {
        $wireless = $this->service->getWireless($router);
        if (! $wireless) {
            return response()->json(['error' => 'Wireless data unavailable — router may be offline or has no wireless interfaces'], 404);
        }

        return response()->json($wireless);
    }
}

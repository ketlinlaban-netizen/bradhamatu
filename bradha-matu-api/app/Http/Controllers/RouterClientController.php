<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

class RouterClientController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function index(string $router): JsonResponse
    {
        return response()->json(
            $this->service->getClients($router)
        );
    }
}

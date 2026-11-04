<?php

namespace App\Http\Controllers;

use App\Models\Router as RouterModel;
use App\Services\ClientInventory;
use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

class RouterClientController extends Controller
{
    public function __construct(
        private RouterService $service,
        private ClientInventory $inventory,
    ) {}

    public function index(string $router): JsonResponse
    {
        $routerModel = RouterModel::find($router);
        if (config('mikrotik.provider') === 'mock' || ! $routerModel) {
            return response()->json($this->service->getClients($router));
        }

        return response()->json(
            $this->inventory->forRouter($routerModel)
                ->map(fn ($client) => $this->inventory->present($client))
                ->values()
        );
    }
}

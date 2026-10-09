<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

/**
 * ClientController — all clients across all routers (network-wide).
 * GET /api/clients
 */
class ClientController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function index(): JsonResponse
    {
        $routers = $this->service->getRouters();
        $allClients = [];
        foreach ($routers as $router) {
            if ($router['status'] !== 'offline') {
                $clients = $this->service->getClients($router['id']);
                foreach ($clients as $client) {
                    $allClients[] = array_merge($client, ['routerName' => $router['name']]);
                }
            }
        }

        return response()->json($allClients);
    }
}

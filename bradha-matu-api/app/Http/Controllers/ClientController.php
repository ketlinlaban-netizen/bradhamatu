<?php

namespace App\Http\Controllers;

use App\Models\NetworkClient;
use App\Models\Router;
use App\Services\ClientInventory;
use App\Services\RouterApiException;
use App\Services\RouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * ClientController — all clients across all routers (network-wide).
 * GET /api/clients
 */
class ClientController extends Controller
{
    public function __construct(
        private RouterService $service,
        private ClientInventory $inventory,
    ) {}

    public function index(): JsonResponse
    {
        if (config('mikrotik.provider') === 'mock') {
            $allClients = [];
            foreach ($this->service->getRouters() as $router) {
                if ($router['status'] === 'offline') {
                    continue;
                }

                foreach ($this->service->getClients($router['id']) as $client) {
                    $allClients[] = array_merge($client, [
                        'routerName' => $router['name'],
                        'status' => 'online',
                        'offlineReason' => null,
                    ]);
                }
            }

            return response()->json($allClients);
        }

        return response()->json($this->savedInventory());
    }

    public function refresh(): JsonResponse
    {
        if (config('mikrotik.provider') === 'mock') {
            return $this->index();
        }

        $routers = Router::query()->where('monitoring_enabled', true)->get();
        $routerErrors = [];

        foreach ($routers as $router) {
            try {
                $snapshot = $this->service->getClients($router->id);
                $this->inventory->recordSnapshot($router, $snapshot);
                $router->forceFill([
                    'last_attempted_at' => now(),
                    'last_seen_at' => now(),
                    'last_error_category' => null,
                ])->save();
            } catch (RouterApiException $exception) {
                $routerErrors[$router->id] = $exception->safeMessage;
                $router->forceFill([
                    'last_attempted_at' => now(),
                    'last_error_category' => $exception->category,
                ])->save();
                Log::notice('Client inventory refresh failed for router.', [
                    'router_id' => $router->id,
                    'category' => $exception->category,
                ]);
            }
        }

        return response()->json($this->savedInventory($routerErrors));
    }

    private function savedInventory(array $routerErrors = []): array
    {
        $routerIds = Router::query()
            ->where('monitoring_enabled', true)
            ->pluck('id');

        return NetworkClient::query()
            ->whereIn('router_id', $routerIds)
            ->with('router')
            ->orderByRaw('LOWER(COALESCE(hostname, mac_address))')
            ->get()
            ->map(fn (NetworkClient $client) => $this->inventory->present(
                $client,
                $routerErrors[$client->router_id] ?? null,
            ))
            ->all();
    }
}

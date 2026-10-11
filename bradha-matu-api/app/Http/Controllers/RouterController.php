<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * RouterController — Router inventory and search (READ-ONLY)
 *
 * GET /api/routers          — list all routers
 * GET /api/routers/search   — search routers by name, IP, MAC, location, etc.
 * GET /api/routers/{router} — single router details
 */
class RouterController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function index(Request $request): JsonResponse
    {
        $routers = $this->service->getRouters();

        // Optional status filter
        $status = $request->query('status');
        if ($status && $status !== 'all') {
            $routers = array_filter($routers, fn ($r) => $r['status'] === $status);
        }

        return response()->json(array_values($routers));
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q', '');

        return response()->json(
            $this->service->searchRouters($query)
        );
    }

    public function show(string $routerId): JsonResponse
    {
        $router = $this->service->getRouter($routerId);
        if (! $router) {
            return response()->json(['error' => 'Router not found'], 404);
        }

        return response()->json($router);
    }

    public function dhcpLeases(string $routerId): JsonResponse
    {
        $router = \App\Models\Router::query()
            ->whereKey($routerId)
            ->where('monitoring_enabled', true)
            ->firstOrFail();
        $observedAt = now();
        $source = config('mikrotik.provider') === 'mock' ? 'mock' : 'dhcp';
        $leases = $this->service->getDhcpLeases($routerId);

        return response()->json([
            'routerId' => $router->id,
            'routerName' => $router->name,
            'source' => $source,
            'observedAt' => $observedAt->toIso8601String(),
            'leases' => array_map(function (array $lease) use ($source, $observedAt): array {
                $lease['leaseToken'] = Crypt::encryptString(json_encode([
                    'routerId' => $lease['routerId'],
                    'hostname' => $lease['hostname'],
                    'ipAddress' => $lease['ipAddress'],
                    'macAddress' => $lease['macAddress'],
                    'interfaceName' => $lease['interfaceName'],
                    'leaseStatus' => $lease['leaseStatus'],
                    'source' => $source,
                    'observedAt' => $observedAt->toIso8601String(),
                    'nonce' => Str::uuid()->toString(),
                ], JSON_THROW_ON_ERROR));

                return $lease;
            }, $leases),
        ]);
    }
}

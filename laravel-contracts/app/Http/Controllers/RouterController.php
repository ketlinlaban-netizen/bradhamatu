<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            $routers = array_filter($routers, fn($r) => $r['status'] === $status);
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
        if (!$router) {
            return response()->json(['error' => 'Router not found'], 404);
        }
        return response()->json($router);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * HealthController — lightweight health check
 *
 * GET /api/health — returns 200 if the app is alive.
 * Exposes NO credentials, no infrastructure details, no database
 * connection info. Safe to expose to monitoring tools.
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'provider' => config('mikrotik.provider', 'mock'),
        ]);
    }
}

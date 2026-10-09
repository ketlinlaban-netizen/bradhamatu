<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

/**
 * DashboardController — Network operations dashboard summary
 * GET /api/dashboard
 */
class DashboardController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(
            $this->service->getDashboardSummary()
        );
    }
}

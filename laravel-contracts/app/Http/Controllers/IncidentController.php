<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\JsonResponse;

class IncidentController extends Controller
{
    public function __construct(private RouterService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(
            $this->service->getIncidents()
        );
    }
}

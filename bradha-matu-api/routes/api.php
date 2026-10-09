<?php

/**
 * Community WiFi Bradha Matu — API Routes (READ-ONLY)
 *
 * CRITICAL SECURITY NOTE:
 * There are NO mutation endpoints. No PUT, PATCH, DELETE, or POST
 * routes that modify router state. The absence of these routes IS
 * the security model. Do not add them.
 */

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MikroTikConfigController;
use App\Http\Controllers\NetworkHealthController;
use App\Http\Controllers\RouterClientController;
use App\Http\Controllers\RouterConnectionController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\RouterInterfaceController;
use App\Http\Controllers\RouterMetricController;
use App\Http\Controllers\RouterStatusController;
use App\Http\Controllers\RouterTrafficController;
use App\Http\Controllers\RouterWirelessController;
use App\Http\Controllers\TrafficController;

// ─── Health (no auth, no sensitive data) ───────────────────────────────
Route::get('/health', [HealthController::class, 'index']);

// ─── Auth (rate-limited to prevent brute force) ─────────────────────────
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

// ─── Dashboard ─────────────────────────────────────────────────────────
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth:sanctum');

// ─── Routers (READ-ONLY) ───────────────────────────────────────────────
Route::get('/routers', [RouterController::class, 'index'])->middleware('auth:sanctum');
Route::get('/routers/search', [RouterController::class, 'search'])->middleware('auth:sanctum');
Route::get('/routers/{router}', [RouterController::class, 'show'])->middleware('auth:sanctum');

// ─── Router Sub-Resources (ALL READ-ONLY) ──────────────────────────────
Route::get('/routers/{router}/status', [RouterStatusController::class, 'show'])->middleware('auth:sanctum');
Route::get('/routers/{router}/interfaces', [RouterInterfaceController::class, 'index'])->middleware('auth:sanctum');
Route::get('/routers/{router}/clients', [RouterClientController::class, 'index'])->middleware('auth:sanctum');
Route::get('/routers/{router}/traffic', [RouterTrafficController::class, 'show'])->middleware('auth:sanctum');
Route::get('/routers/{router}/wireless', [RouterWirelessController::class, 'show'])->middleware('auth:sanctum');
Route::get('/routers/{router}/metrics', [RouterMetricController::class, 'index'])->middleware('auth:sanctum');

// ─── Router Connection (Simulated/Mock — returns read-only session) ────
Route::get('/routers/{router}/connect', [RouterConnectionController::class, 'connect'])->middleware('auth:sanctum');

// ─── MikroTik Configurations (add/list/delete/connect) ─────────────────
// These manage stored router credentials in the database. Passwords are
// NEVER returned — only a masked placeholder. The connect endpoint tests
// the binary API connection and returns live read-only system data.
Route::get('/mikrotik-configs', [MikroTikConfigController::class, 'index'])->middleware('auth:sanctum');
Route::post('/mikrotik-configs', [MikroTikConfigController::class, 'store'])->middleware('auth:sanctum');
Route::delete('/mikrotik-configs/{id}', [MikroTikConfigController::class, 'destroy'])->middleware('auth:sanctum');
Route::post('/mikrotik-configs/{id}/connect', [MikroTikConfigController::class, 'connect'])->middleware('auth:sanctum');

// ─── Global Resources ──────────────────────────────────────────────────
Route::get('/clients', [ClientController::class, 'index'])->middleware('auth:sanctum');
Route::get('/traffic', [TrafficController::class, 'index'])->middleware('auth:sanctum');
Route::get('/network-health', [NetworkHealthController::class, 'index'])->middleware('auth:sanctum');
Route::get('/incidents', [IncidentController::class, 'index'])->middleware('auth:sanctum');

// ═══════════════════════════════════════════════════════════════════════
// FORBIDDEN ROUTES — THESE MUST NEVER EXIST:
//
//   PUT    /api/routers/{router}
//   PATCH  /api/routers/{router}
//   DELETE /api/routers/{router}
//   POST   /api/routers/{router}/reboot
//   POST   /api/routers/{router}/command
//   POST   /api/routers/{router}/interface/disable
//   POST   /api/routers/{router}/interface/enable
//   POST   /api/routers/{router}/client/{client}/disconnect
//   POST   /api/routers/{router}/firewall
//   POST   /api/routers/{router}/queue
//   POST   /api/routers/{router}/hotspot
//   POST   /api/routers/{router}/password
//   POST   /api/routers/{router}/firmware
//   POST   /api/routers/{router}/terminal
//   POST   /api/routers
//
// The absence of these endpoints is part of the security model.
// ═══════════════════════════════════════════════════════════════════════

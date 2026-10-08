<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\MikroTikApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * MikroTikConfigController — manage MikroTik router configurations
 *
 * POST   /api/mikrotik-configs        — add a new router config (password stored, never returned)
 * GET    /api/mikrotik-configs        — list all configs (passwords MASKED, never exposed)
 * DELETE /api/mikrotik-configs/{id}   — delete a config
 * POST   /api/mikrotik-configs/{id}/connect — test connection and return live system data
 *
 * SECURITY: The api_password column is in the model's $hidden array.
 * It is NEVER included in any JSON response. The list endpoint returns
 * a masked placeholder ("••••••••") so the UI can show that a password
 * exists without exposing it.
 */
class MikroTikConfigController extends Controller
{
    /**
     * List all router configs with masked passwords.
     */
    public function index(): JsonResponse
    {
        $routers = Router::orderBy('created_at', 'desc')->get();

        $data = $routers->map(fn($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'host' => $r->ip_address,
            'apiPort' => $r->api_port ?? 8728,
            'useTls' => (bool) $r->api_use_tls,
            'username' => $r->api_username,
            'passwordMasked' => $r->api_password ? '••••••••' : null,
            'location' => $r->location,
            'site' => $r->site,
            'isConnected' => (bool) ($r->status_override === 'online'),
            'lastSeenAt' => $r->last_seen_at?->toIso8601String(),
            'createdAt' => $r->created_at?->toIso8601String(),
        ]);

        return response()->json($data);
    }

    /**
     * Add a new router configuration.
     * The password is stored encrypted in the database and never returned.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'apiPort' => 'integer|default:8728|min:1|max:65535',
            'useTls' => 'boolean',
            'username' => 'required|string|max:255',
            'password' => 'required|string|min:1|max:255',
            'location' => 'nullable|string|max:255',
            'site' => 'nullable|string|max:255',
        ]);

        $router = Router::create([
            'id' => Str::uuid()->toString(),
            'name' => $validated['name'],
            'identity' => $validated['name'],
            'ip_address' => $validated['host'],
            'api_port' => $validated['apiPort'] ?? 8728,
            'api_use_tls' => $validated['useTls'] ?? false,
            'api_username' => $validated['username'],
            'api_password' => $validated['password'],
            'location' => $validated['location'] ?? null,
            'site' => $validated['site'] ?? null,
            'use_ssl' => true,
            'verify_cert' => false,
        ]);

        return response()->json([
            'id' => $router->id,
            'name' => $router->name,
            'host' => $router->ip_address,
            'apiPort' => $router->api_port,
            'useTls' => (bool) $router->api_use_tls,
            'username' => $router->api_username,
            'passwordMasked' => '••••••••',
            'location' => $router->location,
            'site' => $router->site,
            'isConnected' => false,
            'createdAt' => $router->created_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Delete a router configuration.
     * This does NOT touch the router itself — only removes the stored config.
     */
    public function destroy(string $id): JsonResponse
    {
        $router = Router::find($id);
        if (!$router) {
            return response()->json(['error' => 'Configuration not found'], 404);
        }

        $router->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Test the connection to a MikroTik router and return live system data.
     *
     * This attempts a binary API connection (TCP 8728/8729). If successful,
     * it fetches /system/resource/print and /system/identity/print to
     * return real router data. If the connection fails, it returns the
     * error message so the UI can display it.
     *
     * This is READ-ONLY: only print commands are sent. No mutations.
     */
    public function connect(string $id): JsonResponse
    {
        $router = Router::find($id);
        if (!$router) {
            return response()->json(['error' => 'Configuration not found'], 404);
        }

        $port = $router->api_port ?? 8728;
        $useTls = (bool) $router->api_use_tls;
        $timeout = (int) config('mikrotik.timeout', 10);

        try {
            $client = new MikroTikApiClient(
                $router->ip_address,
                $port,
                $useTls,
                $timeout
            );
            $client->connect($router->api_username, $router->api_password);

            // Fetch system resource (CPU, memory, uptime, version, etc.)
            $resource = $client->print('/system/resource/print');
            $identity = $client->print('/system/identity/print');
            $client->disconnect();

            // Update last_seen_at
            $router->update([
                'last_seen_at' => now(),
                'status_override' => 'online',
            ]);

            $res = $resource[0] ?? [];
            $idName = $identity[0]['name'] ?? $router->name;

            return response()->json([
                'success' => true,
                'state' => 'established',
                'message' => 'Connection established. READ-ONLY SESSION.',
                'readOnly' => true,
                'router' => [
                    'id' => $router->id,
                    'name' => $router->name,
                    'identity' => $idName,
                    'host' => $router->ip_address,
                    'model' => $res['board-name'] ?? 'Unknown',
                    'serialNumber' => $res['serial-number'] ?? '',
                    'routerosVersion' => $res['version'] ?? 'Unknown',
                    'architecture' => $res['architecture'] ?? '',
                    'cpuLoad' => (int) ($res['cpu-load'] ?? 0),
                    'uptime' => $res['uptime'] ?? '',
                    'totalMemory' => (int) ($res['total-memory'] ?? 0),
                    'freeMemory' => (int) ($res['free-memory'] ?? 0),
                    'totalHdd' => (int) ($res['total-hdd-space'] ?? 0),
                    'freeHdd' => (int) ($res['free-hdd-space'] ?? 0),
                    'cpuCount' => (int) ($res['cpu-count'] ?? 0),
                    'platform' => $res['platform'] ?? '',
                ],
            ]);
        } catch (\Exception $e) {
            // Mark as offline
            $router->update(['status_override' => 'offline']);

            return response()->json([
                'success' => false,
                'state' => 'failed',
                'message' => $e->getMessage(),
                'readOnly' => true,
                'router' => null,
            ], 200);
        }
    }
}

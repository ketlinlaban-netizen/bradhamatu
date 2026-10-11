<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\RouterApiException;
use App\Services\RouterConnectionTester;
use App\Services\RouterDestinationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
    public function __construct(
        private RouterConnectionTester $connectionTester,
        private RouterDestinationPolicy $destinationPolicy,
    ) {}

    /**
     * List all router configs with masked passwords.
     */
    public function index(): JsonResponse
    {
        $routers = Router::orderBy('created_at', 'desc')->get();

        $data = $routers->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'host' => $r->ip_address,
            'apiPort' => $r->api_port ?? 8728,
            'useTls' => (bool) $r->api_use_tls,
            'verifyCert' => (bool) $r->verify_cert,
            'username' => $r->api_username,
            'passwordMasked' => $r->api_password ? '••••••••' : null,
            'location' => $r->location,
            'site' => $r->site,
            'isConnected' => (bool) ($r->status_override === 'online'),
            'lastSeenAt' => $r->last_seen_at?->toIso8601String(),
            'monitoringEnabled' => (bool) $r->monitoring_enabled,
            'lastRouterosVersion' => $r->last_routeros_version,
            'lastErrorCategory' => $r->last_error_category,
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
            'apiPort' => ['sometimes', 'integer', Rule::in(config('mikrotik.allowed_ports', [8728, 8729]))],
            'useTls' => 'boolean',
            'verifyCert' => 'sometimes|accepted',
            'username' => 'required|string|max:255',
            'password' => 'required|string|min:1|max:255',
            'location' => 'nullable|string|max:255',
            'site' => 'nullable|string|max:255',
        ]);

        try {
            $this->destinationPolicy->validateInput($validated['host']);
        } catch (RouterApiException $exception) {
            throw ValidationException::withMessages(['host' => $exception->safeMessage]);
        }

        $useTls = (bool) ($validated['useTls'] ?? true);
        $apiPort = (int) ($validated['apiPort'] ?? ($useTls ? 8729 : 8728));
        if (($apiPort === 8728 && $useTls) || ($apiPort === 8729 && ! $useTls)) {
            throw ValidationException::withMessages([
                'apiPort' => 'Port 8728 is plain Binary API and port 8729 is API-SSL; select a matching TLS mode or a verified custom service port.',
            ]);
        }

        $router = Router::create([
            'id' => Str::uuid()->toString(),
            'name' => $validated['name'],
            'identity' => $validated['name'],
            'ip_address' => $validated['host'],
            'api_port' => $apiPort,
            'api_use_tls' => $useTls,
            'api_username' => $validated['username'],
            'api_password' => $validated['password'],
            'location' => $validated['location'] ?? null,
            'site' => $validated['site'] ?? null,
            'use_ssl' => true,
            'verify_cert' => true,
            'monitoring_enabled' => true,
        ]);

        return response()->json([
            'id' => $router->id,
            'name' => $router->name,
            'host' => $router->ip_address,
            'apiPort' => $router->api_port,
            'useTls' => (bool) $router->api_use_tls,
            'verifyCert' => (bool) $router->verify_cert,
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
        if (! $router) {
            return response()->json(['error' => 'Configuration not found'], 404);
        }

        $router->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Test the connection to a MikroTik router and return live system data.
     *
     * This tests the server-selected live transport and fetches identity and
     * system resource data. If it fails, return a safe error for the UI.
     *
     * This is READ-ONLY: only fixed print/one-shot monitor reads are sent.
     */
    public function connect(string $id): JsonResponse
    {
        $router = Router::find($id);
        if (! $router) {
            return response()->json(['error' => 'Configuration not found'], 404);
        }

        if (! in_array(config('mikrotik.provider'), ['mikrotik', 'rest'], true)) {
            return response()->json([
                'success' => false,
                'state' => 'failed',
                'category' => 'mock_mode',
                'message' => 'Connection tests are disabled while the router provider is in mock mode.',
                'readOnly' => true,
                'router' => null,
            ], 409);
        }

        return response()->json($this->connectionTester->test($router));
    }
}

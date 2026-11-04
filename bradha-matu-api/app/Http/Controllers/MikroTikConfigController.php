<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\RouterApiException;
use App\Services\RouterConnectionTester;
use App\Services\RouterDestinationPolicy;
use App\Services\RouterTelemetryService;
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
        private RouterTelemetryService $telemetry,
    ) {}

    /**
     * List all router configs with masked passwords.
     */
    public function index(): JsonResponse
    {
        $routers = Router::orderBy('created_at', 'desc')->get();
        $staleBefore = now()->subSeconds(max(15, (int) config('mikrotik.telemetry_stale_seconds', 15)));

        $data = $routers->map(function ($r) use ($staleBefore): array {
            $transportConfigured = in_array($r->api_transport, Router::SUPPORTED_API_TRANSPORTS, true);
            $lastSuccessfulCheck = $r->api_transport === 'telemetry'
                ? $r->telemetry_received_at
                : $r->last_seen_at;
            $isFresh = $lastSuccessfulCheck !== null && $lastSuccessfulCheck->gte($staleBefore);
            $healthStatus = ! $transportConfigured
                ? 'misconfigured'
                : ($r->api_transport === 'telemetry'
                    ? ($r->telemetry_received_at === null ? 'unknown' : ($isFresh ? 'online' : 'stale'))
                    : ($r->last_error_category === 'authentication_failed'
                        ? 'unauthorized'
                        : ($r->status_override === 'offline'
                            ? 'offline'
                            : ($isFresh && $r->status_override === 'degraded'
                                ? 'degraded'
                                : ($r->status_override === 'online' && $isFresh ? 'online' : 'stale')))));

            return [
                'id' => $r->id,
                'name' => $r->name,
                'host' => $r->ip_address,
                'apiTransport' => $r->api_transport,
                'transportConfigured' => $transportConfigured,
                'apiPort' => $r->api_port ?? 8728,
                'useTls' => (bool) $r->api_use_tls,
                'verifyCert' => (bool) $r->verify_cert,
                'username' => $r->api_username,
                'passwordMasked' => $r->api_transport === 'telemetry'
                    ? null
                    : ($r->api_password ? '••••••••' : null),
                'location' => $r->location,
                'site' => $r->site,
                'isConnected' => $healthStatus === 'online',
                'healthStatus' => $healthStatus,
                'lastSeenAt' => $r->last_seen_at?->toIso8601String(),
                'lastSuccessfulCheckAt' => $lastSuccessfulCheck?->toIso8601String(),
                'telemetryEnabled' => $r->api_transport === 'telemetry',
                'telemetryObservedAt' => $r->telemetry_observed_at?->toIso8601String(),
                'telemetryReceivedAt' => $r->telemetry_received_at?->toIso8601String(),
                'telemetryCredentialIssued' => $r->telemetry_token_hash !== null,
                'telemetryEndpoint' => $r->api_transport === 'telemetry'
                    ? config('mikrotik.telemetry_endpoint')
                    : null,
                'telemetryStale' => $r->api_transport === 'telemetry'
                    && ($r->telemetry_received_at === null
                        || $r->telemetry_received_at->lt(now()->subSeconds(max(15, (int) config('mikrotik.telemetry_stale_seconds', 15))))),
                'monitoringEnabled' => (bool) $r->monitoring_enabled,
                'lastRouterosVersion' => $r->last_routeros_version,
                'lastErrorCategory' => $r->last_error_category
                    ?? (in_array($r->api_transport, Router::SUPPORTED_API_TRANSPORTS, true) ? null : 'transport_misconfigured'),
                'createdAt' => $r->created_at?->toIso8601String(),
            ];
        });

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
            'routerIdentity' => 'required_if:apiTransport,telemetry|nullable|string|max:128',
            'apiTransport' => ['sometimes', Rule::in(['binary', 'rest', 'telemetry'])],
            'apiPort' => ['sometimes', 'integer', Rule::in(config('mikrotik.allowed_ports', [8728, 8729]))],
            'useTls' => 'boolean',
            'verifyCert' => 'sometimes|accepted',
            'username' => 'required_unless:apiTransport,telemetry|string|max:255',
            'password' => 'required_unless:apiTransport,telemetry|string|min:1|max:255',
            'location' => 'nullable|string|max:255',
            'site' => 'nullable|string|max:255',
        ]);

        try {
            $this->destinationPolicy->validateInput($validated['host']);
        } catch (RouterApiException $exception) {
            throw ValidationException::withMessages(['host' => $exception->safeMessage]);
        }

        $apiTransport = $validated['apiTransport']
            ?? (config('mikrotik.provider') === 'rest' ? 'rest' : 'binary');
        if ($apiTransport === 'telemetry') {
            $apiPort = (int) ($validated['apiPort'] ?? 8729);
            $useTls = true;
        } else {
            $useTls = (bool) ($validated['useTls'] ?? true);
            $apiPort = (int) ($validated['apiPort'] ?? ($useTls ? 8729 : 8728));
        }
        if ($apiTransport === 'binary' && (($apiPort === 8728 && $useTls) || ($apiPort === 8729 && ! $useTls))) {
            throw ValidationException::withMessages([
                'apiPort' => 'Port 8728 is plain Binary API and port 8729 is API-SSL; select a matching TLS mode or a verified custom service port.',
            ]);
        }

        $router = Router::create([
            'id' => Str::uuid()->toString(),
            'name' => $validated['name'],
            'identity' => $validated['routerIdentity'] ?? $validated['name'],
            'ip_address' => $validated['host'],
            'api_transport' => $apiTransport,
            'api_port' => $apiPort,
            'api_use_tls' => $useTls,
            'api_username' => $validated['username'] ?? 'telemetry-only',
            'api_password' => $validated['password'] ?? bin2hex(random_bytes(32)),
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
            'apiTransport' => $router->api_transport,
            'apiPort' => $router->api_port,
            'useTls' => (bool) $router->api_use_tls,
            'verifyCert' => (bool) $router->verify_cert,
            'username' => $router->api_username,
            'passwordMasked' => $apiTransport === 'telemetry' ? null : '••••••••',
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
        if ($router->api_transport === 'telemetry') {
            $telemetryStatus = $this->telemetry->status($router);
            $isFresh = ($telemetryStatus['telemetryStatus'] ?? null) === 'online';

            return response()->json([
                'success' => $isFresh,
                'state' => $isFresh ? 'telemetry' : 'waiting',
                'category' => $isFresh ? null : ($router->telemetry_received_at === null ? 'telemetry_waiting' : 'telemetry_stale'),
                'message' => $isFresh
                    ? 'Recent authenticated telemetry was received; no inbound router API connection was attempted.'
                    : ($router->telemetry_received_at === null
                        ? 'No authenticated router-initiated telemetry has been received yet; status is unknown.'
                        : 'No recent telemetry report was received; reachability is unconfirmed.'),
                'readOnly' => true,
                'router' => null,
                'telemetryReceivedAt' => $router->telemetry_received_at?->toIso8601String(),
            ]);
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

    public function issueTelemetryCredential(string $id): JsonResponse
    {
        $router = Router::query()->whereKey($id)->first();
        if (! $router) {
            return response()->json(['error' => 'Configuration not found'], 404);
        }

        $token = $this->telemetry->issueCredential($router);

        return response()->json([
            'routerId' => $router->id,
            'endpoint' => $this->telemetry->endpoint(),
            'token' => $token,
            'issuedAt' => now()->toIso8601String(),
            'warning' => 'This token is shown once. Store it only in the reviewed RouterOS script and rotate it if exposed.',
        ]);
    }
}

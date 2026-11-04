<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\RouterApiException;
use App\Services\RouterReadTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RouterPppoeController extends Controller
{
    public function __construct(private RouterReadTransport $transport) {}

    public function show(string $router): JsonResponse
    {
        $model = Router::query()->whereKey($router)->where('monitoring_enabled', true)->first();
        if (! $model) {
            return response()->json(['error' => 'Router not found or monitoring is disabled.'], 404);
        }
        if ($model->api_transport === 'telemetry') {
            return response()->json([
                'routerId' => $model->id,
                'observedAt' => $model->telemetry_observed_at?->toIso8601String(),
                'accounts' => [],
                'activeSessions' => [],
                'available' => false,
                'message' => 'PPPoE telemetry is not included in this router report.',
            ]);
        }

        try {
            $accounts = $this->transport->print($model, '/ppp/secret/print', [
                '.proplist' => 'name,service,profile,disabled,local-address,remote-address',
            ]);
            $activeSessions = $this->transport->print($model, '/ppp/active/print', [
                '.proplist' => 'name,service,caller-id,address,uptime,session-id',
            ]);
        } catch (RouterApiException $exception) {
            Log::notice('Router PPPoE read failed.', [
                'router_id' => $model->id,
                'category' => $exception->category,
            ]);

            return response()->json([
                'error' => $exception->safeMessage,
                'category' => $exception->category,
            ], $exception->httpStatus);
        }

        $accounts = array_values(array_filter(array_map(
            fn (array $account): ?array => in_array($account['service'] ?? 'any', ['pppoe', 'any'], true)
                ? [
                    'name' => $account['name'] ?? '',
                    'service' => $account['service'] ?? 'any',
                    'profile' => $account['profile'] ?? '',
                    'disabled' => $this->routerOsFlag($account['disabled'] ?? false),
                    'localAddress' => $account['local-address'] ?? null,
                    'remoteAddress' => $account['remote-address'] ?? null,
                ]
                : null,
            $accounts,
        )));

        $activeSessions = array_values(array_filter(array_map(
            fn (array $session): ?array => ($session['service'] ?? '') === 'pppoe'
                ? [
                    'name' => $session['name'] ?? '',
                    'service' => $session['service'],
                    'callerId' => $session['caller-id'] ?? null,
                    'address' => $session['address'] ?? null,
                    'uptime' => $session['uptime'] ?? null,
                    'sessionId' => $session['session-id'] ?? null,
                ]
                : null,
            $activeSessions,
        )));

        return response()->json([
            'routerId' => $model->id,
            'observedAt' => now()->toIso8601String(),
            'accounts' => $accounts,
            'activeSessions' => $activeSessions,
        ]);
    }

    private function routerOsFlag(mixed $value): bool
    {
        return $value === true || in_array(strtolower((string) $value), ['true', 'yes', '1'], true);
    }
}

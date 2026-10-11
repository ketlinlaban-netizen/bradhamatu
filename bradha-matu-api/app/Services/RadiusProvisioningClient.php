<?php

namespace App\Services;

use App\Models\Router;
use App\Models\ServiceAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class RadiusProvisioningClient
{
    public function isConfigured(): bool
    {
        $baseUrl = (string) config('services.radius_provisioning.url', '');
        $allowedHost = strtolower((string) config('services.radius_provisioning.allowed_host', ''));
        $parts = parse_url($baseUrl);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) !== ''
            && strtolower((string) $parts['host']) === $allowedHost
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && $this->readableFile('client_certificate')
            && $this->readableFile('client_private_key')
            && $this->readableFile('ca_bundle');
    }

    public function provision(ServiceAccount $account, Router $router, array $package, string $password): void
    {
        if (! in_array($account->access_type, ['hotspot', 'pppoe'], true)) {
            throw new RuntimeException('Unsupported network access type.');
        }

        $response = $this->send('PUT', '/v1/subscribers/'.rawurlencode($account->username), [
            'service_account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'purchase_id' => $account->purchase_id,
            'nas' => [
                'id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
            ],
            'credentials' => ['password' => $password],
            'access_type' => $account->access_type,
            'bandwidth_kbps' => [
                'download' => $account->rate_limit_rx_kbps,
                'upload' => $account->rate_limit_tx_kbps,
            ],
            'duration_minutes' => (int) ($package['duration_minutes'] ?? 0),
            'expires_at' => $account->expires_at?->toIso8601String(),
        ]);

        if (($response['status'] ?? null) !== 'active') {
            throw new RuntimeException('Private RADIUS service did not confirm subscriber activation.');
        }
    }

    public function changePassword(ServiceAccount $account, string $password): void
    {
        $response = $this->send('PATCH', '/v1/subscribers/'.rawurlencode($account->username).'/password', [
            'service_account_id' => $account->id,
            'password' => $password,
        ]);

        if (($response['status'] ?? null) !== 'active') {
            throw new RuntimeException('Private RADIUS service did not confirm the password update.');
        }
    }

    public function deactivate(ServiceAccount $account, string $reason): void
    {
        $response = $this->send('DELETE', '/v1/subscribers/'.rawurlencode($account->username), [
            'service_account_id' => $account->id,
            'reason' => Str::limit($reason, 120, ''),
        ]);

        if (($response['status'] ?? null) !== 'deactivated') {
            throw new RuntimeException('Private RADIUS service did not confirm subscriber deactivation.');
        }
    }

    private function send(string $method, string $path, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Private RADIUS provisioning is not configured.');
        }

        $baseUrl = rtrim((string) config('services.radius_provisioning.url'), '/');
        try {
            $privateKey = config('services.radius_provisioning.client_private_key');
            $passphrase = config('services.radius_provisioning.client_private_key_passphrase');
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->withOptions([
                    'allow_redirects' => false,
                    'cert' => config('services.radius_provisioning.client_certificate'),
                    'ssl_key' => filled($passphrase) ? [$privateKey, $passphrase] : $privateKey,
                    'verify' => config('services.radius_provisioning.ca_bundle'),
                ])
                ->withHeaders(['Idempotency-Key' => Str::uuid()->toString()])
                ->send($method, $baseUrl.$path, ['json' => $payload]);
        } catch (ConnectionException) {
            throw new RuntimeException('Private RADIUS provisioning service is unreachable.');
        }

        if (! $response->successful() || strlen($response->body()) > 32768) {
            throw new RuntimeException('Private RADIUS provisioning request failed.');
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('Private RADIUS provisioning service returned an invalid response.');
        }

        return $body;
    }

    private function readableFile(string $key): bool
    {
        $path = config("services.radius_provisioning.{$key}");

        return is_string($path) && $path !== '' && is_file($path) && is_readable($path);
    }
}

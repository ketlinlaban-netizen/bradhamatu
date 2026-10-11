<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HostedGatewayHttp
{
    public function request(string $method, string $url, ?string $token = null, array $options = []): array
    {
        try {
            $client = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withOptions(['allow_redirects' => false, 'verify' => true]);

            if ($token !== null) {
                $client = $client->withToken($token);
            }

            $response = $client->send($method, $url, $options);
        } catch (ConnectionException) {
            throw new RuntimeException('Payment provider is temporarily unavailable.');
        }

        if (strlen($response->body()) > 65536 || ! $response->successful()) {
            throw new RuntimeException('Payment provider request failed.');
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('Payment provider returned an invalid response.');
        }

        return $body;
    }

    public function requireHttpsCheckoutUrl(mixed $url, array $allowedHosts): string
    {
        if (! is_string($url)) {
            throw new RuntimeException('Payment provider returned an invalid checkout URL.');
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (
            ($parts['scheme'] ?? null) !== 'https'
            || ! in_array($host, $allowedHosts, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('Payment provider returned an unapproved checkout URL.');
        }

        return $url;
    }
}

<?php

namespace App\Services;

class RouterDestinationPolicy
{
    /**
     * Resolve the configured management target and pin DNS names to a
     * private IPv4 address for the socket connection.
     *
     * @return array{address: string, peer_name: string}
     */
    public function resolve(string $host): array
    {
        $host = trim($host);
        $allowedHosts = array_map(
            'strtolower',
            array_filter(array_map('trim', explode(',', (string) config('mikrotik.allowed_hosts', '')))),
        );

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (! $this->isPrivateIpv4($host) || ($allowedHosts !== [] && ! in_array(strtolower($host), $allowedHosts, true))) {
                throw $this->invalidHost();
            }

            return ['address' => $host, 'peer_name' => $host];
        }

        if ($allowedHosts === [] || ! in_array(strtolower($host), $allowedHosts, true) || ! $this->isValidHostname($host)) {
            throw $this->invalidHost();
        }

        $addresses = gethostbynamel($host);
        if (! $addresses || count($addresses) === 0) {
            throw new RouterApiException(
                'dns_resolution_failed',
                'The configured router hostname could not be resolved.',
                httpStatus: 422,
            );
        }

        foreach ($addresses as $address) {
            if (! $this->isPrivateIpv4($address)) {
                throw $this->invalidHost();
            }
        }

        return ['address' => $addresses[0], 'peer_name' => $host];
    }

    public function validateInput(string $host): void
    {
        $this->resolve($host);
    }

    private function isPrivateIpv4(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $range) {
            [$network, $prefix] = explode('/', $range);
            $mask = -1 << (32 - (int) $prefix);
            if ((ip2long($address) & $mask) === (ip2long($network) & $mask)) {
                return true;
            }
        }

        return false;
    }

    private function isValidHostname(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*$/', $host) === 1;
    }

    private function invalidHost(): RouterApiException
    {
        return new RouterApiException(
            'destination_not_allowed',
            'Router targets must be approved private management addresses.',
            httpStatus: 422,
        );
    }
}

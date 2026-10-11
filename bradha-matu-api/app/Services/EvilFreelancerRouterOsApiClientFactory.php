<?php

namespace App\Services;

use App\Models\Router;
use RouterOS\Client;
use RouterOS\Exceptions\BadCredentialsException;
use RouterOS\Exceptions\ConnectException;
use Throwable;

class EvilFreelancerRouterOsApiClientFactory implements RouterOsApiClientFactory
{
    public function connect(Router $router, array $destination): RouterOsApiSession
    {
        $useTls = (bool) $router->api_use_tls;

        try {
            $client = new Client([
                'host' => $destination['address'],
                'user' => $router->api_username,
                'pass' => $router->api_password,
                'port' => (int) $router->api_port,
                'ssl' => $useTls,
                'ssl_options' => $this->tlsOptions($destination['peer_name']),
                'legacy' => false,
                'timeout' => (int) config('mikrotik.connect_timeout', 3),
                'socket_timeout' => (int) config('mikrotik.query_timeout', 3),
                'attempts' => 1,
                'delay' => 0,
                'throw_timeout_exception' => true,
            ]);
        } catch (BadCredentialsException $exception) {
            throw new RouterApiException(
                'authentication_failed',
                'RouterOS rejected the monitoring account credentials.',
                $exception,
            );
        } catch (ConnectException $exception) {
            throw $this->connectionException($exception, $useTls);
        } catch (Throwable $exception) {
            throw new RouterApiException(
                $useTls ? 'tls_handshake_failed' : 'protocol_error',
                $useTls
                    ? 'A verified API-SSL connection could not be established.'
                    : 'The RouterOS API session could not be established.',
                $exception,
            );
        }

        return new EvilFreelancerRouterOsApiSession($client);
    }

    /**
     * @return array<string, mixed>
     */
    public function tlsOptions(string $peerName): array
    {
        $options = [
            'ciphers' => 'DEFAULT',
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $peerName,
            'SNI_enabled' => true,
        ];
        $caFile = config('mikrotik.tls_ca_file');

        if (is_string($caFile) && $caFile !== '') {
            $options['cafile'] = $caFile;
        }

        return $options;
    }

    private function connectionException(ConnectException $exception, bool $useTls): RouterApiException
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return new RouterApiException(
                'connection_timeout',
                'The router did not respond before the connection timeout.',
                $exception,
            );
        }

        if ($useTls && (str_contains($message, 'ssl') || str_contains($message, 'certificate'))) {
            return new RouterApiException(
                'tls_validation_failed',
                'The API-SSL certificate or handshake could not be verified.',
                $exception,
            );
        }

        if (str_contains($message, 'refused')) {
            return new RouterApiException(
                'connection_refused',
                'The router refused the API connection. Check the API service and firewall.',
                $exception,
            );
        }

        return new RouterApiException(
            'connection_failed',
            'The router API endpoint could not be reached.',
            $exception,
        );
    }
}

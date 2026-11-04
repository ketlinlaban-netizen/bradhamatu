<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

class RouterOsApiTransport implements RouterReadTransport
{
    public function __construct(
        private RouterOsApiClientFactory $clientFactory,
        private RouterDestinationPolicy $destinationPolicy,
        private RouterReadOnlyGuard $readOnlyGuard,
    ) {}

    /**
     * @return array<int, array<string, string>>
     */
    public function print(Router $router, string $command, array $args = [], array $queries = []): array
    {
        $this->readOnlyGuard->enforceRouterOsCommand($command);

        if (! in_array((int) $router->api_port, (array) config('mikrotik.allowed_ports', [8728, 8729]), true)) {
            throw new RouterApiException(
                'invalid_port',
                'Only explicitly approved RouterOS API ports can be used.',
                httpStatus: 422,
            );
        }

        if ((bool) $router->api_use_tls && ! (bool) $router->verify_cert) {
            throw new RouterApiException(
                'tls_validation_required',
                'API-SSL connections require certificate and hostname verification.',
                httpStatus: 422,
            );
        }

        $destination = $this->destinationPolicy->resolve($router->ip_address);

        try {
            $items = $this->clientFactory
                ->connect($router, $destination)
                ->print($command, $args, $queries);
            Log::debug('RouterOS read query completed.', [
                'router_id' => $router->id,
                'command' => $command,
                'result_count' => count($items),
            ]);

            return $items;
        } catch (RouterApiException $exception) {
            Log::notice('RouterOS read request failed.', [
                'router_id' => $router->id,
                'command' => $command,
                'category' => $exception->category,
            ]);
            throw $exception;
        }
    }

    public function monitor(Router $router, string $command, array $args = []): array
    {
        $this->readOnlyGuard->enforceRouterOsCommand($command);
        $args['once'] = '';

        return $this->print($router, $command, $args);
    }

    /**
     * Exposed for tests to prove network commands are allowlisted.
     *
     * @return list<string>
     */
    public function allowedCommands(): array
    {
        return $this->readOnlyGuard->allowedRouterOsCommands();
    }
}

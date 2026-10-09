<?php

namespace App\Services;

/**
 * RouterReadOnlyGuard
 *
 * Enforces that every operation passing through RouterService is
 * classified as READ. WRITE operations are blocked at the service
 * layer — not merely hidden in the UI.
 *
 * This is a REAL architectural enforcement point. If someone later
 * accidentally calls $service->reboot(), the guard throws before
 * the provider is ever reached.
 */
class ReadOnlyGuardException extends \RuntimeException
{
    public function __construct(string $operation)
    {
        parent::__construct(
            "BLOCKED by RouterReadOnlyGuard: \"{$operation}\" is a WRITE operation. ".
            'This console is strictly read-only. No mutation operations are permitted.'
        );
    }
}

class RouterReadOnlyGuard
{
    private const ALLOWED_OPERATIONS = [
        'getRouters',
        'getRouter',
        'getStatus',
        'getInterfaces',
        'getClients',
        'getTraffic',
        'getWireless',
        'getMetrics',
        'getDashboardSummary',
        'getIncidents',
        'connect',
        'searchRouters',
    ];

    public function classify(string $operation): string
    {
        return in_array($operation, self::ALLOWED_OPERATIONS) ? 'READ' : 'WRITE';
    }

    public function enforce(string $operation): void
    {
        if ($this->classify($operation) === 'WRITE') {
            throw new ReadOnlyGuardException($operation);
        }
    }
}

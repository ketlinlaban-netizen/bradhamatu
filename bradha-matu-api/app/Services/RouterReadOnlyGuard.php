<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

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
        'getDhcpLeases',
        'getTraffic',
        'getWireless',
        'getMetrics',
        'getDashboardSummary',
        'getIncidents',
        'connect',
        'searchRouters',
    ];

    private const ALLOWED_ROUTEROS_COMMANDS = [
        '/system/identity/print',
        '/system/resource/print',
        '/interface/print',
        '/interface/monitor-traffic',
        '/ip/address/print',
        '/ip/dhcp-server/lease/print',
        '/interface/wireless/print',
        '/interface/wireless/registration-table/print',
    ];

    public function classify(string $operation): string
    {
        if (in_array($operation, self::ALLOWED_OPERATIONS, true)) {
            return 'READ';
        }

        if (in_array($operation, self::ALLOWED_ROUTEROS_COMMANDS, true)) {
            return 'READ';
        }

        return 'WRITE';
    }

    public function enforce(string $operation): void
    {
        if ($this->classify($operation) === 'WRITE') {
            throw new ReadOnlyGuardException($operation);
        }
    }

    public function enforceRouterOsCommand(string $command): void
    {
        if (! in_array($command, self::ALLOWED_ROUTEROS_COMMANDS, true)) {
            Log::warning('Blocked unapproved RouterOS command.', [
                'command' => $command,
            ]);
            throw new ReadOnlyGuardException($command);
        }
    }

    /**
     * @return list<string>
     */
    public function allowedRouterOsCommands(): array
    {
        return self::ALLOWED_ROUTEROS_COMMANDS;
    }
}

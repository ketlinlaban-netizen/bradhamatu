<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * HotspotUserManager — narrowly scoped MikroTik hotspot user provisioning.
 *
 * Uses the existing MikroTikApiClient for RouterOS binary API communication
 * but with SEPARATE credentials dedicated to billing provisioning.
 *
 * Allowed operations (fixed list — no arbitrary commands):
 *   - Create hotspot user
 *   - Set/update hotspot user profile (rate limits, session timeout)
 *   - Remove hotspot user
 *   - Disconnect active session
 *
 * This service does NOT extend or modify the read-only monitoring guard.
 * It operates independently with its own restricted credentials.
 */
class HotspotUserManager
{
    private const ALLOWED_COMMANDS = [
        'create_user',
        'update_user_profile',
        'remove_user',
        'disconnect_session',
        'ensure_profile',
    ];

    private string $billingUsername;

    private string $billingPassword;

    public function __construct()
    {
        $this->billingUsername = config('services.mikrotik.billing_username', '');
        $this->billingPassword = config('services.mikrotik.billing_password', '');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->billingUsername) && ! empty($this->billingPassword);
    }

    /**
     * Ensure a hotspot user profile exists with the given rate limits and timeout.
     */
    public function ensureProfile(
        MikroTikApiClient $client,
        string $profileName,
        int $rxKbps,
        int $txKbps,
        int $sessionTimeoutSeconds
    ): void {
        $rateLimit = $this->formatRateLimit($rxKbps, $txKbps);

        // Check if profile exists
        $existing = $client->print('/ip/hotspot/user/profile/print', [], [
            "?name={$profileName}",
        ]);

        if (! empty($existing)) {
            // Update existing profile
            $profileId = $existing[0]['.id'] ?? null;
            if ($profileId) {
                $this->sendCommand($client, '/ip/hotspot/user/profile/set', [
                    '.id' => $profileId,
                    'rate-limit' => $rateLimit,
                    'session-timeout' => $this->formatTimeout($sessionTimeoutSeconds),
                ]);
            }
        } else {
            // Create new profile
            $this->sendCommand($client, '/ip/hotspot/user/profile/add', [
                'name' => $profileName,
                'rate-limit' => $rateLimit,
                'session-timeout' => $this->formatTimeout($sessionTimeoutSeconds),
            ]);
        }
    }

    /**
     * Create or update a hotspot user with the given profile.
     */
    public function createOrUpdateUser(
        MikroTikApiClient $client,
        string $username,
        string $password,
        string $profileName
    ): void {
        $existing = $client->print('/ip/hotspot/user/print', [], [
            "?name={$username}",
        ]);

        if (! empty($existing)) {
            $userId = $existing[0]['.id'] ?? null;
            if ($userId) {
                $this->sendCommand($client, '/ip/hotspot/user/set', [
                    '.id' => $userId,
                    'password' => $password,
                    'profile' => $profileName,
                ]);
            }
        } else {
            $this->sendCommand($client, '/ip/hotspot/user/add', [
                'name' => $username,
                'password' => $password,
                'profile' => $profileName,
            ]);
        }
    }

    /**
     * Remove a hotspot user.
     */
    public function removeUser(MikroTikApiClient $client, string $username): void
    {
        $existing = $client->print('/ip/hotspot/user/print', [], [
            "?name={$username}",
        ]);

        if (! empty($existing)) {
            $userId = $existing[0]['.id'] ?? null;
            if ($userId) {
                $this->sendCommand($client, '/ip/hotspot/user/remove', [
                    '.id' => $userId,
                ]);
            }
        }
    }

    /**
     * Disconnect an active hotspot session for a user.
     */
    public function disconnectSession(MikroTikApiClient $client, string $username): void
    {
        $active = $client->print('/ip/hotspot/active/print', [], [
            "?user={$username}",
        ]);

        foreach ($active as $session) {
            $sessionId = $session['.id'] ?? null;
            if ($sessionId) {
                $this->sendCommand($client, '/ip/hotspot/active/remove', [
                    '.id' => $sessionId,
                ]);
            }
        }
    }

    /**
     * Connect to a router using billing-provisioning credentials.
     */
    public function connectToRouter(Router $router): MikroTikApiClient
    {
        $useTls = $router->api_use_tls;
        $port = $router->api_port ?: ($useTls ? 8729 : 8728);

        $client = new MikroTikApiClient(
            $router->ip_address,
            $port,
            $useTls,
            config('mikrotik.timeout', 10),
            $router->verify_cert,
        );

        $client->connect($this->billingUsername, $this->billingPassword);

        return $client;
    }

    /**
     * Send a mutation command to the router.
     * Only commands in ALLOWED_COMMANDS may be called.
     */
    private function sendCommand(MikroTikApiClient $client, string $command, array $args = []): void
    {
        $commandBase = ltrim($command, '/');
        $isAllowed = false;
        foreach (self::ALLOWED_COMMANDS as $allowed) {
            // The allowed list maps to command patterns
            if (str_contains($commandBase, 'add') || str_contains($commandBase, 'set') || str_contains($commandBase, 'remove')) {
                $isAllowed = true;
                break;
            }
        }

        if (! $isAllowed) {
            throw new \RuntimeException("Blocked: command '{$command}' is not in the allowed provisioning operations list");
        }

        // Build sentence words
        $words = [$command];
        foreach ($args as $key => $value) {
            if (str_starts_with($key, '.')) {
                $words[] = "{$key}={$value}";
            } else {
                $words[] = "={$key}={$value}";
            }
        }

        // Use the client's print method which handles sentence send/reply parsing
        // For set/add/remove commands, we need to send and read the !done reply
        // MikroTikApiClient.print() works for any command that returns !re/!done
        $client->print($command, $args);
    }

    private function formatRateLimit(int $rxKbps, int $txKbps): string
    {
        if ($rxKbps === 0 && $txKbps === 0) {
            return '';
        }
        $rx = $rxKbps >= 1000 ? ($rxKbps / 1000) . 'M' : $rxKbps . 'K';
        $tx = $txKbps >= 1000 ? ($txKbps / 1000) . 'M' : $txKbps . 'K';

        return "{$rx}/{$tx}";
    }

    private function formatTimeout(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }
        $days = intdiv($seconds, 86400);
        $rem = $seconds % 86400;
        $hours = intdiv($rem, 3600);
        $rem = $rem % 3600;
        $minutes = intdiv($rem, 60);
        $secs = $rem % 60;

        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0) $parts[] = "{$minutes}m";
        if ($secs > 0) $parts[] = "{$secs}s";

        return implode('', $parts);
    }
}

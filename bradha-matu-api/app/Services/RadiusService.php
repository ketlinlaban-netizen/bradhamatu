<?php

namespace App\Services;

use App\Models\Nas;
use App\Models\RadCheck;
use App\Models\RadReply;
use App\Models\RadUserGroup;
use App\Models\RadGroupReply;
use App\Models\RadGroupCheck;
use App\Models\RadAcct;
use App\Models\Router;
use App\Models\ServiceAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RadiusService
{
    public function __construct(
        private HotspotUserManager $hotspotManager,
    ) {}

    public function isEnabled(): bool
    {
        return config('radius.enabled', false);
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled();
    }

    // ─── NAS Management ───────────────────────────────────────────────

    public function registerNas(Router $router, ?string $secret = null): Nas
    {
        $secret = $secret ?? config('radius.nas_secret');

        return Nas::updateOrCreate(
            ['nasname' => $router->ip_address],
            [
                'shortname' => $router->name,
                'type' => config('radius.nas_type', 'mikrotik'),
                'ports' => 0,
                'secret' => $secret,
                'description' => "Auto-registered NAS for {$router->name} ({$router->identity})",
            ],
        );
    }

    public function removeNas(Router $router): void
    {
        Nas::where('nasname', $router->ip_address)->delete();
    }

    public function syncAllNas(): int
    {
        if (!config('radius.auto_register_nas', true)) {
            return 0;
        }

        $count = 0;
        foreach (Router::all() as $router) {
            $this->registerNas($router);
            $count++;
        }
        return $count;
    }

    // ─── Group / Profile Management ───────────────────────────────────

    public function ensureGroup(string $groupName, int $rxKbps, int $txKbps, int $sessionTimeoutSec = 0, int $idleTimeoutSec = 0): void
    {
        $rateLimit = $this->formatRateLimit($rxKbps, $txKbps);

        RadGroupReply::updateOrCreate(
            ['groupname' => $groupName, 'attribute' => config('radius.rate_limit_attribute', 'Mikrotik-Rate-Limit')],
            ['op' => ':=', 'value' => $rateLimit],
        );

        if ($sessionTimeoutSec > 0) {
            RadGroupReply::updateOrCreate(
                ['groupname' => $groupName, 'attribute' => 'Session-Timeout'],
                ['op' => ':=', 'value' => (string) $sessionTimeoutSec],
            );
        }

        if ($idleTimeoutSec > 0) {
            RadGroupReply::updateOrCreate(
                ['groupname' => $groupName, 'attribute' => 'Idle-Timeout'],
                ['op' => ':=', 'value' => (string) $idleTimeoutSec],
            );
        }
    }

    public function removeGroup(string $groupName): void
    {
        RadGroupReply::where('groupname', $groupName)->delete();
        RadGroupCheck::where('groupname', $groupName)->delete();
        RadUserGroup::where('groupname', $groupName)->delete();
    }

    // ─── User Provisioning ────────────────────────────────────────────

    public function createUser(string $username, string $password, string $groupName, int $sessionTimeoutSec = 0): void
    {
        DB::transaction(function () use ($username, $password, $groupName, $sessionTimeoutSec) {
            RadCheck::updateOrCreate(
                ['username' => $username, 'attribute' => config('radius.auth_type', 'Cleartext-Password')],
                ['op' => ':=', 'value' => $password],
            );

            if ($sessionTimeoutSec > 0) {
                RadCheck::updateOrCreate(
                    ['username' => $username, 'attribute' => config('radius.session_timeout_attribute', 'Max-All-Session')],
                    ['op' => ':=', 'value' => (string) $sessionTimeoutSec],
                );
            }

            RadUserGroup::updateOrCreate(
                ['username' => $username],
                ['groupname' => $groupName, 'priority' => 0],
            );
        });
    }

    public function updateUserPassword(string $username, string $password): void
    {
        RadCheck::updateOrCreate(
            ['username' => $username, 'attribute' => config('radius.auth_type', 'Cleartext-Password')],
            ['op' => ':=', 'value' => $password],
        );
    }

    public function updateUserGroup(string $username, string $groupName): void
    {
        RadUserGroup::updateOrCreate(
            ['username' => $username],
            ['groupname' => $groupName, 'priority' => 0],
        );
    }

    public function removeUser(string $username): void
    {
        DB::transaction(function () use ($username) {
            RadCheck::where('username', $username)->delete();
            RadReply::where('username', $username)->delete();
            RadUserGroup::where('username', $username)->delete();
        });
    }

    // ─── Session / Accounting ─────────────────────────────────────────

    public function getActiveSessions(): \Illuminate\Database\Eloquent\Collection
    {
        return RadAcct::whereNull('acctstoptime')
            ->orderByDesc('acctstarttime')
            ->get();
    }

    public function getActiveSessionsCount(): int
    {
        return RadAcct::whereNull('acctstoptime')->count();
    }

    public function getUserSessions(string $username, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return RadAcct::where('username', $username)
            ->orderByDesc('acctstarttime')
            ->limit($limit)
            ->get();
    }

    public function disconnectSession(string $username): int
    {
        $sessions = RadAcct::where('username', $username)
            ->whereNull('acctstoptime')
            ->get();

        $count = 0;
        foreach ($sessions as $session) {
            $session->update([
                'acctstoptime' => now(),
                'acctterminatecause' => 'Admin-Reset',
            ]);
            $count++;
        }

        return $count;
    }

    public function getDataUsage(string $username): array
    {
        $result = RadAcct::where('username', $username)
            ->selectRaw('SUM(acctinputoctets) as total_input, SUM(acctoutputoctets) as total_output, COUNT(*) as session_count')
            ->first();

        return [
            'total_input_bytes' => (int) ($result->total_input ?? 0),
            'total_output_bytes' => (int) ($result->total_output ?? 0),
            'total_bytes' => (int) ($result->total_input ?? 0) + (int) ($result->total_output ?? 0),
            'session_count' => (int) ($result->session_count ?? 0),
        ];
    }

    // ─── Integrated Activation/Deactivation ───────────────────────────

    public function provisionServiceAccount(ServiceAccount $account, array $snapshot): void
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('RADIUS is not enabled');
        }

        $rxKbps = $account->rate_limit_rx_kbps;
        $txKbps = $account->rate_limit_tx_kbps;
        $durationMinutes = $snapshot['duration_minutes'] ?? 0;
        $sessionTimeoutSec = $durationMinutes * 60;
        $idleTimeoutSec = (int) config('radius.idle_timeout', 600);

        $groupName = "bm-{$rxKbps}-{$txKbps}-{$durationMinutes}";

        $this->ensureGroup($groupName, $rxKbps, $txKbps, $sessionTimeoutSec, $idleTimeoutSec);
        $this->createUser($account->username, $account->password_hash, $groupName, $sessionTimeoutSec);

        $router = $account->router;
        if ($router) {
            $this->registerNas($router);
        }

        Log::info('RADIUS user provisioned', [
            'username' => $account->username,
            'group' => $groupName,
            'service_account_id' => $account->id,
        ]);
    }

    public function deprovisionServiceAccount(ServiceAccount $account): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->disconnectSession($account->username);
        $this->removeUser($account->username);

        Log::info('RADIUS user deprovisioned', [
            'username' => $account->username,
            'service_account_id' => $account->id,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private function formatRateLimit(int $rxKbps, int $txKbps): string
    {
        $rx = $rxKbps > 0 ? ($rxKbps >= 1024 ? round($rxKbps / 1024, 1) . 'M' : $rxKbps . 'K') : '0';
        $tx = $txKbps > 0 ? ($txKbps >= 1024 ? round($txKbps / 1024, 1) . 'M' : $txKbps . 'K') : '0';
        return "{$rx}/{$tx}";
    }
}

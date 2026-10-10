<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\ServiceAccount;
use App\Models\Router;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ActivationService — orchestrates hotspot provisioning after verified payment.
 *
 * Separate from read-only monitoring. Uses HotspotUserManager with dedicated
 * billing credentials. Idempotent — duplicate activation calls are safe.
 *
 * Workflow:
 * 1. Acquire idempotency lock (DB transaction on service_accounts)
 * 2. Re-check payment success and package eligibility
 * 3. Resolve authorized router
 * 4. Create/update hotspot user via provisioning adapter
 * 5. Apply server-validated rate limits and expiry
 * 6. Store access identifier and expiry
 * 7. Record audit event
 * 8. Mark service active only after confirmed provisioning
 * 9. On failure: preserve payment, mark activation_failed
 */
class ActivationService
{
    public function __construct(
        private HotspotUserManager $hotspotManager
    ) {}

    /**
     * Activate a service account for a verified purchase.
     */
    public function activate(Purchase $purchase): ServiceAccount
    {
        return DB::transaction(function () use ($purchase) {
            $purchase = $purchase->fresh()->lockForUpdate();

            // Re-check payment success
            $verifiedPayment = $purchase->payments()
                ->where('state', 'successful')
                ->lockForUpdate()
                ->first();

            if (! $verifiedPayment) {
                throw new \RuntimeException('Cannot activate: no verified payment');
            }

            // Check for existing service account (idempotency)
            $existing = ServiceAccount::where('purchase_id', $purchase->id)->first();
            if ($existing && $existing->activation_status === ServiceAccount::STATUS_ACTIVE) {
                return $existing;
            }

            $snapshot = $purchase->package_snapshot;
            $customer = $purchase->customer;

            // Resolve router
            $router = $this->resolveRouter($snapshot['router_id'] ?? null);
            if (! $router) {
                $serviceAccount = $this->createFailedServiceAccount($purchase, 'No router available for activation');

                AuditLog::record('system', null, 'activation_failed_no_router', 'purchase', $purchase->id, []);

                return $serviceAccount;
            }

            // Generate credentials
            $username = 'bm-' . Str::lower(Str::random(8));
            $password = Str::random(12);
            $profileName = "bm-{$snapshot['download_limit_kbps']}-{$snapshot['upload_limit_kbps']}-{$snapshot['duration_minutes']}";

            $expiresAt = now()->addMinutes($snapshot['duration_minutes']);

            try {
                if (! $this->hotspotManager->isConfigured()) {
                    // Sandbox mode — create account record but mark as pending
                    $serviceAccount = ServiceAccount::create([
                        'id' => Str::uuid()->toString(),
                        'purchase_id' => $purchase->id,
                        'customer_id' => $customer->id,
                        'router_id' => $router->id,
                        'username' => $username,
                        'password_hash' => bcrypt($password),
                        'access_type' => $snapshot['access_type'] ?? 'hotspot',
                        'rate_limit_rx_kbps' => $snapshot['download_limit_kbps'] ?? 0,
                        'rate_limit_tx_kbps' => $snapshot['upload_limit_kbps'] ?? 0,
                        'activation_status' => ServiceAccount::STATUS_PENDING,
                        'expires_at' => $expiresAt,
                        'deactivation_reason' => 'Hotspot billing credentials not configured — sandbox mode',
                    ]);

                    $purchase->update(['status' => 'activation_pending']);

                    AuditLog::record('system', null, 'activation_sandbox', 'service_account', $serviceAccount->id, [
                        'reason' => 'billing credentials not configured',
                    ]);

                    return $serviceAccount;
                }

                // Real provisioning
                $client = $this->hotspotManager->connectToRouter($router);

                try {
                    $this->hotspotManager->ensureProfile(
                        $client,
                        $profileName,
                        $snapshot['download_limit_kbps'] ?? 0,
                        $snapshot['upload_limit_kbps'] ?? 0,
                        $snapshot['duration_minutes'] * 60,
                    );

                    $this->hotspotManager->createOrUpdateUser($client, $username, $password, $profileName);

                    $serviceAccount = ServiceAccount::create([
                        'id' => Str::uuid()->toString(),
                        'purchase_id' => $purchase->id,
                        'customer_id' => $customer->id,
                        'router_id' => $router->id,
                        'username' => $username,
                        'password_hash' => bcrypt($password),
                        'access_type' => $snapshot['access_type'] ?? 'hotspot',
                        'rate_limit_rx_kbps' => $snapshot['download_limit_kbps'] ?? 0,
                        'rate_limit_tx_kbps' => $snapshot['upload_limit_kbps'] ?? 0,
                        'activation_status' => ServiceAccount::STATUS_ACTIVE,
                        'activated_at' => now(),
                        'expires_at' => $expiresAt,
                    ]);

                    $purchase->update(['status' => 'activated']);

                    AuditLog::record('system', null, 'activation_success', 'service_account', $serviceAccount->id, [
                        'router_id' => $router->id,
                        'username' => $username,
                        'expires_at' => $expiresAt->toIso8601String(),
                    ]);

                    $client->disconnect();

                    return $serviceAccount;
                } catch (\Throwable $e) {
                    $client->disconnect();

                    $serviceAccount = $this->createFailedServiceAccount($purchase, $e->getMessage(), $router->id);

                    AuditLog::record('system', null, 'activation_failed', 'service_account', $serviceAccount->id, [
                        'error' => $e->getMessage(),
                        'router_id' => $router->id,
                    ]);

                    Log::error('Hotspot activation failed', [
                        'purchase_id' => $purchase->id,
                        'error' => $e->getMessage(),
                    ]);

                    return $serviceAccount;
                }
            } catch (\Throwable $e) {
                $serviceAccount = $this->createFailedServiceAccount($purchase, $e->getMessage());

                AuditLog::record('system', null, 'activation_connection_failed', 'service_account', $serviceAccount->id, [
                    'error' => $e->getMessage(),
                ]);

                return $serviceAccount;
            }
        });
    }

    /**
     * Retry a failed activation without charging the customer again.
     */
    public function retryActivation(ServiceAccount $serviceAccount): ServiceAccount
    {
        if ($serviceAccount->activation_status !== ServiceAccount::STATUS_ACTIVATION_FAILED) {
            return $serviceAccount;
        }

        return $this->activate($serviceAccount->purchase);
    }

    /**
     * Deactivate an expired service account.
     */
    public function deactivate(ServiceAccount $serviceAccount, string $reason): void
    {
        DB::transaction(function () use ($serviceAccount, $reason) {
            if ($serviceAccount->activation_status === ServiceAccount::STATUS_EXPIRED
                || $serviceAccount->activation_status === ServiceAccount::STATUS_DEACTIVATED) {
                return;
            }

            if ($this->hotspotManager->isConfigured() && $serviceAccount->router) {
                try {
                    $client = $this->hotspotManager->connectToRouter($serviceAccount->router);
                    $this->hotspotManager->removeUser($client, $serviceAccount->username);
                    $client->disconnect();
                } catch (\Throwable $e) {
                    Log::warning('Failed to remove hotspot user on deactivation', [
                        'service_account_id' => $serviceAccount->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $serviceAccount->update([
                'activation_status' => ServiceAccount::STATUS_EXPIRED,
                'deactivation_reason' => $reason,
            ]);

            $serviceAccount->purchase->update(['status' => 'expired']);

            AuditLog::record('system', null, 'service_deactivated', 'service_account', $serviceAccount->id, [
                'reason' => $reason,
            ]);
        });
    }

    private function resolveRouter(?string $routerId): ?Router
    {
        if ($routerId) {
            return Router::find($routerId);
        }

        return Router::first();
    }

    private function createFailedServiceAccount(Purchase $purchase, string $reason, ?string $routerId = null): ServiceAccount
    {
        $existing = ServiceAccount::where('purchase_id', $purchase->id)->first();
        if ($existing) {
            $existing->update([
                'activation_status' => ServiceAccount::STATUS_ACTIVATION_FAILED,
                'deactivation_reason' => $reason,
            ]);
            $purchase->update(['status' => 'activation_failed']);
            return $existing->fresh();
        }

        $serviceAccount = ServiceAccount::create([
            'id' => Str::uuid()->toString(),
            'purchase_id' => $purchase->id,
            'customer_id' => $purchase->customer_id,
            'router_id' => $routerId,
            'username' => 'bm-failed-' . Str::lower(Str::random(6)),
            'password_hash' => bcrypt(Str::random(12)),
            'access_type' => $purchase->package_snapshot['access_type'] ?? 'hotspot',
            'rate_limit_rx_kbps' => $purchase->package_snapshot['download_limit_kbps'] ?? 0,
            'rate_limit_tx_kbps' => $purchase->package_snapshot['upload_limit_kbps'] ?? 0,
            'activation_status' => ServiceAccount::STATUS_ACTIVATION_FAILED,
            'deactivation_reason' => $reason,
        ]);

        $purchase->update(['status' => 'activation_failed']);
        return $serviceAccount;
    }
}

<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\ServiceAccount;
use App\Models\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ActivationService — orchestrates subscriber provisioning after verified payment.
 *
 * Subscriber write operations are sent only to the private mTLS-protected
 * FreeRADIUS provisioning API. RouterOS monitoring remains read-only.
 *
 * Workflow:
 * 1. Acquire idempotency lock (DB transaction on service_accounts)
 * 2. Re-check payment success and package eligibility
 * 3. Resolve authorized router
 * 4. Submit the server-selected NAS subscriber to the private RADIUS API
 * 5. Apply server-validated rate limits and expiry
 * 6. Store access identifier and expiry
 * 7. Record audit event
 * 8. Mark service active only after confirmed provisioning
 * 9. On failure: preserve payment, mark activation_failed
 */
class ActivationService
{
    public function __construct(
        private RadiusProvisioningClient $radiusProvisioning,
    ) {}

    /**
     * Activate a service account for a verified purchase.
     */
    public function activate(Purchase $purchase): ServiceAccount
    {
        return DB::transaction(function () use ($purchase) {
            $purchase = Purchase::whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

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
            if (
                $existing
                && $existing->activation_status === ServiceAccount::STATUS_ACTIVE
                && $existing->expires_at?->isFuture()
            ) {
                return $existing;
            }
            if (
                $existing
                && $existing->activation_status === ServiceAccount::STATUS_PENDING
                && ! $this->radiusProvisioning->isConfigured()
            ) {
                return $existing;
            }

            $snapshot = $purchase->package_snapshot;
            $customer = $purchase->customer;

            $router = $this->resolveRouter($snapshot['router_id'] ?? $existing?->router_id);
            if (! $router) {
                $serviceAccount = $this->createFailedServiceAccount($purchase, 'No authorized NAS is available for subscriber provisioning.');

                AuditLog::record('system', null, 'activation_failed_no_router', 'purchase', $purchase->id, []);

                return $serviceAccount;
            }

            $durationMinutes = (int) ($snapshot['duration_minutes'] ?? 0);
            if ($durationMinutes < 1) {
                return $this->createFailedServiceAccount($purchase, 'Package duration is invalid.', $router->id);
            }

            $username = $existing?->username ?? ('bm-'.Str::lower(Str::random(10)));
            $password = Str::random(24);
            $expiresAt = now()->addMinutes($durationMinutes);
            $account = $existing ?? new ServiceAccount([
                'id' => Str::uuid()->toString(),
                'purchase_id' => $purchase->id,
                'customer_id' => $customer->id,
            ]);
            $account->fill([
                'router_id' => $router->id,
                'username' => $username,
                'password_hash' => bcrypt($password),
                'access_type' => $snapshot['access_type'] ?? 'hotspot',
                'rate_limit_rx_kbps' => $snapshot['download_limit_kbps'] ?? 0,
                'rate_limit_tx_kbps' => $snapshot['upload_limit_kbps'] ?? 0,
                'activation_status' => ServiceAccount::STATUS_PENDING,
                'activated_at' => null,
                'expires_at' => $expiresAt,
                'deactivation_reason' => null,
            ]);
            $account->save();

            if (! $this->radiusProvisioning->isConfigured()) {
                $account->update([
                    'expires_at' => null,
                    'deactivation_reason' => 'Private FreeRADIUS provisioning API is not configured.',
                ]);
                $purchase->update(['status' => 'activation_pending']);
                AuditLog::record('system', null, 'activation_pending_configuration', 'service_account', $account->id, []);

                return $account->fresh();
            }

            try {
                $this->radiusProvisioning->provision($account, $router, $snapshot, $password);
            } catch (\RuntimeException) {
                $serviceAccount = $this->createFailedServiceAccount(
                    $purchase,
                    'Private FreeRADIUS provisioning failed; retry after checking the private API.',
                    $router->id,
                );
                AuditLog::record('system', null, 'activation_failed', 'service_account', $serviceAccount->id, [
                    'router_id' => $router->id,
                ]);
                Log::error('Private FreeRADIUS provisioning failed', [
                    'purchase_id' => $purchase->id,
                    'service_account_id' => $serviceAccount->id,
                ]);

                return $serviceAccount;
            }

            $account->update([
                'activation_status' => ServiceAccount::STATUS_ACTIVE,
                'activated_at' => now(),
                'expires_at' => $expiresAt,
            ]);
            $purchase->update(['status' => 'activated']);
            AuditLog::record('system', null, 'activation_success', 'service_account', $account->id, [
                'router_id' => $router->id,
                'username' => $username,
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

            return $account->fresh();
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
            $serviceAccount = ServiceAccount::whereKey($serviceAccount->getKey())->lockForUpdate()->firstOrFail();
            if ($serviceAccount->activation_status === ServiceAccount::STATUS_EXPIRED
                || $serviceAccount->activation_status === ServiceAccount::STATUS_DEACTIVATED) {
                return;
            }

            if (! $this->radiusProvisioning->isConfigured()) {
                throw new \RuntimeException('Private FreeRADIUS provisioning API is not configured.');
            }
            $this->radiusProvisioning->deactivate($serviceAccount, $reason);

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

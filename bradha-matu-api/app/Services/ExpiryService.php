<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\ServiceAccount;
use Illuminate\Support\Facades\Log;

/**
 * ExpiryService — scheduled processing for expired packages and stale payments.
 *
 * All operations are idempotent and safe to run multiple times.
 * The server is authoritative for service expiry — never rely on browser timestamps.
 */
class ExpiryService
{
    public function __construct(
        private ActivationService $activationService,
        private PaymentService $paymentService
    ) {}

    /**
     * Deactivate all service accounts that have passed their expiry time.
     */
    public function processExpiredAccounts(): int
    {
        $expired = ServiceAccount::where('activation_status', ServiceAccount::STATUS_ACTIVE)
            ->where('expires_at', '<', now())
            ->get();

        $count = 0;
        foreach ($expired as $account) {
            try {
                $this->activationService->deactivate($account, 'Package expired');
                $count++;
            } catch (\Throwable $e) {
                Log::error('Failed to deactivate expired account', [
                    'service_account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Retry activation for accounts that failed provisioning.
     */
    public function retryFailedActivations(): int
    {
        $failed = ServiceAccount::where('activation_status', ServiceAccount::STATUS_ACTIVATION_FAILED)
            ->whereHas('purchase', function ($q) {
                $q->where('status', 'activation_failed');
            })
            ->where('updated_at', '<', now()->subMinutes(5))
            ->limit(10)
            ->get();

        $count = 0;
        foreach ($failed as $account) {
            try {
                $result = $this->activationService->retryActivation($account);
                if ($result->activation_status === ServiceAccount::STATUS_ACTIVE) {
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::error('Activation retry failed', [
                    'service_account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Reconcile stale pending payments by querying Daraja.
     */
    public function reconcileStalePayments(): int
    {
        $stale = Payment::where('state', 'pending')
            ->where('initiated_at', '<', now()->subMinutes(2))
            ->whereNotNull('provider_checkout_id')
            ->limit(20)
            ->get();

        $count = 0;
        foreach ($stale as $payment) {
            try {
                $this->paymentService->reconcilePayment($payment);
                $count++;
            } catch (\Throwable $e) {
                Log::error('Payment reconciliation failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Activate purchases with verified payments that haven't been activated yet.
     */
    public function processPendingActivations(): int
    {
        $pendingPurchases = \App\Models\Purchase::where('status', 'activation_pending')
            ->limit(10)
            ->get();

        $count = 0;
        foreach ($pendingPurchases as $purchase) {
            try {
                $serviceAccount = $this->activationService->activate($purchase);
                if ($serviceAccount->activation_status === ServiceAccount::STATUS_ACTIVE
                    || $serviceAccount->activation_status === ServiceAccount::STATUS_PENDING) {
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::error('Pending activation failed', [
                    'purchase_id' => $purchase->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}

<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PaymentService — orchestrates the purchase + STK Push workflow.
 *
 * Enforces:
 * - Server-side price reload from trusted DB record
 * - Package snapshots for historical accuracy
 * - Idempotency keys to prevent duplicate charges
 * - Separate payment and activation states
 * - Only verified callback success triggers activation
 */
class PaymentService
{
    public function __construct(
        private DarajaApiClient $daraja
    ) {}

    /**
     * Initiate a purchase and STK Push.
     *
     * @return array{purchase: Purchase, payment: Payment, daraja_response: array|null}
     */
    public function initiatePurchase(Customer $customer, string $packageId): array
    {
        $package = Package::where('id', $packageId)
            ->where('is_enabled', true)
            ->first();

        if (! $package) {
            throw new ModelNotFoundException('Package not found or not available');
        }

        return DB::transaction(function () use ($customer, $package) {
            $orderId = 'ORD-' . strtoupper(Str::random(12));
            $idempotencyKey = 'IDM-' . Str::uuid()->toString();

            $purchase = Purchase::create([
                'id' => Str::uuid()->toString(),
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'package_snapshot' => $package->toSnapshot(),
                'order_id' => $orderId,
                'amount_minor' => $package->price_minor,
                'status' => 'created',
            ]);

            $payment = Payment::create([
                'id' => Str::uuid()->toString(),
                'purchase_id' => $purchase->id,
                'provider' => 'mpesa',
                'state' => 'created',
                'amount_minor' => $package->price_minor,
                'phone_normalized' => $customer->phone_normalized,
                'idempotency_key' => $idempotencyKey,
            ]);

            $purchase->update(['status' => 'initiating']);
            $payment->update(['state' => 'initiating', 'initiated_at' => now()]);

            $darajaResponse = null;

            if ($this->daraja->isConfigured()) {
                try {
                    $darajaResponse = $this->daraja->stkPush(
                        $customer->phone_normalized,
                        $package->price_minor,
                        $orderId,
                        "Package: {$package->name}"
                    );

                    $payment->update([
                        'state' => 'pending',
                        'provider_request_id' => $darajaResponse['request_id'],
                        'provider_checkout_id' => $darajaResponse['checkout_request_id'],
                    ]);

                    $purchase->update(['status' => 'pending']);
                } catch (\RuntimeException $e) {
                    $payment->update([
                        'state' => 'failed',
                        'failure_reason' => $e->getMessage(),
                        'completed_at' => now(),
                    ]);

                    $purchase->update(['status' => 'failed']);

                    AuditLog::record('system', null, 'stk_push_failed', 'payment', $payment->id, [
                        'order_id' => $orderId,
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            } else {
                // Daraja not configured — leave in pending for sandbox/manual testing
                $payment->update(['state' => 'pending', 'failure_reason' => 'Daraja not configured — sandbox mode']);
                $purchase->update(['status' => 'pending']);
            }

            AuditLog::record('customer', $customer->id, 'purchase_initiated', 'purchase', $purchase->id, [
                'package_id' => $package->id,
                'amount_minor' => $package->price_minor,
                'order_id' => $orderId,
            ]);

            return [
                'purchase' => $purchase->fresh(),
                'payment' => $payment->fresh(),
                'daraja_response' => $darajaResponse,
            ];
        });
    }

    /**
     * Process a Daraja callback. Idempotent — duplicate callbacks are safe.
     *
     * @param  array  $callback  Parsed callback body
     * @return Payment|null The payment that was updated, or null if unmatched
     */
    public function processCallback(array $callback): ?Payment
    {
        $stkCallback = $callback['Body']['stkCallback'] ?? null;
        if (! $stkCallback) {
            Log::warning('Daraja callback missing stkCallback', []);
            return null;
        }

        $checkoutRequestId = $stkCallback['CheckoutRequestID'] ?? null;
        $merchantRequestId = $stkCallback['MerchantRequestID'] ?? null;
        $resultCode = $stkCallback['ResultCode'] ?? null;
        $resultDesc = $stkCallback['ResultDesc'] ?? 'Unknown';

        $payment = Payment::where('provider_checkout_id', $checkoutRequestId)->first();
        if (! $payment) {
            Log::warning('Daraja callback unmatched checkout_request_id', [
                'checkout_request_id' => $checkoutRequestId,
            ]);
            return null;
        }

        return DB::transaction(function () use ($payment, $resultCode, $resultDesc, $stkCallback, $checkoutRequestId) {
            // Idempotency: already processed
            if (in_array($payment->state, ['successful', 'failed', 'cancelled', 'timed_out'])) {
                Log::info('Daraja callback duplicate — already processed', [
                    'payment_id' => $payment->id,
                    'state' => $payment->state,
                ]);
                return $payment;
            }

            $isSuccess = (string) $resultCode === '0';

            if ($isSuccess) {
                $amountMinor = $this->extractCallbackAmount($stkCallback);
                if ($amountMinor !== null && $amountMinor !== (int) $payment->amount_minor) {
                    $payment->update([
                        'state' => 'reconciliation_required',
                        'reconciliation_notes' => "Amount mismatch: expected {$payment->amount_minor}, got {$amountMinor}",
                        'sanitized_evidence' => $this->sanitizeCallback($stkCallback),
                    ]);

                    AuditLog::record('system', null, 'payment_amount_mismatch', 'payment', $payment->id, [
                        'expected' => $payment->amount_minor,
                        'received' => $amountMinor,
                    ]);

                    return $payment->fresh();
                }

                $mpesaRef = $this->extractMpesaReference($stkCallback);

                $payment->update([
                    'state' => 'successful',
                    'provider_reference' => $mpesaRef,
                    'completed_at' => now(),
                    'sanitized_evidence' => $this->sanitizeCallback($stkCallback),
                ]);

                $payment->purchase->update([
                    'status' => 'activation_pending',
                    'completed_at' => now(),
                ]);

                AuditLog::record('system', null, 'payment_verified', 'payment', $payment->id, [
                    'order_id' => $payment->purchase->order_id,
                    'amount_minor' => $payment->amount_minor,
                    'mpesa_ref' => $mpesaRef,
                ]);
            } else {
                $state = match ((string) $resultCode) {
                    '1032' => 'cancelled',
                    '1037' => 'timed_out',
                    default => 'failed',
                };

                $payment->update([
                    'state' => $state,
                    'failure_reason' => $resultDesc,
                    'completed_at' => now(),
                ]);

                $payment->purchase->update(['status' => $state === 'cancelled' ? 'cancelled' : 'failed']);

                AuditLog::record('system', null, "payment_{$state}", 'payment', $payment->id, [
                    'result_code' => $resultCode,
                    'result_desc' => $resultDesc,
                ]);
            }

            return $payment->fresh();
        });
    }

    /**
     * Reconcile a pending payment by querying Daraja transaction status.
     */
    public function reconcilePayment(Payment $payment): Payment
    {
        if (! $this->daraja->isConfigured() || ! $payment->provider_checkout_id) {
            return $payment;
        }

        if (! in_array($payment->state, ['pending', 'initiating'])) {
            return $payment;
        }

        $result = $this->daraja->stkQuery($payment->provider_checkout_id);

        if ($result['is_completed']) {
            return DB::transaction(function () use ($payment, $result) {
                if ($result['is_success']) {
                    $payment->update([
                        'state' => 'successful',
                        'completed_at' => now(),
                        'reconciliation_notes' => 'Resolved via STK query',
                    ]);

                    $payment->purchase->update([
                        'status' => 'activation_pending',
                        'completed_at' => now(),
                    ]);

                    AuditLog::record('system', null, 'payment_reconciled_success', 'payment', $payment->id, []);
                } else {
                    $state = match ($result['result_code']) {
                        '1032' => 'cancelled',
                        '1037' => 'timed_out',
                        default => 'failed',
                    };

                    $payment->update([
                        'state' => $state,
                        'failure_reason' => $result['result_desc'],
                        'completed_at' => now(),
                    ]);

                    $payment->purchase->update(['status' => $state]);

                    AuditLog::record('system', null, 'payment_reconciled_fail', 'payment', $payment->id, [
                        'result_code' => $result['result_code'],
                    ]);
                }

                return $payment->fresh();
            });
        }

        return $payment;
    }

    private function extractCallbackAmount(array $stkCallback): ?int
    {
        $items = $stkCallback['CallbackMetadata']['Item'] ?? [];
        foreach ($items as $item) {
            if (($item['Name'] ?? '') === 'Amount') {
                return (int) round(($item['Value'] ?? 0) * 100);
            }
        }
        return null;
    }

    private function extractMpesaReference(array $stkCallback): ?string
    {
        $items = $stkCallback['CallbackMetadata']['Item'] ?? [];
        foreach ($items as $item) {
            if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
                return $item['Value'] ?? null;
            }
        }
        return null;
    }

    private function sanitizeCallback(array $stkCallback): array
    {
        return [
            'result_code' => $stkCallback['ResultCode'] ?? null,
            'result_desc' => $stkCallback['ResultDesc'] ?? null,
            'checkout_request_id' => $stkCallback['CheckoutRequestID'] ?? null,
            'has_metadata' => isset($stkCallback['CallbackMetadata']),
        ];
    }
}

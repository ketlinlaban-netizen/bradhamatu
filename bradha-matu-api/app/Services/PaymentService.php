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
        private DarajaApiClient $daraja,
        private InvoiceService $invoiceService,
        private PesapalGatewayClient $pesapal,
        private FlutterwaveGatewayClient $flutterwave,
        private PaystackGatewayClient $paystack,
    ) {}

    /**
     * Initiate a purchase and STK Push.
     *
     * @return array{purchase: Purchase, payment: Payment, daraja_response: array|null}
     */
    public function availablePaymentMethods(): array
    {
        $methods = [['id' => 'mpesa', 'label' => 'M-Pesa']];
        foreach ([$this->pesapal, $this->flutterwave, $this->paystack] as $gateway) {
            if ($gateway->isConfigured()) {
                $methods[] = ['id' => $gateway->name(), 'label' => match ($gateway->name()) {
                    'pesapal' => 'Pesapal',
                    'flutterwave' => 'Flutterwave',
                    'paystack' => 'Paystack',
                }];
            }
        }

        return $methods;
    }

    public function initiatePurchase(Customer $customer, string $packageId, string $provider = 'mpesa', ?string $checkoutEmail = null): array
    {
        if (! in_array($provider, ['mpesa', 'pesapal', 'flutterwave', 'paystack'], true)) {
            throw new \InvalidArgumentException('Unsupported payment provider.');
        }

        $gateway = $provider === 'mpesa' ? null : $this->hostedGateway($provider);
        if ($gateway && ! $gateway->isConfigured()) {
            throw new \RuntimeException('Selected payment provider is not configured.');
        }

        $package = Package::where('id', $packageId)
            ->where('is_enabled', true)
            ->first();

        if (! $package) {
            throw new ModelNotFoundException('Package not found or not available');
        }

        $quote = $this->invoiceService->quote((int) $package->price_minor);

        return DB::transaction(function () use ($customer, $package, $provider, $gateway, $checkoutEmail, $quote) {
            $orderId = 'ORD-' . strtoupper(Str::random(12));
            $idempotencyKey = 'IDM-' . Str::uuid()->toString();

            $purchase = Purchase::create([
                'id' => Str::uuid()->toString(),
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'package_snapshot' => $package->toSnapshot(),
                'order_id' => $orderId,
                'amount_minor' => $quote['total_minor'],
                'subtotal_minor' => $quote['subtotal_minor'],
                'tax_minor' => $quote['tax_minor'],
                'status' => 'created',
            ]);

            $result = $this->startPaymentAttempt(
                $purchase,
                $customer,
                $package,
                $provider,
                $gateway,
                $checkoutEmail,
                $idempotencyKey,
            );

            AuditLog::record('customer', $customer->id, 'purchase_initiated', 'purchase', $purchase->id, [
                'package_id' => $package->id,
                'amount_minor' => $quote['total_minor'],
                'order_id' => $orderId,
            ]);

            return $result;
        });
    }

    public function initiatePaymentForPurchase(
        Purchase $purchase,
        string $provider,
        string $idempotencyKey,
        ?string $checkoutEmail = null,
    ): array {
        if (! in_array($provider, ['mpesa', 'pesapal', 'flutterwave', 'paystack'], true)) {
            throw new \InvalidArgumentException('Unsupported payment provider.');
        }
        if (in_array($provider, ['flutterwave', 'paystack'], true)
            && (! is_string($checkoutEmail) || filter_var($checkoutEmail, FILTER_VALIDATE_EMAIL) === false)) {
            throw new \InvalidArgumentException('A valid email is required by the selected payment provider.');
        }

        $gateway = $provider === 'mpesa' ? null : $this->hostedGateway($provider);
        if ($gateway && ! $gateway->isConfigured()) {
            throw new \RuntimeException('Selected payment provider is not configured.');
        }

        return DB::transaction(function () use ($purchase, $provider, $idempotencyKey, $checkoutEmail, $gateway): array {
            $lockedPurchase = Purchase::query()
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->with(['customer', 'package'])
                ->firstOrFail();

            $sameRequest = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($sameRequest) {
                if ($sameRequest->purchase_id !== $lockedPurchase->id || $sameRequest->provider !== $provider) {
                    throw new \InvalidArgumentException('This payment request key was already used for different payment details.');
                }

                return [
                    'purchase' => $lockedPurchase,
                    'payment' => $sameRequest,
                    'daraja_response' => null,
                    'checkout_url' => null,
                    'reused' => true,
                ];
            }

            $successful = $lockedPurchase->payments()->where('state', 'successful')->latest()->first();
            if ($successful) {
                return [
                    'purchase' => $lockedPurchase,
                    'payment' => $successful,
                    'daraja_response' => null,
                    'checkout_url' => null,
                    'reused' => true,
                ];
            }

            $inFlight = $lockedPurchase->payments()
                ->whereIn('state', ['created', 'initiating', 'pending', 'reconciliation_required'])
                ->latest()
                ->first();
            if ($inFlight) {
                return [
                    'purchase' => $lockedPurchase,
                    'payment' => $inFlight,
                    'daraja_response' => null,
                    'checkout_url' => null,
                    'reused' => true,
                ];
            }

            return $this->startPaymentAttempt(
                $lockedPurchase,
                $lockedPurchase->customer,
                $lockedPurchase->package,
                $provider,
                $gateway,
                $checkoutEmail,
                $idempotencyKey,
            );
        });
    }

    private function startPaymentAttempt(
        Purchase $purchase,
        Customer $customer,
        Package $package,
        string $provider,
        ?HostedPaymentGateway $gateway,
        ?string $checkoutEmail,
        string $idempotencyKey,
    ): array {
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'purchase_id' => $purchase->id,
            'provider' => $provider,
            'state' => 'created',
            'amount_minor' => $purchase->amount_minor,
            'phone_normalized' => $customer->phone_normalized,
            'idempotency_key' => $idempotencyKey,
        ]);

        $purchase->update(['status' => 'initiating']);
        $payment->update(['state' => 'initiating', 'initiated_at' => now()]);

        $darajaResponse = null;
        $checkoutUrl = null;

        if ($provider !== 'mpesa') {
            $returnUrl = $this->checkoutReturnUrl($payment);
            $checkout = $gateway->initiate($payment, $purchase, $customer, $returnUrl, $checkoutEmail);
            $payment->update([
                'state' => 'pending',
                'provider_reference' => $checkout['reference'],
                'provider_checkout_id' => $checkout['checkout_id'],
            ]);
            $purchase->update(['status' => 'pending']);
            $checkoutUrl = $checkout['checkout_url'];
        } elseif ($this->daraja->isConfigured()) {
            try {
                $darajaResponse = $this->daraja->stkPush(
                    $customer->phone_normalized,
                    (int) $purchase->amount_minor,
                    $purchase->order_id,
                    "Package: {$package->name}"
                );

                $payment->update([
                    'state' => 'pending',
                    'provider_request_id' => $darajaResponse['request_id'],
                    'provider_checkout_id' => $darajaResponse['checkout_request_id'],
                ]);
                $purchase->update(['status' => 'pending']);
            } catch (\RuntimeException $exception) {
                $payment->update([
                    'state' => 'failed',
                    'failure_reason' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);
                $purchase->update(['status' => 'failed']);
                AuditLog::record('system', null, 'stk_push_failed', 'payment', $payment->id, [
                    'order_id' => $purchase->order_id,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        } else {
            $payment->update(['state' => 'pending', 'failure_reason' => 'Daraja not configured — sandbox mode']);
            $purchase->update(['status' => 'pending']);
        }

        return [
            'purchase' => $purchase->fresh(),
            'payment' => $payment->fresh(),
            'daraja_response' => $darajaResponse,
            'checkout_url' => $checkoutUrl,
            'reused' => false,
        ];
    }

    public function processHostedCallback(string $provider, array $callback): ?Payment
    {
        $gateway = $this->hostedGateway($provider);
        $callbackReference = $callback['reference'] ?? '';
        if ($callbackReference === '') {
            throw new \InvalidArgumentException('Missing payment reference.');
        }

        $payment = Payment::where('provider', $provider)
            ->where('provider_reference', $callbackReference)
            ->first();
        if (! $payment) {
            return null;
        }
        if (in_array($payment->state, ['successful', 'failed', 'cancelled', 'timed_out'], true)) {
            return $payment;
        }

        $verification = $gateway->verify($callbackReference, $callback['transaction_id'] ?? null);
        if (! hash_equals($callbackReference, $verification['reference'])) {
            throw new \InvalidArgumentException('Payment reference did not match provider verification.');
        }

        return $this->completeHostedVerification($provider, $verification);
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
                $this->invoiceService->applyInvoiceAndTax($payment->purchase->fresh());

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
     * Reconcile a pending payment through its own provider.
     */
    public function reconcilePayment(Payment $payment): Payment
    {
        if ($payment->provider !== 'mpesa') {
            if (! in_array($payment->state, ['pending', 'initiating', 'reconciliation_required'], true)) {
                return $payment;
            }

            $gateway = $this->hostedGateway($payment->provider);
            $verification = $gateway->verify($payment->provider_reference ?? '', $payment->provider_checkout_id);
            if (! hash_equals((string) $payment->provider_reference, (string) ($verification['reference'] ?? ''))) {
                return $payment;
            }

            return $this->completeHostedVerification($payment->provider, $verification) ?? $payment;
        }

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
                    $this->invoiceService->applyInvoiceAndTax($payment->purchase->fresh());

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

    private function completeHostedVerification(string $provider, array $verification): ?Payment
    {
        $reference = $verification['reference'] ?? '';
        if ($reference === '') {
            return null;
        }

        $payment = Payment::where('provider', $provider)
            ->where('provider_reference', $reference)
            ->with('purchase')
            ->first();
        if (! $payment) {
            return null;
        }
        if (
            $payment->provider_checkout_id
            && ! empty($verification['transaction_id'])
            && ! hash_equals((string) $payment->provider_checkout_id, (string) $verification['transaction_id'])
        ) {
            throw new \InvalidArgumentException('Payment transaction identifier did not match.');
        }

        return DB::transaction(function () use ($payment, $verification, $provider) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->with('purchase')->firstOrFail();
            if (in_array($locked->state, ['successful', 'failed', 'cancelled', 'timed_out'], true)) {
                return $locked;
            }

            $status = strtoupper((string) ($verification['status'] ?? 'UNKNOWN'));
            $successStatus = match ($provider) {
                'pesapal' => 'COMPLETED',
                'flutterwave' => 'SUCCESSFUL',
                'paystack' => 'SUCCESS',
                default => '',
            };

            if ($status === $successStatus) {
                $expectedCurrency = strtoupper((string) config("services.{$provider}.currency", 'KES'));
                if (
                    ($verification['amount_minor'] ?? null) !== (int) $locked->amount_minor
                    || strtoupper((string) ($verification['currency'] ?? '')) !== $expectedCurrency
                ) {
                    $locked->update([
                        'state' => 'reconciliation_required',
                        'reconciliation_notes' => 'Provider amount or currency did not match the purchase.',
                        'sanitized_evidence' => [
                            'provider_status' => $status,
                            'amount_matches' => ($verification['amount_minor'] ?? null) === (int) $locked->amount_minor,
                            'currency_matches' => strtoupper((string) ($verification['currency'] ?? '')) === $expectedCurrency,
                        ],
                    ]);
                    return $locked->fresh();
                }

                $locked->update([
                    'state' => 'successful',
                    'provider_checkout_id' => $verification['transaction_id'] ?? $locked->provider_checkout_id,
                    'completed_at' => now(),
                    'sanitized_evidence' => ['provider_status' => $status],
                ]);
                $locked->purchase->update(['status' => 'activation_pending', 'completed_at' => now()]);
                $this->invoiceService->applyInvoiceAndTax($locked->purchase->fresh());
                AuditLog::record('system', null, 'payment_verified', 'payment', $locked->id, [
                    'provider' => $provider,
                    'order_id' => $locked->purchase->order_id,
                    'amount_minor' => $locked->amount_minor,
                ]);

                return $locked->fresh();
            }

            if (in_array($status, ['FAILED', 'INVALID', 'REVERSED', 'CANCELLED'], true)) {
                $locked->update([
                    'state' => $status === 'CANCELLED' ? 'cancelled' : 'failed',
                    'failure_reason' => 'Provider reported an unsuccessful transaction.',
                    'completed_at' => now(),
                    'sanitized_evidence' => ['provider_status' => $status],
                ]);
                $locked->purchase->update(['status' => $status === 'CANCELLED' ? 'cancelled' : 'failed']);
            }

            return $locked->fresh();
        });
    }

    private function hostedGateway(string $provider): HostedPaymentGateway
    {
        return match ($provider) {
            'pesapal' => $this->pesapal,
            'flutterwave' => $this->flutterwave,
            'paystack' => $this->paystack,
            default => throw new \InvalidArgumentException('Unsupported hosted payment provider.'),
        };
    }

    private function checkoutReturnUrl(Payment $payment): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $parts = parse_url($base);
        $schemes = app()->environment('production') ? ['https'] : ['http', 'https'];
        if (
            ! is_array($parts)
            || ! in_array($parts['scheme'] ?? '', $schemes, true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \RuntimeException('The configured frontend URL is not a valid secure URL.');
        }

        return $base.'/customer/dashboard?payment_id='.rawurlencode($payment->id);
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

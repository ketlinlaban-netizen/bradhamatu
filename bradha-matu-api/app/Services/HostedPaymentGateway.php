<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use Illuminate\Http\Request;

interface HostedPaymentGateway
{
    public function name(): string;

    public function isConfigured(): bool;

    /** @return array{reference: string, checkout_id: ?string, checkout_url: string} */
    public function initiate(Payment $payment, Purchase $purchase, Customer $customer, string $returnUrl, ?string $checkoutEmail = null): array;

    /** @return array{reference: string, transaction_id: ?string}|null */
    public function callbackReference(Request $request): ?array;

    /**
     * @return array{reference: string, transaction_id: ?string, status: string, amount_minor: ?int, currency: string}
     */
    public function verify(string $reference, ?string $transactionId = null): array;
}

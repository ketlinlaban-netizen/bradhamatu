<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PaystackGatewayClient implements HostedPaymentGateway
{
    public function __construct(private HostedGatewayHttp $http) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.paystack.secret_key'));
    }

    public function initiate(Payment $payment, Purchase $purchase, Customer $customer, string $returnUrl, ?string $checkoutEmail = null): array
    {
        $email = $checkoutEmail ?? $customer->email;
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Add a valid email address to use Paystack checkout.');
        }

        $response = $this->http->request('POST', 'https://api.paystack.co/transaction/initialize', config('services.paystack.secret_key'), [
            'json' => [
                'email' => $email,
                'amount' => $payment->amount_minor,
                'currency' => config('services.paystack.currency', 'KES'),
                'reference' => $purchase->order_id,
                'callback_url' => $returnUrl,
                'metadata' => ['purchase_id' => $purchase->id],
            ],
        ]);
        $data = $response['data'] ?? [];
        $checkoutUrl = $this->http->requireHttpsCheckoutUrl($data['authorization_url'] ?? null, ['checkout.paystack.com']);
        $reference = $data['reference'] ?? null;
        if (! is_string($reference) || $reference !== $purchase->order_id) {
            throw new \RuntimeException('Paystack returned an invalid transaction reference.');
        }

        return ['reference' => $reference, 'checkout_id' => null, 'checkout_url' => $checkoutUrl];
    }

    public function callbackReference(Request $request): ?array
    {
        $secret = (string) config('services.paystack.secret_key', '');
        $signature = (string) $request->header('x-paystack-signature', '');
        $body = $request->getContent();
        if ($secret === '' || $signature === '' || strlen($body) > 65536 || ! hash_equals(hash_hmac('sha512', $body, $secret), $signature)) {
            throw new InvalidArgumentException('Invalid Paystack signature.');
        }

        $payload = json_decode($body, true);
        if (! is_array($payload) || ($payload['event'] ?? null) !== 'charge.success') {
            return null;
        }

        $reference = $payload['data']['reference'] ?? null;
        if (! is_string($reference) || $reference === '') {
            return null;
        }

        return ['reference' => $reference, 'transaction_id' => null];
    }

    public function verify(string $reference, ?string $transactionId = null): array
    {
        $result = $this->http->request(
            'GET',
            'https://api.paystack.co/transaction/verify/'.rawurlencode($reference),
            config('services.paystack.secret_key'),
        )['data'] ?? null;
        if (! is_array($result)) {
            throw new \RuntimeException('Paystack returned an invalid verification response.');
        }

        return [
            'reference' => (string) ($result['reference'] ?? ''),
            'transaction_id' => isset($result['id']) ? (string) $result['id'] : null,
            'status' => strtoupper((string) ($result['status'] ?? 'UNKNOWN')),
            'amount_minor' => isset($result['amount']) && is_numeric($result['amount']) ? (int) $result['amount'] : null,
            'currency' => strtoupper((string) ($result['currency'] ?? '')),
        ];
    }
}

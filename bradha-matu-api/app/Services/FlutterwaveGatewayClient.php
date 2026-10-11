<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class FlutterwaveGatewayClient implements HostedPaymentGateway
{
    public function __construct(private HostedGatewayHttp $http) {}

    public function name(): string
    {
        return 'flutterwave';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.flutterwave.secret_key'))
            && filled(config('services.flutterwave.webhook_secret_hash'));
    }

    public function initiate(Payment $payment, Purchase $purchase, Customer $customer, string $returnUrl, ?string $checkoutEmail = null): array
    {
        $email = $checkoutEmail ?? $customer->email;
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Add a valid email address to use Flutterwave checkout.');
        }

        $response = $this->http->request('POST', 'https://api.flutterwave.com/v3/payments', config('services.flutterwave.secret_key'), [
            'json' => [
                'tx_ref' => $purchase->order_id,
                'amount' => round($payment->amount_minor / 100, 2),
                'currency' => config('services.flutterwave.currency', 'KES'),
                'redirect_url' => $returnUrl,
                'customer' => [
                    'email' => $email,
                    'phonenumber' => $customer->phone_normalized,
                    'name' => $customer->full_name,
                ],
                'customizations' => ['title' => 'Bradha Matu WiFi'],
            ],
        ]);

        $checkoutUrl = $this->http->requireHttpsCheckoutUrl(
            $response['data']['link'] ?? null,
            ['checkout.flutterwave.com'],
        );

        return ['reference' => $purchase->order_id, 'checkout_id' => null, 'checkout_url' => $checkoutUrl];
    }

    public function callbackReference(Request $request): ?array
    {
        $expected = (string) config('services.flutterwave.webhook_secret_hash', '');
        $provided = (string) $request->header('verif-hash', '');
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new InvalidArgumentException('Invalid Flutterwave signature.');
        }

        $payload = $request->json()->all();
        $transactionId = $payload['data']['id'] ?? null;
        $reference = $payload['data']['tx_ref'] ?? null;
        if ((! is_string($transactionId) && ! is_int($transactionId)) || ! is_string($reference) || $reference === '') {
            return null;
        }

        return ['reference' => $reference, 'transaction_id' => (string) $transactionId];
    }

    public function verify(string $reference, ?string $transactionId = null): array
    {
        if (! $transactionId || ! ctype_digit($transactionId)) {
            throw new InvalidArgumentException('Invalid Flutterwave transaction identifier.');
        }

        $result = $this->http->request(
            'GET',
            'https://api.flutterwave.com/v3/transactions/'.rawurlencode($transactionId).'/verify',
            config('services.flutterwave.secret_key'),
        )['data'] ?? null;
        if (! is_array($result)) {
            throw new RuntimeException('Flutterwave returned an invalid verification response.');
        }

        $amount = isset($result['amount']) && is_numeric($result['amount'])
            ? (int) round((float) $result['amount'] * 100)
            : null;

        return [
            'reference' => (string) ($result['tx_ref'] ?? ''),
            'transaction_id' => (string) ($result['id'] ?? $transactionId),
            'status' => strtoupper((string) ($result['status'] ?? 'UNKNOWN')),
            'amount_minor' => $amount,
            'currency' => strtoupper((string) ($result['currency'] ?? '')),
        ];
    }
}

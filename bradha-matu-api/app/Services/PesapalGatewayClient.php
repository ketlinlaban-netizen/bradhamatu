<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class PesapalGatewayClient implements HostedPaymentGateway
{
    public function __construct(private HostedGatewayHttp $http) {}

    public function name(): string
    {
        return 'pesapal';
    }

    public function isConfigured(): bool
    {
        return in_array(config('services.pesapal.env'), ['sandbox', 'production'], true)
            && filled(config('services.pesapal.consumer_key'))
            && filled(config('services.pesapal.consumer_secret'))
            && filled(config('services.pesapal.ipn_id'));
    }

    public function initiate(Payment $payment, Purchase $purchase, Customer $customer, string $returnUrl, ?string $checkoutEmail = null): array
    {
        $response = $this->http->request('POST', $this->baseUrl().'/api/Transactions/SubmitOrderRequest', $this->token(), [
            'json' => [
                'id' => $purchase->order_id,
                'currency' => config('services.pesapal.currency', 'KES'),
                'amount' => round($payment->amount_minor / 100, 2),
                'description' => 'Community WiFi package',
                'callback_url' => $returnUrl,
                'notification_id' => config('services.pesapal.ipn_id'),
                'billing_address' => [
                    'email_address' => $checkoutEmail ?? $customer->email,
                    'phone_number' => $customer->phone_normalized,
                    'country_code' => 'KE',
                    'first_name' => mb_substr($customer->full_name, 0, 50),
                ],
            ],
        ]);

        $checkoutUrl = $this->http->requireHttpsCheckoutUrl(
            $response['redirect_url'] ?? null,
            [config('services.pesapal.env') === 'production' ? 'pay.pesapal.com' : 'cybqa.pesapal.com'],
        );
        $trackingId = $response['order_tracking_id'] ?? null;
        $merchantReference = $response['merchant_reference'] ?? null;
        if (! is_string($trackingId) || ! is_string($merchantReference) || $merchantReference !== $purchase->order_id) {
            throw new RuntimeException('Pesapal returned an invalid order response.');
        }

        return ['reference' => $merchantReference, 'checkout_id' => $trackingId, 'checkout_url' => $checkoutUrl];
    }

    public function callbackReference(Request $request): ?array
    {
        $trackingId = $request->query('OrderTrackingId');
        $reference = $request->query('OrderMerchantReference');
        if (! is_string($trackingId) || ! is_string($reference) || $trackingId === '' || $reference === '') {
            throw new InvalidArgumentException('Invalid Pesapal notification.');
        }

        return ['reference' => $reference, 'transaction_id' => $trackingId];
    }

    public function verify(string $reference, ?string $transactionId = null): array
    {
        if (! $transactionId) {
            throw new InvalidArgumentException('Missing Pesapal transaction identifier.');
        }

        $result = $this->http->request(
            'GET',
            $this->baseUrl().'/api/Transactions/GetTransactionStatus?orderTrackingId='.rawurlencode($transactionId),
            $this->token(),
        );

        $status = strtoupper((string) ($result['payment_status_description'] ?? 'UNKNOWN'));
        $amount = isset($result['amount']) && is_numeric($result['amount'])
            ? (int) round((float) $result['amount'] * 100)
            : null;

        return [
            'reference' => (string) ($result['merchant_reference'] ?? ''),
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount_minor' => $amount,
            'currency' => strtoupper((string) ($result['currency'] ?? '')),
        ];
    }

    private function token(): string
    {
        $response = $this->http->request('POST', $this->baseUrl().'/api/Auth/RequestToken', null, [
            'json' => [
                'consumer_key' => config('services.pesapal.consumer_key'),
                'consumer_secret' => config('services.pesapal.consumer_secret'),
            ],
        ]);
        $token = $response['token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Pesapal authentication failed.');
        }

        return $token;
    }

    private function baseUrl(): string
    {
        return config('services.pesapal.env') === 'production'
            ? 'https://pay.pesapal.com/v3'
            : 'https://cybqa.pesapal.com/pesapalv3';
    }
}

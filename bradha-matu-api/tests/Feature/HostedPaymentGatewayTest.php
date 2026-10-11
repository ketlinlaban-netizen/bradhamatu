<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class HostedPaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_paystack_checkout_and_signed_webhook_are_verified_before_success(): void
    {
        config()->set('services.paystack.secret_key', 'test-paystack-secret');
        $orderId = null;
        Http::fake(function (ClientRequest $request) use (&$orderId) {
            if ($request->url() === 'https://api.paystack.co/transaction/initialize') {
                $orderId = $request->data()['reference'];
                return Http::response([
                    'status' => true,
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/test-checkout',
                        'reference' => $orderId,
                    ],
                ]);
            }
            if (str_contains($request->url(), '/transaction/verify/')) {
                return Http::response(['data' => [
                    'reference' => $orderId,
                    'status' => 'success',
                    'amount' => 50000,
                    'currency' => 'KES',
                    'id' => 7701,
                ]]);
            }

            return Http::response([], 404);
        });

        $response = $this->startPurchase('paystack');
        $response->assertOk()
            ->assertJsonPath('payment.provider', 'paystack')
            ->assertJsonPath('checkout_url', 'https://checkout.paystack.com/test-checkout')
            ->assertDontSee('test-paystack-secret');

        $payload = ['event' => 'charge.success', 'data' => ['reference' => $orderId]];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $body, 'test-paystack-secret');
        $this->call('POST', '/api/payment-webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $body)->assertOk();

        $this->assertDatabaseHas('payments', ['provider' => 'paystack', 'provider_reference' => $orderId, 'state' => 'successful']);
        $this->assertDatabaseHas('purchases', ['order_id' => $orderId, 'invoice_number' => 'BM-'.now()->format('Ym').'-0001']);
        Http::assertSentCount(2);
    }

    public function test_flutterwave_webhook_requires_secret_hash_and_provider_verification(): void
    {
        config()->set('services.flutterwave.secret_key', 'test-flw-secret');
        config()->set('services.flutterwave.webhook_secret_hash', 'expected-webhook-hash');
        $orderId = null;
        Http::fake(function (ClientRequest $request) use (&$orderId) {
            if ($request->url() === 'https://api.flutterwave.com/v3/payments') {
                $orderId = $request->data()['tx_ref'];
                return Http::response(['data' => ['link' => 'https://checkout.flutterwave.com/pay/test-checkout']]);
            }
            if ($request->url() === 'https://api.flutterwave.com/v3/transactions/901/verify') {
                return Http::response(['data' => [
                    'id' => 901,
                    'tx_ref' => $orderId,
                    'status' => 'successful',
                    'amount' => 500,
                    'currency' => 'KES',
                ]]);
            }

            return Http::response([], 404);
        });

        $response = $this->startPurchase('flutterwave');
        $response->assertOk()->assertJsonPath('checkout_url', 'https://checkout.flutterwave.com/pay/test-checkout');

        $this->withHeader('verif-hash', 'wrong-hash')
            ->postJson('/api/payment-webhooks/flutterwave', ['data' => ['id' => 901, 'tx_ref' => $orderId]])
            ->assertUnauthorized();
        $this->assertDatabaseHas('payments', ['provider' => 'flutterwave', 'state' => 'pending']);
        Http::assertSentCount(1);

        $this->withHeader('verif-hash', 'expected-webhook-hash')
            ->postJson('/api/payment-webhooks/flutterwave', ['data' => ['id' => 901, 'tx_ref' => $orderId]])
            ->assertOk();
        $this->assertDatabaseHas('payments', ['provider' => 'flutterwave', 'provider_reference' => $orderId, 'state' => 'successful']);
        Http::assertSentCount(2);
    }

    public function test_pesapal_ipn_status_is_checked_with_provider_and_amount_mismatch_needs_reconciliation(): void
    {
        config()->set('services.pesapal.env', 'sandbox');
        config()->set('services.pesapal.consumer_key', 'test-pesapal-key');
        config()->set('services.pesapal.consumer_secret', 'test-pesapal-secret');
        config()->set('services.pesapal.ipn_id', 'registered-ipn-id');
        $orderId = null;
        Http::fake(function (ClientRequest $request) use (&$orderId) {
            if (str_ends_with($request->url(), '/api/Auth/RequestToken')) {
                return Http::response(['token' => 'test-access-token']);
            }
            if (str_ends_with($request->url(), '/api/Transactions/SubmitOrderRequest')) {
                $orderId = $request->data()['id'];
                return Http::response([
                    'redirect_url' => 'https://cybqa.pesapal.com/pesapalv3/iframe?order=test',
                    'order_tracking_id' => 'pesapal-tracking-1',
                    'merchant_reference' => $request->data()['id'],
                ]);
            }
            if (str_contains($request->url(), '/api/Transactions/GetTransactionStatus')) {
                return Http::response([
                    'merchant_reference' => $orderId,
                    'payment_status_description' => 'Completed',
                    'amount' => 499,
                    'currency' => 'KES',
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->startPurchase('pesapal');
        $response->assertOk()->assertJsonPath('payment.provider', 'pesapal');
        $this->getJson('/api/payment-webhooks/pesapal?OrderTrackingId=pesapal-tracking-1&OrderMerchantReference='.$orderId)
            ->assertOk();
        $this->assertDatabaseHas('payments', [
            'provider' => 'pesapal',
            'provider_reference' => $orderId,
            'state' => 'reconciliation_required',
        ]);
    }

    public function test_unconfigured_or_unapproved_gateways_are_not_available_or_used(): void
    {
        config()->set('services.pesapal.consumer_key', '');
        config()->set('services.pesapal.consumer_secret', '');
        config()->set('services.pesapal.ipn_id', '');
        config()->set('services.flutterwave.secret_key', '');
        config()->set('services.flutterwave.webhook_secret_hash', '');
        config()->set('services.paystack.secret_key', '');
        $customer = $this->customer();
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/customer/payment-methods')
            ->assertOk()
            ->assertJsonPath('methods.0.id', 'mpesa')
            ->assertJsonMissing(['id' => 'paystack']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/customer/purchase', ['package_id' => $this->package()->id])
            ->assertOk()
            ->assertJsonPath('payment.provider', 'mpesa')
            ->assertJsonPath('payment.state', 'pending')
            ->assertJsonPath('checkout_url', null);

        \App\Models\AppSetting::set('tax_rate_percent', '16');
        \App\Models\AppSetting::set('tax_inclusive', 'false');
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/customer/packages')
            ->assertOk()
            ->assertJsonPath('packages.0.checkout_total_ksh', 580)
            ->assertJsonPath('packages.0.checkout_tax_ksh', 80);

        $taxedPurchase = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/customer/purchase', ['package_id' => $this->package()->id])
            ->assertOk()
            ->assertJsonPath('purchase.amount_ksh', 580)
            ->assertJsonPath('payment.amount_ksh', 580);
        $this->assertDatabaseHas('purchases', [
            'id' => $taxedPurchase->json('purchase.id'),
            'amount_minor' => 58000,
            'subtotal_minor' => 50000,
            'tax_minor' => 8000,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/customer/purchase', [
                'package_id' => $this->package()->id,
                'provider' => 'https://attacker.example',
            ])
            ->assertUnprocessable();
    }

    public function test_tls_failure_is_sanitized_and_checkout_urls_require_exact_https_provider_hosts(): void
    {
        $http = app(\App\Services\HostedGatewayHttp::class);
        $this->assertSame(
            'https://checkout.paystack.com/session',
            $http->requireHttpsCheckoutUrl('https://checkout.paystack.com/session', ['checkout.paystack.com']),
        );

        try {
            $http->requireHttpsCheckoutUrl('http://checkout.paystack.com/session', ['checkout.paystack.com']);
            $this->fail('Plain HTTP checkout URL should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payment provider returned an unapproved checkout URL.', $exception->getMessage());
        }

        Http::fake(fn () => throw new ConnectionException('certificate verify failed; secret=do-not-leak'));
        try {
            $http->request('GET', 'https://api.paystack.co/transaction/verify/test', 'test-secret');
            $this->fail('The failed TLS connection should not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payment provider is temporarily unavailable.', $exception->getMessage());
            $this->assertStringNotContainsString('do-not-leak', $exception->getMessage());
        }
    }

    private function startPurchase(string $provider)
    {
        $customer = $this->customer();
        if ($provider === 'flutterwave' || $provider === 'paystack') {
            $customer->forceFill(['email' => 'customer@example.test'])->save();
        }

        return $this->actingAs($customer, 'sanctum')
            ->postJson('/api/customer/purchase', [
                'package_id' => $this->package()->id,
                'provider' => $provider,
                'email' => in_array($provider, ['flutterwave', 'paystack'], true) ? $customer->email : null,
            ]);
    }

    private function customer(): Customer
    {
        return Customer::firstOrCreate(['phone_normalized' => '254700123456'], [
            'id' => Str::uuid()->toString(),
            'full_name' => 'Gateway Test',
            'phone_display' => '+254700123456',
            'password' => 'secure-password',
            'email' => null,
            'status' => 'active',
        ]);
    }

    private function package(): Package
    {
        return Package::firstOrCreate(['name' => 'Gateway Test Package'], [
            'id' => Str::uuid()->toString(),
            'price_minor' => 50000,
            'duration_minutes' => 1440,
            'is_enabled' => true,
        ]);
    }
}

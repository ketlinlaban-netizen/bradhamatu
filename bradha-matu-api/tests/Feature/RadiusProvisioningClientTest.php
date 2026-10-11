<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\ServiceAccount;
use App\Services\ActivationService;
use App\Services\RadiusProvisioningClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RadiusProvisioningClientTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureRadiusApi();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_activation_provisions_to_the_private_api_and_never_uses_routeros(): void
    {
        Http::fake(function (ClientRequest $request) {
            $this->assertSame('PUT', $request->method());
            $this->assertStringStartsWith('https://radius-api.internal.test/v1/subscribers/bm-', $request->url());
            $this->assertNotEmpty($request->header('Idempotency-Key'));
            $this->assertSame('hotspot', $request->data()['access_type']);
            $this->assertSame(1024, $request->data()['bandwidth_kbps']['download']);
            $this->assertNotEmpty($request->data()['credentials']['password']);

            return Http::response(['status' => 'active']);
        });

        $router = $this->router();
        $customer = $this->customer();
        $package = Package::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Daily WiFi',
            'price_minor' => 50000,
            'duration_minutes' => 1440,
            'access_type' => 'hotspot',
            'download_limit_kbps' => 1024,
            'upload_limit_kbps' => 512,
            'router_id' => $router->id,
        ]);
        $purchase = $this->verifiedPurchase($customer, $package);

        $serviceAccount = app(ActivationService::class)->activate($purchase);

        $this->assertSame(ServiceAccount::STATUS_ACTIVE, $serviceAccount->activation_status);
        $this->assertSame('activated', $purchase->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_unconfigured_radius_api_leaves_verified_purchase_pending_without_routeros_fallback(): void
    {
        config()->set('services.radius_provisioning.url', '');
        $router = $this->router();
        $customer = $this->customer();
        $package = Package::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Daily WiFi',
            'price_minor' => 50000,
            'duration_minutes' => 1440,
            'access_type' => 'pppoe',
            'router_id' => $router->id,
        ]);
        $purchase = $this->verifiedPurchase($customer, $package);

        $serviceAccount = app(ActivationService::class)->activate($purchase);

        $this->assertSame(ServiceAccount::STATUS_PENDING, $serviceAccount->activation_status);
        $this->assertSame('activation_pending', $purchase->fresh()->status);
        $this->assertNull($serviceAccount->expires_at);
        Http::assertNothingSent();
    }

    public function test_expiry_marks_account_deactivated_only_after_private_api_acknowledges(): void
    {
        Http::fake(function (ClientRequest $request) {
            $this->assertSame('DELETE', $request->method());
            $this->assertStringEndsWith('/v1/subscribers/bm-subscriber', $request->url());

            return Http::response(['status' => 'deactivated']);
        });

        [$account, $purchase] = $this->activeServiceAccount();
        app(ActivationService::class)->deactivate($account, 'Package expired');

        $this->assertSame(ServiceAccount::STATUS_EXPIRED, $account->fresh()->activation_status);
        $this->assertSame('expired', $purchase->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_customer_password_is_changed_only_after_radius_update_succeeds(): void
    {
        Http::fake(function (ClientRequest $request) {
            $this->assertSame('PATCH', $request->method());
            $this->assertStringEndsWith('/v1/subscribers/bm-subscriber/password', $request->url());
            $this->assertSame('new-secure-password', $request->data()['password']);

            return Http::response(['status' => 'active']);
        });
        [$account, , $customer] = $this->activeServiceAccount();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/customer/change-password', [
                'current_password' => 'secure-password',
                'new_password' => 'new-secure-password',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('new-secure-password', $customer->fresh()->password));
        Http::assertSentCount(1);
    }

    public function test_customer_password_is_not_changed_when_radius_is_unconfigured(): void
    {
        config()->set('services.radius_provisioning.url', '');
        [, , $customer] = $this->activeServiceAccount();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/customer/change-password', [
                'current_password' => 'secure-password',
                'new_password' => 'new-secure-password',
            ])
            ->assertStatus(503);

        $this->assertTrue(Hash::check('secure-password', $customer->fresh()->password));
        Http::assertNothingSent();
    }

    public function test_client_requires_https_exact_host_and_readable_mtls_files(): void
    {
        $client = app(RadiusProvisioningClient::class);
        $this->assertTrue($client->isConfigured());

        config()->set('services.radius_provisioning.url', 'http://radius-api.internal.test');
        $this->assertFalse($client->isConfigured());

        config()->set('services.radius_provisioning.url', 'https://attacker.example');
        $this->assertFalse($client->isConfigured());

        config()->set('services.radius_provisioning.url', 'https://radius-api.internal.test');
        config()->set('services.radius_provisioning.client_private_key', '/missing/private-key.pem');
        $this->assertFalse($client->isConfigured());
    }

    public function test_tls_failure_is_rejected_with_a_safe_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('certificate validation failed; private-key=not-for-logs'));
        try {
            app(RadiusProvisioningClient::class)->changePassword(
                new ServiceAccount(['id' => Str::uuid()->toString(), 'username' => 'subscriber-1']),
                'replacement-password',
            );
            $this->fail('Expected TLS failure to stop the operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Private RADIUS provisioning service is unreachable.', $exception->getMessage());
            $this->assertStringNotContainsString('not-for-logs', $exception->getMessage());
        }

    }

    public function test_redirect_response_is_not_followed_or_accepted(): void
    {
        Http::fake(fn () => Http::response('', 302, ['Location' => 'https://attacker.example']));
        try {
            app(RadiusProvisioningClient::class)->changePassword(
                new ServiceAccount(['id' => Str::uuid()->toString(), 'username' => 'subscriber-1']),
                'replacement-password',
            );
            $this->fail('Redirect response must not be followed or accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Private RADIUS provisioning request failed.', $exception->getMessage());
        }
    }

    private function configureRadiusApi(): void
    {
        config()->set('services.radius_provisioning.url', 'https://radius-api.internal.test');
        config()->set('services.radius_provisioning.allowed_host', 'radius-api.internal.test');
        foreach (['client_certificate', 'client_private_key', 'ca_bundle'] as $key) {
            $path = tempnam(sys_get_temp_dir(), 'radius-test-');
            file_put_contents($path, 'test-only-file');
            $this->temporaryFiles[] = $path;
            config()->set("services.radius_provisioning.{$key}", $path);
        }
    }

    private function router(): Router
    {
        return Router::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test NAS',
            'ip_address' => '10.20.0.1',
            'api_password' => 'test-router-password',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'id' => Str::uuid()->toString(),
            'full_name' => 'Subscriber Test',
            'phone_normalized' => '254700234567',
            'phone_display' => '+254700234567',
            'password' => 'secure-password',
            'status' => 'active',
        ]);
    }

    private function verifiedPurchase(Customer $customer, Package $package): Purchase
    {
        $purchase = Purchase::create([
            'id' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'package_snapshot' => $package->toSnapshot(),
            'order_id' => 'ORD-'.Str::upper(Str::random(12)),
            'amount_minor' => $package->price_minor,
            'status' => 'activation_pending',
        ]);
        Payment::create([
            'id' => Str::uuid()->toString(),
            'purchase_id' => $purchase->id,
            'provider' => 'mpesa',
            'state' => 'successful',
            'amount_minor' => $package->price_minor,
            'phone_normalized' => $customer->phone_normalized,
            'idempotency_key' => 'IDM-'.Str::uuid()->toString(),
        ]);

        return $purchase;
    }

    private function activeServiceAccount(): array
    {
        $router = $this->router();
        $customer = $this->customer();
        $package = Package::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Daily WiFi',
            'price_minor' => 50000,
            'duration_minutes' => 1440,
            'access_type' => 'hotspot',
            'router_id' => $router->id,
        ]);
        $purchase = $this->verifiedPurchase($customer, $package);
        $account = ServiceAccount::create([
            'id' => Str::uuid()->toString(),
            'purchase_id' => $purchase->id,
            'customer_id' => $customer->id,
            'router_id' => $router->id,
            'username' => 'bm-subscriber',
            'password_hash' => bcrypt('temporary-password'),
            'access_type' => 'hotspot',
            'activation_status' => ServiceAccount::STATUS_ACTIVE,
            'expires_at' => now()->addDay(),
        ]);

        return [$account, $purchase, $customer];
    }
}

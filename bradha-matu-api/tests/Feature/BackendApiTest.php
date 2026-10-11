<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Purchase;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\InvoiceService;
use App\Services\WalletService;
use Tests\TestCase;

class BackendApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_is_public_and_returns_status(): void
    {
        config()->set('mikrotik.provider', 'mock');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('provider', 'mock');
    }

    public function test_router_endpoints_require_authentication(): void
    {
        $this->get('/api/routers')->assertUnauthorized();
        $this->getJson('/api/routers')->assertUnauthorized();
    }

    public function test_user_can_login_and_access_authenticated_endpoints(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => 'secure-password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'secure-password',
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $this->withToken($response->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('role', 'admin');

        $this->withToken($response->json('token'))
            ->getJson('/api/routers')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_tls_router_configuration_defaults_to_api_ssl_port_and_certificate_verification(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'sanctum')
            ->postJson('/api/mikrotik-configs', [
                'name' => 'Office Router',
                'host' => '192.168.2.10',
                'useTls' => true,
                'username' => 'monitoring',
                'password' => 'router-password',
            ])
            ->assertCreated()
            ->assertJsonPath('apiPort', 8729)
            ->assertJsonPath('useTls', true)
            ->assertJsonPath('verifyCert', true);
    }

    public function test_non_super_admin_cannot_manage_router_inventory_or_test_connections(): void
    {
        $router = \App\Models\Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'Protected Router',
            'ip_address' => '192.168.2.10',
            'api_username' => 'monitoring',
            'api_password' => 'test-router-secret',
            'api_port' => 8729,
            'api_use_tls' => true,
            'verify_cert' => true,
            'monitoring_enabled' => true,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->postJson('/api/mikrotik-configs', [
                'name' => 'Unauthorized Router',
                'host' => '192.168.2.11',
                'username' => 'monitoring',
                'password' => 'test-router-secret',
            ])
            ->assertForbidden();

        $this->deleteJson("/api/mikrotik-configs/{$router->id}")
            ->assertForbidden();

        $this->postJson("/api/mikrotik-configs/{$router->id}/connect")
            ->assertForbidden();
    }

    public function test_general_admin_settings_do_not_expose_or_update_sms_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        \App\Models\AppSetting::set('sms_api_key', 'sensitive-test-key');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('sms_api_key_configured', true)
            ->assertJsonMissingPath('settings.sms_api_key')
            ->assertDontSee('sensitive-test-key');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/settings', [
                'settings' => ['sms_api_key' => 'replacement-key'],
            ])
            ->assertUnprocessable();

        $this->assertSame('sensitive-test-key', \App\Models\AppSetting::get('sms_api_key'));
    }

    public function test_billing_revenue_report_returns_real_daily_series_and_rejects_invalid_periods(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/reports/revenue?period=today')
            ->assertOk()
            ->assertJsonPath('period', 'today')
            ->assertJsonCount(7, 'daily_revenue')
            ->assertJsonPath('daily_revenue.6.date', now()->toDateString())
            ->assertJsonPath('daily_revenue.6.revenue_ksh', 0);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/reports/revenue?period=invalid')
            ->assertUnprocessable();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/service-accounts/activation-failures')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', []);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/vouchers')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_admin_can_create_a_package_and_generate_vouchers_for_it(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $package = $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/packages', [
                'name' => 'Daily WiFi',
                'price_minor' => 5000,
                'duration_minutes' => 1440,
                'access_type' => 'hotspot',
                'is_enabled' => true,
            ])
            ->assertCreated()
            ->json();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/vouchers/generate', [
                'package_id' => $package['id'],
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('count', 2)
            ->assertJsonCount(2, 'vouchers')
            ->assertJsonPath('vouchers.0.status', 'unused');
    }

    public function test_billing_foundation_persists_wallet_balance_and_invoice_tax(): void
    {
        $customer = Customer::create([
            'id' => Str::uuid()->toString(),
            'full_name' => 'Billing Test',
            'phone_normalized' => '254700123456',
            'phone_display' => '+254700123456',
            'password' => 'secure-password',
        ]);

        app(WalletService::class)->topUp($customer, 2500);
        $this->assertSame(2500, $customer->fresh()->balance_minor);
        $this->assertDatabaseHas('wallet_transactions', [
            'customer_id' => $customer->id,
            'amount_minor' => 2500,
            'balance_after_minor' => 2500,
        ]);

        $package = Package::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Daily',
            'price_minor' => 10000,
            'duration_minutes' => 1440,
        ]);
        \App\Models\AppSetting::set('tax_rate_percent', '16');
        \App\Models\AppSetting::set('tax_inclusive', 'false');
        $quote = app(InvoiceService::class)->quote($package->price_minor);
        $this->assertSame(10000, $quote['subtotal_minor']);
        $this->assertSame(1600, $quote['tax_minor']);
        $this->assertSame(11600, $quote['total_minor']);

        $purchase = Purchase::create([
            'id' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'package_snapshot' => $package->toSnapshot(),
            'order_id' => 'TEST-BILLING-001',
            'amount_minor' => $quote['total_minor'],
            'subtotal_minor' => $quote['subtotal_minor'],
            'tax_minor' => $quote['tax_minor'],
            'status' => 'successful',
        ]);

        $invoiced = app(InvoiceService::class)->applyInvoiceAndTax($purchase);

        $this->assertNotEmpty($invoiced->invoice_number);
        $this->assertSame(10000, $invoiced->subtotal_minor);
        $this->assertSame(1600, $invoiced->tax_minor);
        $this->assertSame(11600, $invoiced->amount_minor);
    }
}

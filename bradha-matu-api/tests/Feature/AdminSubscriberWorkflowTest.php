<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\AppSetting;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSubscriberWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_issue_and_idempotently_retrieve_an_invoice_for_an_existing_subscriber(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create([
            'id' => (string) Str::uuid(),
            'account_number' => 'BM-EXISTING01',
            'full_name' => 'Existing Subscriber',
            'phone_normalized' => '+254712345678',
            'phone_display' => '0712345678',
            'password' => 'long-test-password',
            'status' => 'active',
        ]);
        $package = $this->createPackage();
        $payload = [
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'identity_mode' => 'none',
            'billing_period_start_at' => now()->toDateString(),
            'due_at' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/workflow/invoices', $payload)
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('customer.account_number', 'BM-EXISTING01')
            ->assertJsonPath('invoice.total', 10);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/workflow/invoices', array_merge($payload, [
                'idempotency_key' => (string) Str::uuid(),
            ]))
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/workflow/invoices', $payload)
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_subscriber_workflow_requires_an_authenticated_admin(): void
    {
        $this->postJson('/api/admin/workflow/invoices', [])
            ->assertUnauthorized();
    }

    public function test_invoice_quote_uses_the_configured_billing_tax_rules(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $package = $this->createPackage();
        AppSetting::set('tax_rate_percent', '16');
        AppSetting::set('tax_inclusive', 'false');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/workflow/quote', [
                'package_id' => $package->id,
                'billing_period_start_at' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('subtotal_minor', 1000)
            ->assertJsonPath('tax_minor', 160)
            ->assertJsonPath('total_minor', 1160)
            ->assertJsonPath('tax_inclusive', false);
    }

    private function createPackage(): Package
    {
        return Package::create([
            'id' => (string) Str::uuid(),
            'name' => 'Daily access',
            'price_minor' => 1000,
            'duration_minutes' => 1440,
            'access_type' => 'hotspot',
            'is_enabled' => true,
        ]);
    }
}

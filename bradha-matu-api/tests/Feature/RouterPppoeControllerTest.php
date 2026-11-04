<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterPppoeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_pppoe_endpoint_returns_account_and_session_inventory_without_passwords(): void
    {
        config()->set('mikrotik.provider', 'rest');
        config()->set('mikrotik.rest_port', 443);
        config()->set('mikrotik.rest_allowed_ports', [443]);
        config()->set('mikrotik.allowed_hosts', '');

        $router = Router::create([
            'id' => (string) Str::uuid(),
            'name' => 'PPPoE router',
            'ip_address' => '192.168.88.1',
            'api_transport' => 'rest',
            'api_username' => 'monitor',
            'api_password' => 'private-password',
            'monitoring_enabled' => true,
        ]);

        Http::fake(fn (Request $request) => str_contains($request->url(), '/ppp/secret?')
            ? Http::response([
                ['name' => 'subscriber-a', 'service' => 'pppoe', 'profile' => 'home', 'disabled' => false, 'password' => 'must-not-be-returned'],
                ['name' => 'pptp-user', 'service' => 'pptp', 'profile' => 'legacy'],
            ])
            : Http::response([
                ['name' => 'subscriber-a', 'service' => 'pppoe', 'caller-id' => 'AA:BB:CC:DD:EE:FF', 'address' => '10.0.0.2', 'uptime' => '1h', 'session-id' => '*1'],
                ['name' => 'pptp-user', 'service' => 'pptp', 'address' => '10.0.0.3'],
            ]));

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/routers/{$router->id}/pppoe")
            ->assertOk()
            ->assertJsonCount(1, 'accounts')
            ->assertJsonPath('accounts.0.name', 'subscriber-a')
            ->assertJsonPath('accounts.0.disabled', false)
            ->assertJsonCount(1, 'activeSessions')
            ->assertJsonPath('activeSessions.0.callerId', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonMissingPath('accounts.0.password');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/ppp/secret?')
            && ! str_contains($request->url(), 'password'));
    }
}

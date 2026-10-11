<?php

/**
 * Community WiFi Bradha Matu — API Routes (READ-ONLY)
 *
 * CRITICAL SECURITY NOTE:
 * There are NO mutation endpoints. No PUT, PATCH, DELETE, or POST
 * routes that modify router state. The absence of these routes IS
 * the security model. Do not add them.
 */

use App\Http\Controllers\AdminBillingController;
use App\Http\Controllers\AdminSubscriberWorkflowController;
use App\Http\Controllers\AppSettingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientAliasController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerDashboardController;
use App\Http\Controllers\CustomerPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MikroTikConfigController;
use App\Http\Controllers\NetworkHealthController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\RouterClientController;
use App\Http\Controllers\RouterConnectionController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\RouterInterfaceController;
use App\Http\Controllers\RouterMetricController;
use App\Http\Controllers\RouterStatusController;
use App\Http\Controllers\RouterTrafficController;
use App\Http\Controllers\RouterWirelessController;
use App\Http\Controllers\RadiusController;
use App\Http\Controllers\SmsTemplateController;
use App\Http\Controllers\TrafficController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\WalletController;

// ─── Health (no auth, no sensitive data) ───────────────────────────────
Route::get('/health', [HealthController::class, 'index']);

// ─── Auth (rate-limited to prevent brute force) ─────────────────────────
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/auth/me', [AuthController::class, 'me'])->middleware(['auth:sanctum', 'admin.user']);

// ─── Dashboard ─────────────────────────────────────────────────────────
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth:sanctum', 'admin.user']);

// ─── Routers (READ-ONLY) ───────────────────────────────────────────────
Route::get('/routers', [RouterController::class, 'index'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/routers/search', [RouterController::class, 'search'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/routers/{router}', [RouterController::class, 'show'])->middleware(['auth:sanctum', 'admin.user']);

// ─── Router Sub-Resources (ALL READ-ONLY) ──────────────────────────────
Route::get('/routers/{router}/status', [RouterStatusController::class, 'show'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/routers/{router}/interfaces', [RouterInterfaceController::class, 'index'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/routers/{router}/clients', [RouterClientController::class, 'index'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/routers/{router}/dhcp-leases', [RouterController::class, 'dhcpLeases'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/routers/{router}/traffic', [RouterTrafficController::class, 'show'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/routers/{router}/wireless', [RouterWirelessController::class, 'show'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/routers/{router}/metrics', [RouterMetricController::class, 'index'])->middleware(['auth:sanctum', 'admin.user']);

// ─── Router Connection (Simulated/Mock — returns read-only session) ────
Route::get('/routers/{router}/connect', [RouterConnectionController::class, 'connect'])->middleware(['auth:sanctum', 'admin.user']);

// ─── MikroTik Configurations (add/list/delete/connect) ─────────────────
// These manage stored router credentials in the database. Passwords are
// NEVER returned — only a masked placeholder. The connect endpoint tests
// the binary API connection and returns live read-only system data.
Route::get('/mikrotik-configs', [MikroTikConfigController::class, 'index'])->middleware(['auth:sanctum', 'admin.user']);
Route::post('/mikrotik-configs', [MikroTikConfigController::class, 'store'])
    ->middleware(['auth:sanctum', 'admin.user', 'can:manage-router-inventory']);
Route::delete('/mikrotik-configs/{id}', [MikroTikConfigController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'admin.user', 'can:manage-router-inventory']);
Route::post('/mikrotik-configs/{id}/connect', [MikroTikConfigController::class, 'connect'])
    ->middleware(['auth:sanctum', 'admin.user', 'can:manage-router-inventory']);

// ─── Global Resources ──────────────────────────────────────────────────
Route::get('/clients', [ClientController::class, 'index'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/clients/refresh', [ClientController::class, 'refresh'])->middleware(['auth:sanctum', 'admin.user']);
Route::patch('/routers/{router}/clients/{client}/alias', [ClientAliasController::class, 'update'])
    ->middleware(['auth:sanctum', 'admin.user', 'can:manage-device-aliases']);
Route::delete('/routers/{router}/clients/{client}/alias', [ClientAliasController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'admin.user', 'can:manage-device-aliases']);
Route::get('/traffic', [TrafficController::class, 'index'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/network-health', [NetworkHealthController::class, 'index'])->middleware(['auth:sanctum', 'admin.user']);
Route::get('/incidents', [IncidentController::class, 'index'])->middleware(['auth:sanctum', 'admin.user']);

// ═══════════════════════════════════════════════════════════════════════
// FORBIDDEN ROUTES — THESE MUST NEVER EXIST:
//
//   PUT    /api/routers/{router}
//   PATCH  /api/routers/{router}
//   DELETE /api/routers/{router}
//   POST   /api/routers/{router}/reboot
//   POST   /api/routers/{router}/command
//   POST   /api/routers/{router}/interface/disable
//   POST   /api/routers/{router}/interface/enable
//   POST   /api/routers/{router}/client/{client}/disconnect
//   POST   /api/routers/{router}/firewall
//   POST   /api/routers/{router}/queue
//   POST   /api/routers/{router}/hotspot
//   POST   /api/routers/{router}/password
//   POST   /api/routers/{router}/firmware
//   POST   /api/routers/{router}/terminal
//   POST   /api/routers
//
// The absence of these endpoints is part of the security model.
// ═══════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════
// PUBLIC — no auth required
// ═══════════════════════════════════════════════════════════════════════

Route::get('/public/packages', [PackageController::class, 'publicIndex']);

// M-Pesa callback — public but validated by provider identifiers
Route::post('/mpesa/callback', [PaymentCallbackController::class, 'mpesaCallback']);
Route::get('/payment-webhooks/pesapal', [PaymentCallbackController::class, 'pesapalIpn']);
Route::post('/payment-webhooks/flutterwave', [PaymentCallbackController::class, 'flutterwaveWebhook']);
Route::post('/payment-webhooks/paystack', [PaymentCallbackController::class, 'paystackWebhook']);

// ═══════════════════════════════════════════════════════════════════════
// CUSTOMER PORTAL — uses Sanctum with customer guard
// ═══════════════════════════════════════════════════════════════════════

Route::prefix('customer')->group(function () {
    Route::post('/register', [CustomerAuthController::class, 'register']);
    Route::post('/login', [CustomerAuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'customer.user'])->group(function () {
        Route::post('/logout', [CustomerAuthController::class, 'logout']);
        Route::get('/me', [CustomerAuthController::class, 'me']);
        Route::get('/packages', [CustomerDashboardController::class, 'packages']);
        Route::get('/payment-methods', [CustomerDashboardController::class, 'paymentMethods']);
        Route::get('/service-status', [CustomerDashboardController::class, 'serviceStatus']);
        Route::get('/purchases', [CustomerDashboardController::class, 'purchases']);
        Route::get('/purchases/{id}', [CustomerDashboardController::class, 'purchaseDetail']);
        Route::get('/purchases/{id}/invoice', [CustomerDashboardController::class, 'purchaseInvoice']);
        Route::get('/payments', [CustomerDashboardController::class, 'payments']);
        Route::post('/purchase', [CustomerDashboardController::class, 'initiatePurchase']);
        Route::post('/renew', [CustomerDashboardController::class, 'renew']);
        Route::get('/payments/{id}/status', [CustomerDashboardController::class, 'paymentStatus']);
        Route::get('/support-tickets', [CustomerDashboardController::class, 'supportTickets']);
        Route::post('/support-tickets', [CustomerDashboardController::class, 'createSupportTicket']);

        Route::post('/change-password', [CustomerPasswordController::class, 'changePassword']);
        Route::post('/vouchers/redeem', [VoucherController::class, 'redeem']);

        Route::get('/wallet/balance', [WalletController::class, 'balance']);
        Route::post('/wallet/topup', [WalletController::class, 'topUp']);
        Route::post('/wallet/auto-renewal', [WalletController::class, 'toggleAutoRenewal']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// ADMIN BILLING — uses Sanctum (admin guard)
// ═══════════════════════════════════════════════════════════════════════

Route::prefix('admin')->middleware(['auth:sanctum', 'admin.user'])->group(function () {
    Route::get('/packages', [PackageController::class, 'index']);
    Route::post('/packages', [PackageController::class, 'store']);
    Route::put('/packages/{id}', [PackageController::class, 'update']);
    Route::delete('/packages/{id}', [PackageController::class, 'destroy']);

    Route::get('/customers', [AdminBillingController::class, 'customers']);
    Route::get('/customers/{id}', [AdminBillingController::class, 'customerDetail']);
    Route::post('/workflow/invoices', [AdminSubscriberWorkflowController::class, 'createInvoice']);
    Route::post('/workflow/quote', [AdminSubscriberWorkflowController::class, 'quoteInvoice']);
    Route::post('/customers/{customerId}/invoices', [AdminSubscriberWorkflowController::class, 'issueInvoice']);
    Route::get('/customers/{customerId}/network-identities', [AdminSubscriberWorkflowController::class, 'identities']);
    Route::post('/customers/{customerId}/network-identities', [AdminSubscriberWorkflowController::class, 'storeIdentity']);
    Route::patch('/customers/{customerId}/network-identities/{identityId}', [AdminSubscriberWorkflowController::class, 'updateIdentity']);
    Route::delete('/customers/{customerId}/network-identities/{identityId}', [AdminSubscriberWorkflowController::class, 'destroyIdentity']);
    Route::post('/invoices/{purchaseId}/payment', [AdminSubscriberWorkflowController::class, 'initiatePayment']);

    Route::get('/payments', [AdminBillingController::class, 'payments']);
    Route::get('/invoices', [AdminBillingController::class, 'invoices']);
    Route::get('/invoices/{id}', [AdminBillingController::class, 'invoice']);
    Route::get('/payments/pending', [AdminBillingController::class, 'pendingPayments']);
    Route::get('/payments/reconciliation', [AdminBillingController::class, 'reconciliationQueue']);
    Route::post('/payments/{id}/reconcile', [AdminBillingController::class, 'reconcilePayment']);
    Route::get('/service-accounts/activation-failures', [AdminBillingController::class, 'activationFailures']);
    Route::post('/service-accounts/{id}/retry-activation', [AdminBillingController::class, 'retryActivation']);

    Route::get('/reports/revenue', [AdminBillingController::class, 'revenueReport']);

    Route::get('/audit-logs', [AdminBillingController::class, 'auditLogs']);

    Route::get('/vouchers', [VoucherController::class, 'index']);
    Route::post('/vouchers/generate', [VoucherController::class, 'generateBatch']);

    Route::get('/sms/templates', [SmsTemplateController::class, 'templates']);
    Route::get('/sms/settings', [SmsTemplateController::class, 'settings']);
    Route::put('/sms/settings', [SmsTemplateController::class, 'updateSettings']);
    Route::get('/sms/history', [SmsTemplateController::class, 'history']);

    Route::get('/settings', [AppSettingController::class, 'index']);
    Route::put('/settings', [AppSettingController::class, 'update']);

    // ── RADIUS management ───────────────────────────────────────────
    Route::get('/radius/status', [RadiusController::class, 'status']);
    Route::get('/radius/nas', [RadiusController::class, 'nasIndex']);
    Route::post('/radius/nas', [RadiusController::class, 'nasStore']);
    Route::delete('/radius/nas/{id}', [RadiusController::class, 'nasDestroy']);
    Route::post('/radius/nas/sync', [RadiusController::class, 'syncNas']);
    Route::get('/radius/sessions', [RadiusController::class, 'activeSessions']);
    Route::post('/radius/sessions/disconnect', [RadiusController::class, 'disconnectSession']);
    Route::get('/radius/accounting', [RadiusController::class, 'accountingHistory']);
    Route::get('/radius/users/{username}/usage', [RadiusController::class, 'userDataUsage']);
    Route::get('/radius/post-auth', [RadiusController::class, 'postAuthLog']);
    Route::get('/radius/groups', [RadiusController::class, 'groups']);
});

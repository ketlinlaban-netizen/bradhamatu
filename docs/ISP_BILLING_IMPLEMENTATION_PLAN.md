# ISP Billing Platform — Implementation Plan

**Date:** 2026-10-10  
**Project:** Community WiFi Bradha Matu

---

## Phased Execution

### Phase A: Audit (COMPLETE)
- Full repository inspection
- Gap analysis documented in `ISP_BILLING_AUDIT.md`

### Phase B: Data Model, Package Management, Customer Portal

**Database migrations:**
- `customers` — UUID PK, full_name, phone (normalized, unique), email (nullable), password, status, terms_accepted_at, policy_version, timestamps
- `packages` — UUID PK, name, description, price_minor (integer KSh cents), duration_minutes, access_type (hotspot/ppoe), download_limit_kbps, upload_limit_kbps, quota_mb (nullable), max_devices, router_id (nullable, for site-specific), is_enabled, sort_order, timestamps
- `purchases` — UUID PK, customer_id, package_id, package_snapshot (JSON), order_id (unique), amount_minor, status (enum), created_at, completed_at
- `payments` — UUID PK, purchase_id, provider (mpesa), provider_request_id, provider_checkout_id, provider_reference, state (enum), amount_minor, phone, idempotency_key (unique), initiated_at, completed_at, failure_reason, timestamps
- `service_accounts` — UUID PK, purchase_id, customer_id, router_id, username, password (hashed), access_type, rate_limit_rx, rate_limit_tx, activated_at, expires_at, activation_status, deactivation_reason, timestamps
- `audit_logs` — UUID PK, actor_type, actor_id, action, entity_type, entity_id, details (JSON), created_at
- `support_tickets` — UUID PK, customer_id, subject, body, status, priority, assigned_to (nullable), timestamps

**Backend:**
- Customer auth: register, login (Sanctum), logout, me, password reset
- Package CRUD (admin only)
- Customer profile and dashboard endpoints
- Phone number normalization utility (Kenyan format)

**Frontend:**
- Customer registration page
- Customer login page
- Customer dashboard (packages, purchase, payment status, history)
- Public ISP website (homepage, packages, coverage, how-to-connect)

### Phase C: Daraja STK Push Service

**Backend:**
- `DarajaApiClient` service — OAuth token management, STK push request, transaction status query
- `PaymentService` — orchestrates STK push, creates pending payments, processes callbacks
- `PaymentCallbackController` — public callback endpoint with validation, idempotency, reconciliation
- Payment state machine: created → initiating → pending → successful/failed/cancelled/timed_out
- Reconciliation service for ambiguous outcomes
- Configuration: Daraja env, consumer key/secret, short code, passkey, callback URL
- Sandbox/production switching via env

**Frontend:**
- STK Push payment form with phone confirmation
- Payment status polling with backoff
- Payment result display (success/failure/pending)

### Phase D: MikroTik Billing-Provisioning Adapter

**Backend:**
- `BillingProvisioningService` — separate from monitoring, uses dedicated write-capable MikroTik credentials
- Allowed operations: create hotspot user, set hotspot user profile, remove hotspot user, disconnect session
- `HotspotUserManager` — creates/updates/removes `/ip/hotspot/user` entries
- `HotspotProfileManager` — manages `/ip/hotspot/user/profile` for rate limits and session timeouts
- Idempotency locks on provisioning operations
- Audit logging for all provisioning actions
- Safe failure handling — activation_failed state preserves payment

### Phase E: Activation, Expiry, Receipts, Admin Dashboard

**Backend:**
- `ActivationService` — orchestrates provisioning after verified payment
- `ExpiryScheduler` — scheduled commands for expired packages, retryable activations, stale payment reconciliation
- Receipt generation (PDF or structured data)
- Revenue reporting endpoints (verified payments only)
- Admin endpoints: customer management, payment reconciliation, package management, audit logs

**Frontend:**
- Admin dashboard extensions: revenue, active subscribers, pending payments, activation failures
- Customer: receipt view, renewal flow, expiry notifications
- Admin: payment reconciliation view, customer service history, audit log viewer

### Phase F: Automated Tests

- Customer registration/login/authz
- Package pricing validation
- Phone number normalization
- STK Push initiation with fake Daraja client
- Duplicate callback handling
- Forged callback rejection
- Wrong amount detection
- Payment success → activation flow
- Activation failure → retry without re-charge
- Duplicate activation prevention
- Package expiry processing
- Customer isolation
- Admin permission checks
- Revenue reports exclude pending/failed
- Read-only guard still rejects writes
- Billing provisioning requires authorization

### Phase G: Frontend Build, Documentation, Final Report

- Production frontend build
- All documentation files
- Final implementation report

---

## File Structure Plan

```
bradha-matu-api/
  app/
    Http/Controllers/
      CustomerAuthController.php      (new)
      CustomerDashboardController.php (new)
      PackageController.php           (new)
      PaymentController.php           (new)
      PaymentCallbackController.php   (new)
      ReceiptController.php           (new)
      AdminReportController.php       (new)
      AdminCustomerController.php     (new)
      AuditLogController.php          (new)
      SupportTicketController.php     (new)
    Models/
      Customer.php                    (new)
      Package.php                     (new)
      Purchase.php                    (new)
      Payment.php                     (new)
      ServiceAccount.php              (new)
      AuditLog.php                    (new)
      SupportTicket.php               (new)
    Services/
      DarajaApiClient.php             (new)
      PaymentService.php              (new)
      ActivationService.php           (new)
      BillingProvisioningService.php  (new)
      HotspotUserManager.php          (new)
      ExpiryService.php               (new)
      PhoneNormalizer.php             (new)
      ReceiptService.php              (new)
    Policies/
      CustomerPolicy.php              (new)
      PackagePolicy.php               (new)
      PurchasePolicy.php              (new)
      SupportTicketPolicy.php         (new)
  database/migrations/
    2024_01_01_000006_create_customers_table.php
    2024_01_01_000007_create_packages_table.php
    2024_01_01_000008_create_purchases_table.php
    2024_01_01_000009_create_payments_table.php
    2024_01_01_000010_create_service_accounts_table.php
    2024_01_01_000011_create_audit_logs_table.php
    2024_01_01_000012_create_support_tickets_table.php
  tests/
    Feature/
      CustomerAuthTest.php
      PackageTest.php
      PaymentTest.php
      PaymentCallbackTest.php
      ActivationTest.php
      ExpiryTest.php
      AuthorizationTest.php

src/
  pages/
    customer/
      CustomerLoginPage.tsx
      CustomerRegisterPage.tsx
      CustomerDashboardPage.tsx
      CustomerPackagesPage.tsx
      CustomerPaymentsPage.tsx
      CustomerProfilePage.tsx
    public/
      PublicHomePage.tsx
      PublicPackagesPage.tsx
    AdminReportsPage.tsx
    AdminCustomersPage.tsx
    AdminPaymentsPage.tsx
  services/
    customerApi.ts
    paymentApi.ts
```

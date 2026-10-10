# ISP Billing Platform — API Matrix

**Date:** 2026-10-10  
**Project:** Community WiFi Bradha Matu

---

## Existing API Routes (Preserved)

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| GET | `/api/health` | None | Health check |
| POST | `/api/auth/login` | None (throttled 5/min) | Admin login |
| POST | `/api/auth/logout` | Sanctum (admin) | Admin logout |
| GET | `/api/auth/me` | Sanctum (admin) | Admin profile |
| GET | `/api/dashboard` | Sanctum (admin) | Dashboard summary |
| GET | `/api/routers` | Sanctum (admin) | Router inventory |
| GET | `/api/routers/search` | Sanctum (admin) | Search routers |
| GET | `/api/routers/{id}` | Sanctum (admin) | Router detail |
| GET | `/api/routers/{id}/status` | Sanctum (admin) | Router status |
| GET | `/api/routers/{id}/interfaces` | Sanctum (admin) | Router interfaces |
| GET | `/api/routers/{id}/clients` | Sanctum (admin) | Router clients |
| GET | `/api/routers/{id}/traffic` | Sanctum (admin) | Router traffic |
| GET | `/api/routers/{id}/wireless` | Sanctum (admin) | Wireless info |
| GET | `/api/routers/{id}/metrics` | Sanctum (admin) | Router metrics |
| GET | `/api/routers/{id}/connect` | Sanctum (admin) | Test connection |
| GET | `/api/clients` | Sanctum (admin) | All clients |
| GET | `/api/traffic` | Sanctum (admin) | Aggregate traffic |
| GET | `/api/network-health` | Sanctum (admin) | Network health |
| GET | `/api/incidents` | Sanctum (admin) | Incidents |
| GET | `/api/mikrotik-configs` | Sanctum (admin) | List router configs |
| POST | `/api/mikrotik-configs` | Sanctum (admin) | Add router config |
| DELETE | `/api/mikrotik-configs/{id}` | Sanctum (admin) | Delete router config |
| POST | `/api/mikrotik-configs/{id}/connect` | Sanctum (admin) | Test router connection |

---

## New API Routes — Customer Portal

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| POST | `/api/customer/register` | None | Customer registration |
| POST | `/api/customer/login` | None (throttled 5/min) | Customer login |
| POST | `/api/customer/logout` | Sanctum (customer) | Customer logout |
| GET | `/api/customer/me` | Sanctum (customer) | Customer profile |
| POST | `/api/customer/forgot-password` | None | Password reset request |
| POST | `/api/customer/reset-password` | None | Password reset submission |
| GET | `/api/customer/packages` | Sanctum (customer) | Available packages |
| GET | `/api/customer/purchases` | Sanctum (customer) | Purchase history |
| GET | `/api/customer/purchases/{id}` | Sanctum (customer) | Purchase detail + receipt |
| GET | `/api/customer/payments` | Sanctum (customer) | Payment history |
| GET | `/api/customer/payments/{id}` | Sanctum (customer) | Payment detail |
| POST | `/api/customer/purchase` | Sanctum (customer) | Initiate purchase + STK push |
| GET | `/api/customer/payments/{id}/status` | Sanctum (customer) | Poll payment status |
| GET | `/api/customer/service-status` | Sanctum (customer) | Current active package + expiry |
| POST | `/api/customer/support-tickets` | Sanctum (customer) | Submit support ticket |
| GET | `/api/customer/support-tickets` | Sanctum (customer) | List own tickets |

## New API Routes — Public

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| GET | `/api/public/packages` | None | Public package listing |
| GET | `/api/public/coverage` | None | Service area info |

## New API Routes — M-Pesa Callback

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| POST | `/api/mpesa/callback` | Provider-validated | Daraja STK push callback |

## New API Routes — Admin Billing

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| GET | `/api/admin/packages` | Sanctum (admin+) | List all packages |
| POST | `/api/admin/packages` | Sanctum (admin+) | Create package |
| PUT | `/api/admin/packages/{id}` | Sanctum (admin+) | Update package |
| DELETE | `/api/admin/packages/{id}` | Sanctum (super_admin) | Delete package |
| GET | `/api/admin/customers` | Sanctum (admin+) | List customers |
| GET | `/api/admin/customers/{id}` | Sanctum (admin+) | Customer detail |
| GET | `/api/admin/payments` | Sanctum (admin+) | All payments with filters |
| GET | `/api/admin/payments/pending` | Sanctum (admin+) | Pending payments |
| GET | `/api/admin/payments/reconciliation` | Sanctum (admin+) | Reconciliation queue |
| POST | `/api/admin/payments/{id}/reconcile` | Sanctum (admin+) | Manual reconciliation |
| GET | `/api/admin/reports/revenue` | Sanctum (admin+) | Revenue reports |
| GET | `/api/admin/reports/subscribers` | Sanctum (admin+) | Active subscriber reports |
| GET | `/api/admin/audit-logs` | Sanctum (super_admin) | Audit log viewer |
| GET | `/api/admin/support-tickets` | Sanctum (admin+) | All support tickets |
| PUT | `/api/admin/support-tickets/{id}` | Sanctum (admin+) | Update ticket status |

---

## Authentication Guards

| Guard | Users | Token Storage | Purpose |
|---|---|---|---|
| `web` | users table | Session | Admin web (not used for API) |
| `sanctum` (default) | users table | personal_access_tokens | Admin API access |
| `customer` | customers table | personal_access_tokens | Customer API access |

## Role Permissions Matrix

| Permission | super_admin | admin | billing | tech | support | customer |
|---|---|---|---|---|---|---|
| View admin dashboard | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Manage router configs | ✓ | ✓ | ✗ | ✓ | ✗ | ✗ |
| View router monitoring | ✓ | ✓ | ✗ | ✓ | ✗ | ✗ |
| Manage packages | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| View customers | ✓ | ✓ | ✓ | ✗ | ✓ | ✗ |
| Reconcile payments | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| View revenue reports | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| Manage support tickets | ✓ | ✓ | ✗ | ✗ | ✓ | ✗ |
| View audit logs | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Customer self-service | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ |

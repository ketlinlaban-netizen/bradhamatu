# ISP Billing Platform — Existing Repository Audit

**Date:** 2026-10-10  
**Project:** Community WiFi Bradha Matu NOC Console  
**Audit scope:** Full repository inspection before billing platform implementation

---

## Executive Summary

The existing project is a **read-only Network Operations Console** for monitoring MikroTik routers. It has a polished React frontend and a Laravel 13 backend with a pure-PHP MikroTik binary API client. The entire architecture is deliberately read-only — the absence of mutation endpoints is described in the code as the security model.

**No billing, packages, M-Pesa, customer portal, or persistent customer accounts exist.** The "clients" in the UI are transient WiFi/DHCP registrations pulled live from routers, not subscriber accounts.

---

## Tech Stack

### Frontend
- React 18.3, react-router-dom 6.26, recharts 2.12, lucide-react 0.45
- Vite 5.4, TypeScript 5.5, Tailwind CSS 3.4
- Dark "NOC console" theme with custom palette (noc-bg, noc-panel, noc-card, noc-accent)
- Fonts: Inter + JetBrains Mono
- PWA: manifest.webmanifest + service worker
- `@supabase/supabase-js` declared but never imported (dead dependency)

### Backend
- **Laravel Framework 13.17**, PHP 8.3
- laravel/sanctum 4.3 (token auth)
- PHPUnit 12 (dev)
- Default DB: MySQL/MariaDB; SQLite also supported

---

## What Already Works (Preserve These)

1. **MikroTik binary API client** (`MikroTikApiClient.php`) — pure PHP RouterOS protocol implementation with TCP/TLS sockets, login, print/monitor commands
2. **Read-only guard** — enforced at three layers: route absence, backend `RouterReadOnlyGuard`, frontend `RouterReadOnlyGuard`
3. **Router inventory management** — `MikroTikConfigController` CRUD for router credentials
4. **Auth system** — Sanctum token auth, login/logout/me, role column on users
5. **Router monitoring** — status, interfaces, clients, traffic, wireless, metrics, connection test
6. **Dashboard** — summary stats, router health, incidents (computed live)
7. **Design system** — consistent dark NOC theme, reusable UI components
8. **Security middleware** — SecurityHeaders, TrustCloudflareProxies
9. **Deployment infrastructure** — nginx + cloudflared + systemd + setup scripts

---

## What Is Missing for Billing Platform

| Capability | Status |
|---|---|
| Customer/subscriber accounts | Absent — `users` is admins only |
| Internet packages/plans | No table, no model, no API |
| Subscriptions / package assignment | Nothing links customer to plan |
| M-Pesa Daraja integration | No STK push, no callbacks |
| Payment ledger / transactions | No table |
| Customer portal | No customer-facing auth or routes |
| MikroTik hotspot user management | No write operations (deliberately forbidden) |
| Bandwidth/queue assignment | No queue writes |
| Auto-suspension on expiry | None |
| Notifications (SMS/email) | None |
| Scheduled expiry processing | No scheduler tasks |
| Receipts/invoices | None |
| Revenue reporting | None |
| Role-based access control | Roles exist but not enforced |
| Support tickets | None |

---

## Duplicate / Mock / Placeholder Implementations

1. **`router_metrics` table unused** — `getMetrics()` synthesizes a single in-memory point; no persistence, no scheduler
2. **`incidents` table unused** — `getIncidents()` computes from live status; never reads/writes the table
3. **`getTraffic().history` returns a single point**, not the "last 2 hours" the UI claims
4. **`connect()` returns fake session token** `"ro-".Str::random(32)`
5. **DashboardPage "Clients"/"Uptime" columns render literal "—"** — placeholder
6. **N+1 fan-out everywhere** — both frontend and backend loop per-router for dashboard/clients/traffic/health
7. **`@supabase/supabase-js` dependency unused** — leftover
8. **SystemPage says "Laravel 11"** — actually Laravel 13
9. **`laravel-contracts/` directory is stale** — references MockRouterProvider and MD5 login that don't match actual code
10. **Router API passwords stored plaintext** — model `$hidden` hides from JSON but not encrypted at rest
11. **Auth roles cosmetic** — `super_admin`/`admin`/`viewer` exist but no authorization logic differentiates them

---

## Architectural Considerations for Billing

### Read-Only Model Extension
The current security model is read-only by absence of mutation endpoints. Adding billing requires **write operations** to MikroTik (creating hotspot users, setting queues, disconnecting expired users). This must be implemented as a **separate, narrowly scoped service** with:
- A fixed list of allowed operations
- Dedicated, restricted RouterOS credentials (separate from monitoring)
- Server-side validation and authorization
- Audit logging
- Idempotency
- No arbitrary RouterOS command input

### Customer Identity
- New `customers` table separate from admin `users`
- Phone number as primary identifier (Kenyan format normalization)
- Sanctum tokens for customer API access (separate guard)

### Payment Security
- Integer minor units for all money (KSh centimes)
- Server-side price reload from trusted DB record
- Package snapshots on purchase
- Idempotency keys on payment records
- Separate payment state and service activation state
- Callback idempotency via unique constraints

### Database
- Existing: users, routers, router_metrics, incidents, personal_access_tokens
- New: customers, packages, purchases, payments, service_accounts, audit_logs, support_tickets
- All new tables use UUID primary keys (matching existing convention)

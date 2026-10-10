# Community WiFi Bradha Matu — Complete Laravel API Matrix

**Source of truth:** `bradha-matu-api/routes/api.php` and the frontend service calls.

## Request conventions

- Frontend base path: `VITE_API_URL` when supplied, otherwise `/api`.
- JSON requests send `Accept: application/json` and `Content-Type: application/json`.
- Admin bearer tokens are stored separately from customer tokens but sent through the shared Laravel API client.
- Admin routes require `auth:sanctum` plus `admin.user`.
- Customer routes require `auth:sanctum` plus `customer.user`.
- Router mutations are intentionally absent. MikroTik operations exposed to the console are read-only monitoring or protected configuration storage.

## Public and authentication routes

| Method | Laravel path | Frontend caller | Auth | Controller | Result |
|---|---|---|---|---|---|
| GET | `/api/health` | Deployment/health probes | None | `HealthController@index` | Service health |
| POST | `/api/auth/login` | `AuthContext.login` | None, throttled | `AuthController@login` | Admin token and profile |
| POST | `/api/auth/logout` | `AuthContext.logout` | Admin Sanctum | `AuthController@logout` | Revoked token |
| GET | `/api/auth/me` | `AuthContext` session restore | Admin Sanctum | `AuthController@me` | Admin profile |
| GET | `/api/public/packages` | `getPublicPackages` | None | `PackageController@publicIndex` | Public packages |
| POST | `/api/mpesa/callback` | Daraja provider | Provider callback validation | `PaymentCallbackController@mpesaCallback` | Payment state update |

## Admin monitoring and configuration

| Method | Laravel path | Frontend caller | Auth | Controller | Result |
|---|---|---|---|---|---|
| GET | `/api/dashboard` | `LaravelApiProvider.getDashboardSummary` | Admin | `DashboardController@index` | Network summary |
| GET | `/api/routers` | `getRouters` | Admin | `RouterController@index` | Router inventory |
| GET | `/api/routers/search?q=` | `searchRouters` | Admin | `RouterController@search` | Matching routers |
| GET | `/api/routers/{router}` | `getRouter` | Admin | `RouterController@show` | Router detail |
| GET | `/api/routers/{router}/status` | `getStatus` | Admin | `RouterStatusController@show` | Status |
| GET | `/api/routers/{router}/interfaces` | `getInterfaces` | Admin | `RouterInterfaceController@index` | Interfaces |
| GET | `/api/routers/{router}/clients` | `getClients` | Admin | `RouterClientController@index` | Connected clients |
| GET | `/api/routers/{router}/traffic` | `getTraffic` | Admin | `RouterTrafficController@show` | Traffic |
| GET | `/api/routers/{router}/wireless` | `getWireless` | Admin | `RouterWirelessController@show` | Wireless data |
| GET | `/api/routers/{router}/metrics` | `getMetrics` | Admin | `RouterMetricController@index` | Metrics |
| GET | `/api/routers/{router}/connect` | `connect` | Admin | `RouterConnectionController@connect` | Read-only connection test |
| GET | `/api/clients` | `getClients` dashboard calls | Admin | `ClientController@index` | Global clients |
| GET | `/api/traffic` | Traffic page calls | Admin | `TrafficController@index` | Aggregate traffic |
| GET | `/api/network-health` | Network health page calls | Admin | `NetworkHealthController@index` | Health summary |
| GET | `/api/incidents` | `getIncidents` | Admin | `IncidentController@index` | Incidents |
| GET | `/api/mikrotik-configs` | MikroTik config page | Admin | `MikroTikConfigController@index` | Masked configs |
| POST | `/api/mikrotik-configs` | MikroTik config page | Admin | `MikroTikConfigController@store` | Stored config |
| DELETE | `/api/mikrotik-configs/{id}` | MikroTik config page | Admin | `MikroTikConfigController@destroy` | Deleted config |
| POST | `/api/mikrotik-configs/{id}/connect` | MikroTik config page | Admin | `MikroTikConfigController@connect` | Read-only connection result |

## Customer portal

| Method | Laravel path | Frontend caller | Auth | Controller | Result |
|---|---|---|---|---|---|
| POST | `/api/customer/register` | `customerRegister` | None | `CustomerAuthController@register` | Customer token |
| POST | `/api/customer/login` | `customerLogin` | None, throttled | `CustomerAuthController@login` | Customer token |
| POST | `/api/customer/logout` | `customerLogout` | Customer | `CustomerAuthController@logout` | Revoked token |
| GET | `/api/customer/me` | `getCustomerProfile` | Customer | `CustomerAuthController@me` | Customer profile |
| GET | `/api/customer/packages` | `getCustomerPackages` | Customer | `CustomerDashboardController@packages` | Available packages |
| GET | `/api/customer/service-status` | `getServiceStatus` | Customer | `CustomerDashboardController@serviceStatus` | Active package and due date |
| GET | `/api/customer/purchases` | `getPurchases` | Customer | `CustomerDashboardController@purchases` | Purchase history |
| GET | `/api/customer/purchases/{id}` | Reserved for detail view | Customer | `CustomerDashboardController@purchaseDetail` | Purchase and receipt |
| GET | `/api/customer/payments` | `getPayments` | Customer | `CustomerDashboardController@payments` | Payment history |
| GET | `/api/customer/payments/{id}/status` | `getPaymentStatus` | Customer | `CustomerDashboardController@paymentStatus` | Current payment state |
| POST | `/api/customer/purchase` | `initiatePurchase` | Customer | `CustomerDashboardController@initiatePurchase` | Server-priced purchase and STK push |
| POST | `/api/customer/renew` | Available for renewal flows | Customer | `CustomerDashboardController@renew` | Renewal purchase |
| GET | `/api/customer/support-tickets` | `getSupportTickets` | Customer | `CustomerDashboardController@supportTickets` | Own tickets |
| POST | `/api/customer/support-tickets` | `createSupportTicket` | Customer | `CustomerDashboardController@createSupportTicket` | New ticket |

## Admin billing

| Method | Laravel path | Frontend caller | Auth | Controller | Result |
|---|---|---|---|---|---|
| GET | `/api/admin/packages` | `getAdminPackages` | Admin | `PackageController@index` | Package catalog |
| POST | `/api/admin/packages` | `createAdminPackage` | Admin | `PackageController@store` | New package |
| PUT | `/api/admin/packages/{id}` | `updateAdminPackage` | Admin | `PackageController@update` | Updated package |
| DELETE | `/api/admin/packages/{id}` | `deleteAdminPackage` | Admin | `PackageController@destroy` | Removed package |
| GET | `/api/admin/customers` | `getAdminCustomers` | Admin | `AdminBillingController@customers` | Paginated customers |
| GET | `/api/admin/customers/{id}` | `getAdminCustomer` | Admin | `AdminBillingController@customerDetail` | Customer billing detail |
| GET | `/api/admin/payments` | `getAdminPayments` | Admin | `AdminBillingController@payments` | Paginated payments |
| GET | `/api/admin/payments/pending` | `getPendingAdminPayments` | Admin | `AdminBillingController@pendingPayments` | Pending payments |
| GET | `/api/admin/payments/reconciliation` | `getReconciliationQueue` | Admin | `AdminBillingController@reconciliationQueue` | Reconciliation queue |
| POST | `/api/admin/payments/{id}/reconcile` | `reconcileAdminPayment` | Admin | `AdminBillingController@reconcilePayment` | Reconciled payment |
| POST | `/api/admin/service-accounts/{id}/retry-activation` | `retryAdminActivation` | Admin | `AdminBillingController@retryActivation` | Retried activation |
| GET | `/api/admin/reports/revenue?period=` | `getRevenueReport` | Admin | `AdminBillingController@revenueReport` | Revenue and subscriber metrics |
| GET | `/api/admin/audit-logs` | `getAdminAuditLogs` | Admin | `AdminBillingController@auditLogs` | Paginated audit logs |

## Deliberately not present

The repository does not define customer password-reset routes, public coverage routes, subscriber-report routes, or admin support-ticket routes. They are not listed as available APIs and must not be called by the frontend until Laravel controllers and routes are added.

The repository also does not define router reboot, terminal, firewall, queue, hotspot, client-disconnect, or router-write routes. This is intentional read-only protection.

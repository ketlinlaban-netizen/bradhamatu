# Hosted payment gateway setup

The customer portal keeps the existing M-Pesa/Daraja STK flow and can also initiate hosted checkout with Pesapal, Flutterwave, or Paystack. Gateway choice is an allowlisted enum sent to Laravel; credentials, provider URLs, payment references, webhook verification, and the final paid decision remain server-side.

## Transport and verification

| Method | Checkout | Notification | Server verification |
|---|---|---|---|
| M-Pesa | STK Push | `/api/mpesa/callback` | Existing Daraja callback/query flow |
| Pesapal | API 3.0 hosted redirect | `GET /api/payment-webhooks/pesapal` (register this as an IPN first) | Query the authenticated transaction-status endpoint; compare merchant reference, amount, and currency |
| Flutterwave | Standard hosted payment link | `POST /api/payment-webhooks/flutterwave` | Check the `verif-hash`, then query the authenticated transaction-verification endpoint and compare reference, amount, and currency |
| Paystack | Transaction initialization redirect | `POST /api/payment-webhooks/paystack` | Check the `x-paystack-signature` HMAC, then query the authenticated transaction-verification endpoint and compare reference, amount, and currency |

Hosted checkout links are accepted only over HTTPS and only for the exact provider checkout hosts. Provider API URLs are fixed in the backend, redirects are disabled, TLS certificate and hostname validation remain enabled, and API requests have bounded connection/request timeouts. A browser return is not proof of payment. A successful provider-side verification is required before an invoice is issued or activation proceeds.

The app requests KES amounts using minor units where the provider expects them and major KES units where its API expects a decimal amount. Provider-reported amount and currency must exactly match the pending payment; discrepancies remain in `reconciliation_required`.

## Server environment

Set these only in the Laravel server environment (never in `VITE_*` variables or browser storage). The backend `.env.example` contains blank examples:

```dotenv
# Existing M-Pesa/Daraja integration
DARAJA_ENV=sandbox
DARAJA_CONSUMER_KEY=
DARAJA_CONSUMER_SECRET=
DARAJA_SHORT_CODE=
DARAJA_PASSKEY=
DARAJA_CALLBACK_URL=

# Pesapal API 3.0; register the IPN URL below and use its returned notification ID.
PESAPAL_ENV=sandbox
PESAPAL_CONSUMER_KEY=
PESAPAL_CONSUMER_SECRET=
PESAPAL_IPN_ID=
PESAPAL_CURRENCY=KES

# Flutterwave and Paystack credentials must be provisioned from the merchant accounts.
FLW_SECRET_KEY=
FLW_WEBHOOK_SECRET_HASH=
FLW_CURRENCY=KES
PAYSTACK_SECRET_KEY=
PAYSTACK_CURRENCY=KES

# The customer-facing React application origin, not a value supplied by the browser.
FRONTEND_URL=https://your-console-host.example
```

For production, use live credentials from the corresponding merchant account, `PESAPAL_ENV=production`, and a public HTTPS `FRONTEND_URL`. Keep secret values in the hosting provider's secret/environment manager, restrict access to them, and rotate them if exposed. Flutterwave and Paystack require a valid customer email; an email entered for checkout is sent only to that gateway and is not written to the customer record by this purchase endpoint.

## Webhook and hosting checklist

1. Confirm the Laravel API is reachable by the selected provider at the callback URL over public HTTPS with a valid certificate chain and hostname.
2. Allow outbound HTTPS from the cPanel account to the provider APIs. Do not relax TLS validation or route payment traffic through an untrusted proxy.
3. Register `https://your-api-host.example/api/payment-webhooks/pesapal` with Pesapal and set the returned IPN ID as `PESAPAL_IPN_ID`.
4. Configure the exact Flutterwave and Paystack webhook paths shown above in each merchant dashboard. Set the configured Flutterwave webhook secret hash to the value used by the merchant dashboard.
5. Verify sandbox checkout, signed/registered notification delivery, provider verification, amount/currency matching, duplicate notifications, and a failed transaction before enabling live credentials.
6. Check Laravel and provider logs without recording credentials, full authorization headers, webhook secrets, payment-card data, or raw customer checkout payloads.
7. Confirm provider settlement, refund and dispute procedures with each merchant account; this integration does not automatically implement refunds, chargebacks, settlements, or financial reconciliation beyond checking individual transaction status.

No gateway credentials are present in the repository. Hosted gateways are offered to the customer only when their required server configuration is present. The existing M-Pesa option remains available as before; without Daraja configuration it stays in the legacy pending/manual-test mode and does not send an STK push. Pesapal IPN registration and merchant-dashboard webhook configuration are manual prerequisites; they are not performed automatically by the application.

## Tests and operational limits

The Laravel feature suite uses mocked HTTP responses. It verifies hosted checkout initiation, webhook authentication, provider-side verification, amount mismatch handling, provider selection, and that credentials are not returned in the customer API response. These tests do not contact a payment provider and do not establish live merchant-account, cPanel egress, callback reachability, settlement, or production TLS behavior.

To disable a hosted gateway, remove its corresponding backend credential/configuration values and run `php artisan config:clear` (or rebuild the deployment's config cache). Its payment option then disappears for new purchases. Existing payment rows and invoices are retained; pending transactions must be reconciled with the provider before any manual state change. M-Pesa remains available. Do not delete payment or purchase records as part of rollback.

Provider documentation:

- [Pesapal API 3.0 reference](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/api-reference)
- [Flutterwave developer documentation](https://developer.flutterwave.com/docs)
- [Paystack transaction API](https://paystack.com/docs/api/transaction/)

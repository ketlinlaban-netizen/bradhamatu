# cPanel deployment audit

Audit date: 2026-10-10
Post-integration review: 2026-10-11

## Scope and working-tree state

This is an audit of the repository in its current local workspace. The
MikroTik connection was reported working by the operator. This audit did not
contact a physical router, access cPanel, deploy files, or change production
data.

The monitoring implementation from the separate local `marto` project was
integrated into this repository while preserving its existing customer and
administrator billing routes, services, migrations, UI, and scheduled billing
commands. The source project was not modified.

The worktree was already substantially modified before this production
preparation. Existing user changes were preserved. In particular, the Laravel
RouterOS integration, client inventory work, dashboard edits, and build
artifacts may include pre-existing local work and should be reviewed together
before release.

The actual Laravel backend is `bradha-matu-api/`; the frontend is built from
the repository root. Laravel Composer dependencies and the root Node lockfile
are present. The local Laravel `.env` is ignored by Git. It contains
environment-specific values and credentials; their values are intentionally
not copied into this audit.

## Versions verified locally

| Component | Verified version/state |
|---|---|
| Laravel | 13.35.0 |
| PHP CLI | 8.4.24 |
| Composer | 2.10.2 |
| PHP requirement in Composer | `^8.3` |
| RouterOS client | `evilfreelancer/routeros-api-php` 1.7.1, locked |
| Sanctum | 4.3.3, locked |
| React | 18.3.1, root lockfile |
| Vite | 5.4.21, root lockfile |
| TypeScript | 5.9.3, root lockfile |
| Node / npm | 24.16.0 / 11.16.0 |

The lockfile requires PHP `^8.3`, `sockets` for the locked RouterOS Binary API
client, and Laravel's runtime extensions including `ctype`, `filter`, `hash`,
`mbstring`, `openssl`, `session`, and `tokenizer`. The local
`composer check-platform-reqs --no-dev --lock` also verified `dom`, `fileinfo`,
`iconv`, `json`, and `libxml`; `mbstring` is satisfied by the locked polyfill
in that Composer check. The configured MySQL/MariaDB driver separately
requires `pdo_mysql`. REST hostname-to-approved-private-IP pinning requires
cURL; for IP-literal endpoints cURL is not a general Composer requirement.
These checks passed only for the local CLI, not cPanel web/CLI runtimes.

## Runtime and application design

- The backend provider abstraction is `RouterProvider`. Implemented provider
  values are `mikrotik` (existing Binary API), `rest` (new HTTPS REST transport),
  and `mock` (fictional demo data, explicitly tagged). The mode is selected
  from server-side Laravel configuration, not a browser request.
- `MikroTikRouterProvider` gets enabled router credentials from Laravel's
  database. `Router.api_password` is encrypted with Laravel's `APP_KEY`, hidden
  from model serialization, and masked in configuration API responses.
- Local Binary API traffic continues to use short-lived TCP sessions via the
  locked `evilfreelancer/routeros-api-php` package, with bounded timeouts and
  retries disabled. The REST transport uses HTTPS, validated trust chains and
  hostnames, a pinned approved private destination, disabled redirects,
  bounded timeouts and a 1 MiB response ceiling.
- The server-side `RouterReadOnlyGuard` allowlists RouterOS read command paths
  before a client session is constructed. Both transports share the same
  provider data mapping; REST additionally allows only the fixed RouterOS
  `print` resources and one exact one-shot traffic monitor POST. No arbitrary
  command or REST write verb is exposed by the application.
- Router inventory create/delete changes Laravel records only. Router API
  credentials are never returned to the frontend.
- Current live router data is request-driven; dashboard/API reads can contact
  routers. Successful client snapshots persist in `network_clients`, and the
  client inventory has explicit refresh behavior. No scheduled router
  collection command, cadence, or lock-protected periodic collector was found.
- RouterOS cache entries are short lived. The default cache store is configured
  by `CACHE_STORE` (the local environment uses database cache). The frontend
  API client does not cache GET responses. Neither cache nor saved client
  inventory is a durable periodic router health/metrics collection system.
- Database queue support exists in Laravel config, but no application
  collection jobs were found. No Redis, WebSocket, daemon, shell-based runtime
  dependency, or long-running worker is required by the RouterOS transport.
- `routes/console.php` defines billing processing commands; the scheduler in
  `bootstrap/app.php` invokes expiry processing, activation retries, payment
  reconciliation, and pending activations. There is no scheduled router
  collector. `routes/api.php` contains authenticated monitoring, customer,
  and billing APIs plus a minimal public health route.
- `TrustCloudflareProxies` trusts Cloudflare address ranges and loopback
  proxies only; it does not trust arbitrary forwarded headers. A cPanel
  reverse-proxy/TLS-termination arrangement must be confirmed with the provider
  and configured only for its actual trusted proxy addresses.

## Local deployment arrangement

The Laravel backend can run via `php artisan serve`; the local frontend uses
Vite (`npm run dev`) and proxies `/api` to a configured local backend. The
production frontend is built by root `npm run build` and emits `dist/`.
Laravel's current `public/index.html` and public assets are tracked local build
outputs; deployment must ensure the HTML asset filenames match the exact
frontend build being released.

No XAMPP-specific runtime dependency was found. The working local arrangement
in this workspace uses PHP CLI and Vite dev server. cPanel production should
use Apache/PHP-FPM or the hosting provider's supported Laravel deployment
mechanism, not `artisan serve` or the Vite dev server.

## Public files and document root

Laravel's backend `public/` directory contains the front controller,
`.htaccess`, SPA index, and frontend assets. Its `.htaccess` denies directory
listing, preserves Authorization and XSRF headers, and forwards non-file
requests to `index.php`.

The preferred cPanel configuration is a domain/subdomain document root pointed
at the deployed Laravel `public/` directory, with the rest of the Laravel
repository outside the web root. If cPanel requires `public_html`, put only
the contents of Laravel `public/` there and use an explicitly reviewed
front-controller path into the private application directory. Do not upload
the whole repository into the public document root. Do not expose `.env`,
`vendor`, Git metadata, `storage`, database files, or archives.

## Hosting and router facts that remain unverified

No target cPanel account, PHP selector output, hosting plan capabilities,
database account, cron panel, firewall policy, VPN, private route, router
management VLAN, or provider network documentation was available. Therefore
none of these have been verified:

- cPanel PHP 8.3+ in both web and CLI, all locked runtime requirements,
  `pdo_mysql` for the selected MySQL/MariaDB connection, and cURL if REST
  hostname pinning is selected.
- Outbound TCP connectivity from cPanel to the current private router network.
- cPanel-to-router HTTPS REST connectivity, including DNS/IP SAN trust and the
  approved source route.
- Physical RouterOS version, enabled `www-ssl` service, certificate chain,
  source-address/firewall policy, and the exact dedicated `read,api,rest-api`
  group behavior against that device.
- Availability of VPN routes or permission to run a monitoring agent/worker.
- Cron frequency limits, command-line PHP version, Composer/Node availability,
  memory limits, process/request timeouts, disk quota, or symlink policy.
- cPanel Apache document-root changes and HTTPS/proxy behavior.

The private RFC1918 router address used locally will not ordinarily be routed
from generic shared hosting. REST changes the application protocol, not the
network path. Do not expose ports 8728/8729 or HTTPS REST publicly to work
around this. The likely viable topology is a supported private VPN route or a
separately reviewed outbound monitoring agent on a network host that already
reaches the routers. See
[MIKROTIK_PRODUCTION_CONNECTIVITY.md](./MIKROTIK_PRODUCTION_CONNECTIVITY.md).

## Implementation verification

Run results from this workspace:

- `php artisan test --compact`: passed, 61 tests / 309 assertions (latest run recorded in
  [CPANEL_DEPLOYMENT_TEST_REPORT.md](./CPANEL_DEPLOYMENT_TEST_REPORT.md)).
- `npm run build`: passed; Vite emitted a large-bundle advisory
  (823.81 kB minified JavaScript).
- `composer check-platform-reqs --no-dev --lock`: passed locally on PHP 8.4.24.
- REST tests use mocked HTTP and cover response mapping, TLS verification
  options/failure handling, authentication, timeout, malformed/oversized
  responses, unavailable router, redirects, destination rejection and
  prohibited commands. No test uses a physical router.
- No tests were run against a physical MikroTik device. Neither cPanel access
  nor cPanel-to-router connectivity was available or attempted.

## RouterOS REST least-privilege prerequisite

The official RouterOS REST manual requires `rest-api` and `api` group policies
in addition to task permissions. It explicitly warns that the default `read`
group contains additional rights, and suggests custom groups. The planned
dashboard-only group is `read,api,rest-api`, with no `write`, `test`,
`sensitive`, `reboot`, or user-management policy. This is a documented
prerequisite, not a permission test against the actual device. Its RouterOS
version and current configuration remain unknown; no terminal import script
has been prepared.

See [CPANEL_DEPLOYMENT_TEST_REPORT.md](./CPANEL_DEPLOYMENT_TEST_REPORT.md) for
the full release test report and unresolved checks.

# cPanel deployment test report

Run date: 2026-10-12  
Environment: local development workspace only; no cPanel account or production
environment was accessed.

## Completed checks

| Check | Result | Notes |
|---|---|---|
| Laravel backend test suite | PASS | Latest full run: `php artisan test`, 52 tests and 264 assertions |
| Frontend production build | PASS | `npm run build`; TypeScript and Vite passed, with a large-chunk advisory (~711 kB minified JS) |
| Full PHP formatting check | PASS | `./vendor/bin/pint --test` |
| Composer locked platform requirements | PASS locally | `composer check-platform-reqs --no-dev --lock` on PHP 8.4.24 |
| RouterOS package lock | PASS | `evilfreelancer/routeros-api-php` 1.7.1 |
| Laravel version | VERIFIED locally | Laravel 13.35.0; Composer constraint PHP `^8.3` |
| Binary API regression coverage | PASS | Existing package/session tests and provider tests pass; dependency retained |
| REST transport security | PASS in mocked tests | TLS verification asserted enabled; TLS failure safely mapped, redirect rejected, request pinned, bounded timeouts and response size enforced |
| REST provider contract | PASS in mocked feature test | Authenticated `/api/routers/{id}/status` retains existing field names and numeric shapes |
| Per-router transport selection | PASS in unit/feature tests | Saved Binary API/API-SSL or REST selection routes through that router's configured transport |
| Gateway route observations | PASS in mocked feature test | Multiple active equal-distance defaults are preserved, identified as ECMP, and do not change the management address |
| PPPoE account/session inventory | PASS in mocked feature test | Configured accounts and active PPPoE sessions are filtered; secret password property is omitted |
| Scheduled collection and stale fallback | PASS in tests | Scheduler registration, per-router lock, persisted metrics, online/offline update, and stale route/metric fallback covered |
| Read-only transport guard | PASS in tests | Prohibited writes and unbounded/other-command REST POSTs rejected before HTTP |
| Router destination policy | PASS in tests | Approved private IPv4 accepted; public IP/unapproved hostname rejected |
| Credential response protection | PASS in tests | API omits password; database value encrypted and decryptable with app key |
| Client offline persistence | PASS in tests | Snapshot and offline status tests are included in suite |
| Deployment / migration to cPanel | NOT RUN | Explicitly outside authorization and no target account supplied |
| cPanel → RouterOS private HTTPS route | NOT VERIFIED | Needs cPanel/provider network details and approved VPN/private routing; no public port exposure attempted |
| Production TLS/session behavior | NOT VERIFIED | Config defaults prepared, but no deployed HTTPS endpoint exists |
| Physical RouterOS REST smoke test | NOT RUN | No router contacted; target RouterOS version, certificate, service and user permissions remain uninspected |
| Real cPanel-to-router smoke test | NOT RUN | No cPanel environment; local operator-reported Binary API connectivity is not a cPanel test |

## Security/regression coverage reviewed

Automated coverage includes existing Binary API regression cases, provider
selection (`mikrotik`, `rest`, unknown), explicit fictional mock labeling,
encrypted/masked router credentials, authenticated API field compatibility,
REST read mapping, TLS verification and TLS failure, authentication failure,
timeouts, malformed and oversized response data, unavailable routers,
unapproved private/public destinations, pinned DNS host, redirects, prohibited
write commands, safe connection-failure logging, production secure-cookie
defaults, and the fixed one-shot monitor POST. REST tests use mocked HTTP; no
physical router was used.

No agent ingestion test is applicable: agent provider/endpoint is intentionally
not implemented. The collection command is tested locally; cPanel cron
execution, duplicate execution across production processes, and router network
reachability are not verified.

Production cookie configuration now defaults to secure cookies when
`APP_ENV=production`, with a cPanel template explicitly setting secure,
HTTP-only, encrypted, same-site cookies. That configuration has not been
verified against a real cPanel proxy or HTTPS request.

## Provider information still required

Ask the cPanel provider:

1. What exact PHP version and configuration are used by the web SAPI and CLI?
   Do both satisfy the release lockfile's platform requirements? Confirm
   `openssl`, `sockets`, `pdo_mysql`, `fileinfo`, `dom`, `iconv`, `json`,
   `libxml`, `ctype`, `filter`, `hash`, `session`, and `tokenizer`. Confirm
   cURL when REST hostname pinning is selected; `mbstring` can be supplied by
   the locked polyfill.
2. Is Composer available? If not, can a compatible locked `vendor/` build be
   uploaded? What Composer version is supported?
3. Can the document root point outside `public_html` to Laravel `public/`?
   Which Apache version and `mod_rewrite`/`.htaccess` overrides are available?
   Are Authorization and XSRF headers forwarded? Are symlinks permitted?
4. Which MySQL/MariaDB version, database backup/restore tooling, and least-
   privilege database grants are available?
5. Is cron available, with what minimum interval, PHP CLI path, CPU/runtime
   limits, and memory limits? Are long-running workers/daemons prohibited?
6. Does the shared host have a permitted private route/VPN from its PHP runtime
   to the router management subnet? Does the provider support outbound HTTPS
   to that private endpoint through the VPN, with its source address and routes
   isolated?
7. If private routing is unsupported, may a trusted outbound-only monitoring
   agent send authenticated HTTPS observations to the application? What
   ingress/rate-limit/WAF constraints apply?
8. What are `memory_limit`, `max_execution_time`, request/body limits, disk
   quota, session persistence/path, writable-directory policy, outbound TLS
   trust-store details, HTTPS termination, and trusted proxy behavior?

Do not answer the network limitation by opening RouterOS API ports or HTTPS
REST to the public internet. REST changes the application protocol but does
not create private routing.

## RouterOS REST prerequisites not yet verified

The official [RouterOS REST API manual](https://help.mikrotik.com/docs/display/ROS/REST+API)
requires `read,api,rest-api` for a read-only REST client and recommends a
custom group because default groups include extra rights. Before enabling
production, inspect the actual RouterOS version and verify the dedicated
account, HTTPS `www-ssl` service, CA-signed hostname-matching certificate,
firewall/source-address restriction, and each allowlisted read operation.
No router import/setup script was prepared: actual device configuration and
version were not available.

If the cPanel account cannot reach the approved private router endpoint, the
production blocker remains. Secure alternatives are a cPanel-supported
isolated VPN/private route or a separately designed outbound monitoring agent
on a trusted network host. No agent or network topology change is implemented.

# cPanel deployment guide

This is a preparation guide only. No deployment has been run. First obtain the
provider facts listed in the final section; do not upload the local `.env`,
local database, or local build without reviewing the release.

For the supplied `onlineconsole.pandatechs.co.ke` cPanel layout, the current
upload-and-browser-migration package procedure is in
[CPANEL_BROWSER_DEPLOYMENT.md](./CPANEL_BROWSER_DEPLOYMENT.md). Do not use the
older scripts under `deploy/cpanel/DEPLOYMENT_STEPS.md`; they target a
different public layout and are not the supported production deployment path.
The package includes a home-root `.htaccess` compatibility route because this
LiteSpeed host was observed serving the account home directory at `/` despite
the nested `public_html/onlineconsole.pandatechs.co.ke` package layout.

## 1. Confirm hosting capabilities

Ask the hosting provider for:

- Exact PHP CLI and web-SAPI versions (PHP `^8.3`), whether they share the
  same configuration, and the Composer lockfile platform-check results for
  both. Confirm `openssl`, `sockets`, `pdo_mysql`, `fileinfo`, `dom`, `iconv`,
  `json`, `libxml`, `ctype`, `filter`, `hash`, `session`, and `tokenizer`;
  `mbstring` can be supplied by the locked polyfill. cURL is required for
  private-address pinning when the REST endpoint uses a hostname, but is not
  a universal Composer requirement.
- Composer availability/version or permission to upload a locally built
  `vendor/` tree using the locked Composer dependencies.
- Whether Node.js is needed on the account (it is not needed at runtime when
  the matching production `dist/` assets are built locally and uploaded).
- MySQL/MariaDB version, database/user creation, least-privilege grants, and
  backup/restore procedure.
- Whether the domain document root may point outside `public_html`; Apache
  version, `mod_rewrite`, `.htaccess` overrides, Authorization/XSRF header
  forwarding, and symlink restrictions.
- Cron minimum interval, supported CLI PHP binary, execution time/memory
  limits, and whether long-running workers are prohibited.
- Whether outbound TCP to private LAN/VPN destinations is possible, VPN policy,
  and the source address/routing method. For REST, specifically confirm
  outbound HTTPS to the router management subnet. Do not expose router ports
  publicly.
- HTTPS certificate/proxy behavior, filesystem quotas, `upload_max_filesize`,
  `post_max_size`, `memory_limit`, and PHP/web request timeout settings.
- PHP file-session path, ownership and persistence; writable private
  `storage/` and `bootstrap/cache/` support; outbound TLS trust store; and
  whether the edge proxy headers can be safely configured without trusting
  arbitrary clients.

Do not proceed with direct live RouterOS connectivity until the provider
confirms a private routed path and a test can be performed safely.

## 2. Build and prepare a release off-production

Use a clean release checkout; preserve local working files and review
`git status` before packaging. Do not include `.env`, database dumps, logs,
`node_modules`, `.git`, private uploads, credentials, or backups.

From repository root:

```sh
npm ci
npm run build
```

The build outputs `dist/`. The current Laravel SPA shell is in
`bradha-matu-api/public/index.html`, which references fingerprinted assets in
`/assets/`. Copy the current release's `dist/index.html` and assets into the
Laravel public asset layout, then verify the HTML asset names point at files
from that same build. Do not mix old and new hashed assets between releases.

From `bradha-matu-api/`, with PHP 8.3+ and Composer:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan test
```

Tests require development dependencies; run them before production
`--no-dev` packaging in a test checkout. If cPanel cannot run Composer safely,
build `vendor/` from the lockfile on a compatible trusted build machine and
upload it as part of the release. Never run Composer scripts with production
secrets in an untrusted build environment.

## 3. Secure filesystem and web root

Preferred layout (adapt paths to the account):

```text
/home/CPANELUSER/community-console/    Laravel app, vendor, .env, storage
/home/CPANELUSER/public_html/          only Laravel public files
```

If supported, point the domain document root directly to
`community-console/public/`. Otherwise put only the contents of Laravel
`public/` in `public_html`, and make a carefully reviewed `index.php` reference
the private application's `vendor/autoload.php` and `bootstrap/app.php` by
absolute path. Keep `.env`, `vendor`, `storage`, `database`, `.git`, logs, and
archives outside the web root. Retain `.htaccess` rewrite and Authorization
header rules. Verify Apache rewrite support with the provider.

Set ownership to the cPanel account/PHP execution user using provider-supported
permissions. Make only `storage/` and `bootstrap/cache/` writable to the PHP
runtime; avoid `777`. Keep logs in private storage. Create
`storage/app/public` and `php artisan storage:link` only if the app actually
serves public uploads; inspect the symlink target and do not link private data.

## 4. Configure environment and database

1. Create the MySQL/MariaDB database and a dedicated user with privileges only
   on that database. Store the password in cPanel's protected environment
   configuration or private `.env` file.
2. Use [../bradha-matu-api/.env.cpanel.example](../bradha-matu-api/.env.cpanel.example)
   as a key reference, not a production secrets file.
3. Set `APP_ENV=production`, `APP_DEBUG=false`, canonical `APP_URL=https://…`,
   persistent database settings, secure cookies, logging, and the approved
   router provider. For cPanel use `ROUTER_PROVIDER=rest`, `MIKROTIK_REST_PORT=443`,
   and `MIKROTIK_REST_ALLOWED_PORTS=443`; these settings are server-side and
   are not browser-selectable. Use `mikrotik` locally to preserve the existing
   Binary API integration, or `mock` only for clearly fictional demo data.
   Generate `APP_KEY` once on the target and back it up
   securely: existing encrypted router credentials depend on the key.
4. Do not run the initial super-admin seeder on every deploy. Set initial
   account credentials only through an explicitly controlled one-time setup
   process and rotate them after initial access.
5. Build frontend assets with same-origin `/api`; do not embed secrets or a
   private router address in frontend build variables.

### RouterOS production prerequisites

The REST provider uses HTTPS only on the configured port and requires a
trusted TLS chain plus hostname validation. RouterOS must have a valid
`www-ssl` certificate, the HTTPS service enabled, and REST support. Follow the
dedicated connectivity document's required custom account policy set
(`read,api,rest-api`), source-address restriction, and test checklist. The
default RouterOS `read` group is not least privilege. Do not expose `www`,
`www-ssl`, 8728, or 8729 publicly to make cPanel connectivity work. Do not run
terminal setup commands until the actual router configuration and RouterOS
version have been inspected; no router import script is provided here.

The provider contract and all current REST mappings are documented in
[MIKROTIK_PRODUCTION_CONNECTIVITY.md](./MIKROTIK_PRODUCTION_CONNECTIVITY.md).
Binary API and REST preserve the same Laravel/frontend API shape; `mock`
remains explicitly labeled fictional data.

Before schema changes, take and verify a database backup. Migrations are not
run by this guide. After explicit operator authorization and a reviewed backup,
the planned command is:

```sh
php artisan migrate --force
```

Do not run `migrate:fresh`, rollback destructive migrations, or import the
local database into production.

## 5. Framework caches and application checks

Once the environment is correct:

```sh
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Ensure runtime-writable directories before serving requests. Do not dump
configuration cache contents into logs or public paths. Confirm the active
web-SAPI configuration separately from CLI.

## 6. Monitoring model

The application includes a read-only `routers:collect` command scheduled once
per minute by Laravel. It records CPU/memory/storage/traffic samples, updates
router last-attempt and last-success state, marks failed polls offline, uses a
per-router lock, and retains 30 days of metric history. The status API serves
the last saved metric and gateway observation as explicitly stale if a live
poll fails. This is periodic collection only when the host actually runs
Laravel's scheduler; it is not started by the browser migration.

If cPanel Cron Jobs is available, add one entry for the private backend path,
substituting the actual account path and PHP CLI binary:

```cron
* * * * * cd /home/ACCOUNT/private/bradha-matu-api && /usr/local/bin/php artisan schedule:run >/dev/null 2>&1
```

Confirm CLI PHP matches the web PHP version and that the job completes within
the provider's runtime limit. Do not run a persistent daemon or multiple
schedule entries. After adding cron, confirm `php artisan schedule:list`,
`php artisan routers:collect`, logs, `last_seen_at`, and new metric rows. A
working cron does not prove cPanel can reach the private RouterOS address; the
router network path must be tested separately. If cron or private routing is
unavailable, follow the separately authorized outbound agent/VPS architecture
described in [MIKROTIK_PRODUCTION_CONNECTIVITY.md](./MIKROTIK_PRODUCTION_CONNECTIVITY.md).

## 7. Release, verification, and rollback

If account storage permits, stage each release in a new private directory,
verify it before switching the document root/release symlink, and retain the
previous release. If symlink/document-root switching is unavailable, upload
to a new private staging directory and arrange a short maintenance window for
an explicit reviewed copy; do not delete or overwrite the previous working
release first.

Post-release checks:

- HTTPS certificate, HTTPS-only login, and secure cookie attributes.
- Login, logout, super-admin authorization, and authenticated API endpoints.
- Public health endpoint returns only status/time/provider and no secrets.
- Build assets load; no stale asset hashes or console/network errors.
- Test read-only UI and confirm forbidden write/terminal routes do not exist.
- Router target has a proven private route, correct source-IP restriction, and
  least-privilege RouterOS user. For REST, confirm the exact `read,api,rest-api`
  policy on a dedicated account and a valid matching TLS certificate.
- Review redacted logs for errors; verify no credentials/tokens are logged.
- Confirm the reported last successful collection time is accurate. Current
  implementation has no production periodic collector.

Rollback and backup handling are in
[DEPLOYMENT_ROLLBACK.md](./DEPLOYMENT_ROLLBACK.md).

## 8. Do not deploy until these are answered

See the provider question list in
[CPANEL_DEPLOYMENT_TEST_REPORT.md](./CPANEL_DEPLOYMENT_TEST_REPORT.md). A
private network path and collection model must be decided before live router
monitoring from production can be claimed.

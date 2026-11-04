# cPanel browser deployment package

Build a release ZIP from a reviewed working tree with:

```sh
bash deploy/cpanel/build-package.sh
```

The generated archive is written to `dist/bradha-matu-cpanel-<UTC timestamp>.zip`.
It contains the Laravel application and production Composer dependencies,
the built frontend, a front controller for the supplied domain document root,
and a one-use browser migration runner. It deliberately excludes `.env`,
SQLite databases, log files, tests, Git data, and local runtime storage. It
includes empty Laravel runtime directories with no local data.

## Before upload

1. Rotate the cPanel database user's password because it was shared in a
   conversation. If that password was reused, rotate each other use as well.
2. Confirm that the database user has privileges only on this application
   database. Database-wide `ALL PRIVILEGES` may be needed for Laravel schema
   migrations; do not grant the user global privileges.
3. Confirm cPanel PHP 8.3+ and the extensions required by Laravel and the
   locked Composer dependencies. Ask the provider to confirm private routing
   or VPN access from cPanel to the router management subnet before expecting
   live monitoring.
4. Make and verify a production database backup before running migrations.

## Upload, extract, configure

1. In cPanel **Domains**, set the document root for
   `onlineconsole.pandatechs.co.ke` to exactly
   `public_html/onlineconsole.pandatechs.co.ke` (relative to the cPanel home
   directory). The live site URL must be
   `https://onlineconsole.pandatechs.co.ke/` — not a URL containing
   `/public_html/onlineconsole.pandatechs.co.ke/`. The React build uses
   root-relative `/assets/...` URLs, so serving the site from a nested URL
   breaks its CSS and JavaScript assets.
2. Upload the ZIP to the cPanel account home directory (not a public web
   directory) and extract it there. The archive places the private Laravel
   application at `~/bradha-matu-api` and the public site files in
   `~/public_html/onlineconsole.pandatechs.co.ke`. Extract hidden files as well
   and allow the package `.htaccess` to be installed. Do not extract the ZIP
   inside the public document root.
3. If the hosting provider will not let you set the domain document root and
   serves the account home directory instead, the included account-root
   `.htaccess` is a compatibility fallback. It blocks direct web access to
   the private backend and ZIP. Never remove these access rules. Prefer the
   exact document root above; verify the canonical root URL and its assets
   before running migrations.
4. In cPanel File Manager, copy `.env.cpanel.example` to `.env` inside the
   private `bradha-matu-api` directory and fill in:
   - `APP_URL=https://onlineconsole.pandatechs.co.ke`
   - the cPanel-issued MySQL database name, username, and newly rotated
     password
   - a unique `SUPERADMIN_EMAIL` and password of at least 16 characters
   - `DEPLOYMENT_TOKEN`, generated with a password manager as a unique random
     value of at least 32 characters
   - `ROUTER_PROVIDER=rest`, `MIKROTIK_REST_PORT=443`, and
     `MIKROTIK_REST_ALLOWED_PORTS=443`
5. Keep `.env` outside the public document root with owner-only access where
   the hosting environment permits. Do not send the database or deployment
   token in a URL, email, or chat.
6. Verify the domain's HTTPS certificate and Force HTTPS in cPanel. The
   migration page refuses plain HTTP.

## Run the browser migration

1. In your browser, open
   `https://onlineconsole.pandatechs.co.ke/web-migrate.php`.
2. Enter the deployment token from the private `.env` using the form. It is
   sent in a POST body, never in the URL.
3. Confirm the database backup and click **Run safe deployment**.
4. The runner verifies production/debug/database/provider settings, creates a
   Laravel application key if blank, tests the DB connection, runs only
   pending `migrate --force` migrations, and creates the initial super-admin
   only if the users table is empty. It then caches Laravel configuration,
   routes, and views.
5. On success the runner consumes the token and removes itself. If PHP cannot
   delete it, remove `web-migrate.php` manually using File Manager immediately.
   The token hash is stored under private `storage/app` so refresh/replay is
   rejected. Failed runs leave diagnostics in private Laravel logs and do not
   expose stack traces or secrets in the browser.
6. Remove the `DEPLOYMENT_TOKEN` line/value from `.env` with File Manager after
   success. The consumed token hash also blocks replay, but the plaintext
   token is not needed by the running application.

The runner has no `migrate:fresh`, rollback, arbitrary command, file upload,
or database credential form. Do not weaken it or leave a reusable token in
`.env`. For a later release, upload the new package, configure a new random
deployment token in `.env`, and use the newly packaged runner once. Keep an
existing production `APP_KEY`; rotating it can make stored encrypted router
credentials unreadable.

## Configure router-initiated telemetry

For production without a verified private route or VPN from cPanel, use the
router's **Telemetry / HTTPS push** profile. This makes an outbound HTTPS POST
to Laravel and does not require cPanel to reach the router or expose RouterOS
management services to the Internet. The existing REST/Binary API profiles
remain separate options for a verified private management path.

The release includes the authenticated telemetry endpoint, its database
migration, stale-data handling, the super-admin credential-issuance UI, and a
locally generated RouterOS script with a deferred Scheduler command. The token is
generated per router, stored server-side only as a SHA-256 hash, displayed
once, and is revocable by issuing a replacement. Never put the token in a
deployment URL, source control, chat, or shell history.

**RouterOS compatibility gate:** The target RouterOS 7.23.1 rejected an
earlier generated script because it attempted to read a DHCP lease `interface`
field that this RouterOS version does not provide. The generator now resolves
the DHCP server's interface instead; this corrected script still requires a
manual run and accepted-report verification on the router. HTTPS POST and
`:serialize to=json` ingestion must also be confirmed before scheduling.
Before generating a replacement, deploy this corrected release and rotate any
telemetry token whose script source was exposed. Treat each downloaded `.rsc`
as a secret: do not paste its source into chat or terminal output.

Do not install the scheduled telemetry script until an authorized operator
has manually verified the public certificate using
`/tool fetch url="https://onlineconsole.pandatechs.co.ke/api/health" check-certificate=yes output=none`
and reviewed/tested the script against the router's exact RouterOS version.
Never disable certificate verification or fall back to HTTP. Confirm the
router clock is synchronized to UTC and that outbound DNS/HTTPS works. The
router configuration page produces a reviewable script; it does not execute
or import it. Importing that file stores the one-shot script only. Run it
manually, verify the authenticated report is persisted, and only then consider
the commented Scheduler command. Its rollback commands are included in the
generated file. Fetch failures log the RouterOS error and response headers;
the telemetry endpoint includes an `X-Telemetry-Error` header with a safe
failure category or validation field names to help diagnose rejected reports.
Do not share the script source or credentials when reporting errors.

After deployment, verify:

- `https://onlineconsole.pandatechs.co.ke/` loads and frontend assets return
  successfully.
- `https://onlineconsole.pandatechs.co.ke/api/health` returns a minimal
  healthy response.
- Sign in with the initial super-admin from `.env`, then rotate the password.
- In the MikroTik Configuration page, create/choose the telemetry profile,
  issue its dedicated credential, and use the generated script only after
  the RouterOS compatibility gate above passes.
- Confirm the first authenticated report is accepted and stored, then verify
  status and observed/received timestamps update in the dashboard.
- Power off Kali and verify that new reports continue arriving. Until this
  is observed against cPanel and the router, production telemetry is not
  verified.

For periodic router polling, configure one cPanel Cron Job to run Laravel's
scheduler every minute, using the actual account path and the provider's PHP
8.3+ CLI binary:

```cron
* * * * * cd /home/CPANEL_ACCOUNT/bradha-matu-api && /usr/local/bin/php artisan schedule:run >/dev/null 2>&1
```

Verify `php artisan schedule:list` from the private app directory and review
the Laravel logs and router `last_seen_at` after the job runs. The cron entry
does not prove that cPanel can reach a private router address; the hosting
provider must confirm and the exact RouterOS route must be tested separately.

This package prepares application code and database schema; it cannot access
your cPanel account, rotate its database password, install router certificates,
configure RouterOS, or prove production connectivity. The minutely cPanel
cron above is for legacy routers polled over an already verified private
route; router-initiated telemetry does not depend on that cron. Deployment
alone does not prove that RouterOS accepts the telemetry script or that cPanel
stores reports successfully.

The browser URL is only for the one-use, token-protected migration runner
included in the uploaded release. It does not accept arbitrary file uploads
or URLs and cannot install application files by itself. Upload/extract the
release ZIP using cPanel File Manager (or an authenticated account-level
transfer such as SFTP) first, then open the runner URL and use the private
deployment token via its POST form. Do not add a public package downloader or
arbitrary web-based file uploader.

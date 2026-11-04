# Deployment: onlineconsole.communitygroup.co.ke

This folder builds an **isolated second instance** of the Bratha Matu console
on the same cPanel account that already serves
`onlineconsole.pandatechs.co.ke`. The pandatechs site keeps its own private
app (`~/bradha-matu-api`) and is not touched by this release.

| | pandatechs (existing) | communitygroup (this release) |
|---|---|---|
| URL | `https://onlineconsole.pandatechs.co.ke` | `https://onlineconsole.communitygroup.co.ke` |
| Private app | `~/bradha-matu-api` | `~/bradha-matu-api-communitygroup` |
| Document root | `~/public_html/onlineconsole.pandatechs.co.ke` | `~/public_html/onlineconsole.communitygroup.co.ke` |
| `.env` / APP_KEY | existing instance's | its own, generated at first migration |
| Database | existing instance's | **must be a new, separate database + user** |
| Migration runner | its own | `web-migrate.php` bound only to the communitygroup app |

The separate app directory name is load-bearing: both the front controller
and the migration runner resolve their backend by directory name, so sharing
`bradha-matu-api` would silently serve or migrate the wrong instance.

## Build the package

From the repository root:

```sh
bash deploy/onlineconsole-communitygroup/build-package.sh
```

Output: `dist/bradha-matu-communitygroup-<UTC timestamp>.zip` containing the
Laravel app with production Composer dependencies, the built React frontend,
the docroot front controller, `.htaccess` files, and the one-use browser
migration runner. It excludes `.env`, databases, logs, tests, and Git data.

## cPanel steps

1. **Select PHP 8.4 first.** The bundled Symfony 8.1 components require
   `php >= 8.4.1`, so in MultiPHP Manager (or *Select PHP Version*) set
   `onlineconsole.communitygroup.co.ke` to PHP **8.4.x**, not 8.2/8.3, and
   enable `curl`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, and `tokenizer`.
   If the docroot stays on an older handler every dynamic path returns a
   `500` whose whole body is `Composer detected issues in your platform:
   Your Composer dependencies require a PHP version ">= 8.4.1"`. while the
   static SPA still loads normally.
   In the same area set `max_execution_time = 300` and `memory_limit = 256M`
   for that domain (MultiPHP INI Editor, or `.user.ini` in the private app
   directory). The migration runner calls `Artisan::call('migrate')` inside
   the web request and does not lift the time limit, so a shared-hosting
   default of 30-60 seconds kills the run partway through. MySQL commits each
   `CREATE TABLE` immediately but the `migrations` row is not written, leaving
   a half-migrated database that then fails every retry with
   `1050 Table 'users' already exists`.
2. **Add the domain with its own document root.** In cPanel → Domains →
   *Create A New Domain*, add `onlineconsole.communitygroup.co.ke`. The
   **Document Root field is relative to the account home** (`/home/CPANEL_USER`),
   so the value must end up as
   `/home/CPANEL_USER/public_html/onlineconsole.communitygroup.co.ke`.
   **Do not tick "Share document root"** with the parent domain: a shared root
   makes the subdomain serve the parent's files, which shows up as every path
   (`/`, `/api/health`, even a nonsense path) returning one identical
   `200` copy of the cPanel default page. Equally, a document root cPanel
   auto-creates but never populates serves an Apache autoindex listing
   containing only `cgi-bin/`. Confirm with `curl` before deploying: `/` must
   return the Bratha Matu `index.html` and nothing else may look like the
   parent site. Issue/verify the HTTPS certificate and enable Force HTTPS
   before going further; the migration runner refuses plain HTTP.
3. **Create a new database and user, then link them.** Use cPanel's
   four-step *Create a New Database* wizard (database name, user name,
   password, privileges), which creates the user and grants it in one pass.
   If you use the separate *Create Database* and *Create User* boxes instead,
   you must still run **Add User To Database** and tick **All Privileges** —
   skipping it is the single most common cause of failure here. The *Current
   Databases* table's user column staying empty proves the grant is missing.
   cPanel prefixes both names with the account (e.g. `cpuser_dbname`) and
   MySQL treats names **case-sensitively**, so copy the strings with the
   *Copy* icon into `.env` rather than typing them; a mistyped name yields
   `1045` (no such user) while a correct-but-unlinked user yields `1044`
   (authenticated, no privileges on that database). The database must be
   empty and dedicated to this instance — never reuse the pandatechs
   credentials, which live on a different account anyway.
4. **Upload and extract.** Upload the ZIP to the cPanel account **home
   directory** (not inside `public_html`) and extract it there, including
   hidden files. This creates `~/bradha-matu-api-communitygroup` (private)
   and `~/public_html/onlineconsole.communitygroup.co.ke` (public). Never
   extract inside the document root. Extracting there nests the site one
   level deeper (`<docroot>/public_html/<domain>/index.html`), leaves the
   private app *inside* the web root, and the front controller then answers
   `503 Application is not configured.` because it refuses an app path below
   `DOCUMENT_ROOT`. Confirm before continuing: open the document root in File
   Manager (Settings → *Show Hidden Files*) and check `index.php`, `.htaccess`,
   `index.html`, `sw.js`, `assets/` and `web-migrate.php` sit **directly** in
   it, that `bradha-matu-api-communitygroup` is in the home directory instead,
   and that the ZIP itself has been deleted (while the document root is wrong
   it is downloadable over HTTPS).
5. **Configure `.env`.** In File Manager, copy
   `bradha-matu-api-communitygroup/.env.communitygroup.example` to `.env` in
   the same private directory and fill in:
   - `APP_URL=https://onlineconsole.communitygroup.co.ke`
   - the new `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
   - a unique `SUPERADMIN_EMAIL` and a password of at least 16 characters
   - `DEPLOYMENT_TOKEN`: a unique random value of at least 32 characters
     generated with a password manager
   Keep `.env` outside the document root; never send its values by URL,
   email, or chat.

## Run the browser migration (one use)

1. Make sure the new database is empty and backed-up policy is met (it is
   fresh, so a backup is trivial; still confirm before clicking).
2. Open `https://onlineconsole.communitygroup.co.ke/web-migrate.php`, enter the
   deployment token (POST body only), tick the backup confirmation, and run.
3. The runner is bound to `bradha-matu-api-communitygroup` and cannot touch
   the pandatechs app. It creates an `APP_KEY` if blank, tests the DB, runs
   only pending `migrate --force`, seeds the first super-admin only if the
   users table is empty, then caches config/routes/views.
4. On success it removes `web-migrate.php` and `deployment-runner.php` and
   consumes the token (hash stored in private `storage/app`; replay is
   rejected). If PHP cannot delete them, remove both manually immediately.
5. Delete the `DEPLOYMENT_TOKEN` line from `.env` afterwards.

## Failure codes on shared hosting

Each response below identifies exactly one layer, so fix only that layer and
re-run rather than re-uploading the package:

| symptom | layer | action |
|---|---|---|
| every path returns the parent site's page | document root shared | give the domain its own root |
| `200` autoindex listing only `cgi-bin/` | document root empty | point the root at the extracted site folder |
| `503 Application is not configured.` | front controller | app is inside `DOCUMENT_ROOT`; extract at home instead |
| `500` body `Composer detected issues in your platform` | PHP version | switch the domain to PHP 8.4 |
| `503 ...DEPLOYMENT_TOKEN of at least 32 characters` | `.env` | set a 32+ character token |
| `1045 Access denied ... (using password: YES)` | MySQL user | name/password wrong; copy the *Current Users* string |
| `1044 ... to database` | MySQL grant | user is not linked to the database; *Add User To Database* + All Privileges |
| `1050 Table 'users' already exists` | half-applied run | drop all tables in that database, raise `max_execution_time`, re-run |
| `1071 Specified key was too long; max key length is 1000 bytes` | storage engine | host defaults to MyISAM; the app pins `InnoDB`, so check no stale config cache |

`1071` matters more than it looks. MyISAM has no foreign keys and no
transactions, so a schema that "succeeds" on it is silently wrong; the pinned
`InnoDB` engine in `config/database.php` is what keeps the two hosts, which
have different server defaults, producing the same schema. If a host still
reports 1071, delete `bootstrap/cache/config.php` and `routes.php` — a cached
config freezes the old value against edits to that file.

The runner cannot drop tables or roll back, by design, so recovering from a
half-applied migration always means clearing the tables in phpMyAdmin first.

## Post-deploy verification

- `https://onlineconsole.communitygroup.co.ke/` loads and `/assets/...` return
  200 (if they 404, the domain docroot is wrong or the site is being served
  from a nested URL).
- `https://onlineconsole.communitygroup.co.ke/api/health` returns a minimal
  healthy response, and it must **not** report the pandatechs app.
- Sign in with the new super-admin, then rotate the password.
- Routers onboarding this console must use its telemetry endpoint
  (`.../api/v1/internal/mikrotik/telemetry` on this host) with credentials
  issued from **this** instance only; tokens are instance-specific because
  only the SHA-256 hash is stored per app.
- Repeat the RouterOS certificate preflight against this host before
  installing any telemetry script:
  `/tool fetch url="https://onlineconsole.communitygroup.co.ke/api/health" check-certificate=yes output=none`
- For periodic polling (legacy private-route routers only), add one cPanel
  Cron Job per instance. This one is separate from the pandatechs cron, and it
  must use the same PHP 8.4 binary the web handler uses (`/usr/local/bin/php`
  is usually a symlink to the account default; `ea-php84` or
  `/opt/cpanel/ea-php84/root/usr/bin/php` is the explicit path on CloudLinux):

```cron
* * * * * cd /home/CPANEL_ACCOUNT/bradha-matu-api-communitygroup && /usr/local/bin/php artisan schedule:run >/dev/null 2>&1
```

## Rollback / removal

This release only adds files under the two new paths and a new domain entry.
To remove it: delete the domain in cPanel, then
`~/public_html/onlineconsole.communitygroup.co.ke` and
`~/bradha-matu-api-communitygroup`, and drop its dedicated database. The
pandatechs instance has no dependency on any of it.

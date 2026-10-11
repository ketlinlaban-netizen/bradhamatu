# Production environment reference

The cPanel template is [../bradha-matu-api/.env.cpanel.example](../bradha-matu-api/.env.cpanel.example).
Copy its keys into a protected production `.env` or the hosting provider's
secret manager. Do not commit or publish the completed file. The existing
local ignored `.env` remains separate and should not be copied to production.

| Setting | Production guidance |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | Canonical HTTPS console origin |
| `APP_KEY` | Generate once on the target environment and protect/back up it; it encrypts router credentials |
| `DB_CONNECTION` | `mysql` for cPanel's provisioned MySQL/MariaDB |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Values issued by cPanel; dedicated least-privilege DB user |
| `CACHE_STORE` | `database` only after migrations create cache and cache-lock tables; alternatively `file` only for one server with writable private storage |
| `SESSION_DRIVER` | `file` for a single-instance shared host with private writable storage; `database` if session table is migrated and DB persistence is preferred |
| `SESSION_ENCRYPT` | `true` |
| `SESSION_SECURE_COOKIE` | `true` (also defaults secure when `APP_ENV=production`) |
| `SESSION_HTTP_ONLY` | `true` |
| `SESSION_SAME_SITE` | `lax` for the current same-origin dashboard/API |
| `QUEUE_CONNECTION` | `sync` for current app, which has no collection jobs; do not configure a worker unless plan support is confirmed |
| `FILESYSTEM_DISK` | `local`; private files remain outside public document root |
| `LOG_CHANNEL` | `daily` |
| `LOG_LEVEL` | `notice` or stricter operationally appropriate level; never log passwords/tokens |
| `VITE_API_URL` | Leave unset for same-origin `/api` with current frontend code, or set `/api` at build time; never set a local URL |
| `ROUTER_PROVIDER` | `rest` only after cPanel confirms a private route and HTTPS reachability; `mikrotik` for local Binary API; `mock` is fictional demo-only. Selection is server-side only. |
| `MIKROTIK_CONNECT_TIMEOUT`, `MIKROTIK_QUERY_TIMEOUT` | Small finite values appropriate to measured network latency |
| `MIKROTIK_ALLOWED_PORTS` | Binary API only, normally `8728,8729`; keep constrained |
| `MIKROTIK_ALLOWED_HOSTS` | Empty when only explicitly validated RFC1918 IP entries are used; use an explicit allowlist for DNS names |
| `MIKROTIK_REST_PORT` | REST HTTPS port, normally `443`; server configuration only, never supplied by the browser |
| `MIKROTIK_REST_ALLOWED_PORTS` | Explicit server-side REST-port approval list; default `443`, never include plain HTTP |
| `MIKROTIK_TLS_CA_FILE` | Optional absolute path in private storage for a trusted CA bundle; REST certificate-chain and hostname verification cannot be disabled |

Local `.env.example` selects the existing Binary API provider (`mikrotik`).
The cPanel template shows HTTPS REST (`rest`) and pins the REST port to
`443`; activate it only after the provider confirms the private route. REST
does not make RFC1918 addresses reachable from shared hosting. See
[MIKROTIK_PRODUCTION_CONNECTIVITY.md](./MIKROTIK_PRODUCTION_CONNECTIVITY.md)
for the transport comparison, exact RouterProvider-to-REST path mapping,
RouterOS account policies, and cPanel connectivity checklist.

`MIKROTIK_API_PROTOCOL`, `MIKROTIK_TIMEOUT`, and
`MIKROTIK_ROUTER_01_*` keys exist in some historical/local templates but are
not read by the current database-backed provider configuration. Router
endpoints and credentials are created in the authenticated application UI/API
and persisted encrypted in the database. Do not assume those old per-router
environment keys configure production.

The frontend build consumes `VITE_*` variables at build time. Build with the
production origin/API path. Laravel runtime secrets are read through Laravel
configuration; application services should use `config()` rather than calling
`env()` directly.

When configuration is cached, edit `.env` first, then run
`php artisan config:cache`. Clear stale config with `php artisan config:clear`
before rebuilding it. Do not print the cached config or `.env` in deployment
logs. Only config files should read environment values.

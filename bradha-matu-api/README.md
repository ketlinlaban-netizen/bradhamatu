# Bratha Matu Laravel API

Laravel 13 backend for the Bratha Matu NOC dashboard. `ROUTER_PROVIDER` is
selected only in server configuration and selects the default transport for
new router configurations:

- `mikrotik` uses the existing native RouterOS Binary API client (local
  development default).
- `rest` uses HTTPS RouterOS REST when the cPanel host has an approved private
  route to the router.
- `mock` previews explicitly fictional demo data and never contacts a router.

Both live transports implement the same `RouterProvider` application contract.
Existing routers select Binary API/API-SSL or HTTPS REST independently in
their saved configuration.
See the repository's
[transport and connectivity guide](../docs/MIKROTIK_PRODUCTION_CONNECTIVITY.md).

## Local setup

```bash
composer install
cp -n .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
```

Set `SUPERADMIN_EMAIL` and a strong `SUPERADMIN_PASSWORD` in `.env`, then run
`php artisan db:seed`. The seeder creates or updates that super-admin account;
never use demo or shared credentials outside a local test environment.

Start Laravel with `php artisan serve`. From the repository root, start the
frontend with `npm run dev`. Configure the frontend API base URL using the
existing Vite environment settings for your local setup.

## Register a MikroTik router

Create the router using the super-admin inventory UI/API. Its host must be an
RFC1918 private IPv4 address; hostnames must be explicitly listed in
`MIKROTIK_ALLOWED_HOSTS` and resolve only to private IPv4 addresses. When that
list is configured, it also restricts private IP literals to listed values.
Only ports 8728 and 8729 are allowed by default.
Credentials are encrypted using `APP_KEY`, masked in responses, and must be
backed up securely along with the key. Rotating `APP_KEY` requires a planned
credential re-encryption before the old key is discarded.

For Binary API, enable only the required API/API-SSL service and restrict it
to the Laravel host address. For REST, enable HTTPS `www-ssl` with a trusted
hostname-matching certificate. RouterOS documents the REST user group
policies as `read,api,rest-api`; create a dedicated group rather than relying
on the default `read` group, which includes additional permissions. Restrict
the user/service to the trusted source address. Certificate verification
cannot be disabled by the application.

Relevant server settings:

```dotenv
ROUTER_PROVIDER=mikrotik
MIKROTIK_CONNECT_TIMEOUT=3
MIKROTIK_QUERY_TIMEOUT=3
MIKROTIK_ALLOWED_PORTS=8728,8729
MIKROTIK_ALLOWED_HOSTS=
# MIKROTIK_TLS_CA_FILE=/path/to/ca-bundle.pem
```

For production REST, select `ROUTER_PROVIDER=rest` as the new-router default
and configure `MIKROTIK_REST_PORT=443` with
`MIKROTIK_REST_ALLOWED_PORTS=443`. The REST port is server-selected, not
browser-controlled. REST validates TLS, disables
redirects, pins the destination and bounds request/response sizes.

RouterOS operations are limited to the shared application allowlist of
read-only commands. REST supports only fixed GET reads and one bounded
one-shot traffic monitor POST; no write verb or arbitrary command is exposed.
Router inventory create/delete operations modify Laravel records only and are
restricted to `super_admin`.

## Scheduled status and metrics

Laravel schedules `routers:collect` every minute. It reads router status and
traffic, stores metric samples for 30 days, and updates online/offline state.
Run `php artisan schedule:run` from the private backend directory every minute
using the host's scheduler (for cPanel, configure one Cron Jobs entry). The
browser migration does not create or verify the cron job. Live polling failure
returns the last saved metric and route observations with an explicit stale
marker; it does not fabricate online status or zeros for unavailable metrics.

## Client inventory

The authenticated `GET /api/clients` endpoint returns the saved inventory
without waiting for a router connection. `GET /api/clients/refresh` polls
enabled routers, updates the inventory by router and MAC address, and marks
clients missing from a successful snapshot offline. A failed router poll leaves
saved state intact, keeps each client's last confirmed online/offline status,
and flags monitoring data as stale until the router can be reached again. The
dashboard refreshes this snapshot when the
Clients page opens, when an administrator requests a refresh, and every minute
while that page remains open.

Wireless client presence is based on the active wireless registration table,
not merely on a still-bound DHCP lease. A DHCP lease cannot override a
previously observed wireless client's offline state. Client records retain
first-seen and last-seen timestamps and the reason the client was marked
offline. In an incomplete wireless snapshot, clients positively observed in
the report are refreshed online; clients absent from that partial report keep
their last confirmed status and are flagged stale. Only a complete snapshot
can mark a missing, previously online client offline.

## Validation

```bash
php artisan test
./vendor/bin/pint --test
npm run build
```

Tests use a local in-memory database and do not contact a physical router.
Successful live connectivity must be verified against a router with the
required API service, network access, read-only credentials, and trusted TLS
certificate (when API-SSL is enabled).

For cPanel preparation, see the repository-root
[`CPANEL_DEPLOYMENT_GUIDE.md`](../docs/CPANEL_DEPLOYMENT_GUIDE.md),
[`PRODUCTION_ENVIRONMENT_REFERENCE.md`](../docs/PRODUCTION_ENVIRONMENT_REFERENCE.md),
and [`MIKROTIK_PRODUCTION_CONNECTIVITY.md`](../docs/MIKROTIK_PRODUCTION_CONNECTIVITY.md).
These documents do not perform a production deployment.

The credential encryption migration is intentionally irreversible: restoring
plaintext credentials via rollback would be unsafe. Restore a protected database
backup and key if a full rollback is required.

# Telemetry inventory deployment report

**Status of the 2026-10-22 telemetry-only update: `DEPLOYED BUT NOT VERIFIED`**

**Latest full-console release attempt: `BLOCKED` before application publish.**

This deployment updated the Laravel telemetry ingestion path. It did not
update the frontend bundle or the RouterOS script/scheduler, and no new
authenticated router report was correlated through database, API, and browser.
Do not treat this partial backend deployment as proof that the live inventory
is fixed.

## Release identity and deployed files

- Branch: `main`
- Base commit: `114d686910596f9edbdc1f26b9a8225624f23877`
- Deployed source was uncommitted at deployment time; the two deployed file
  SHA-256 hashes below identify the exact runtime payload.
- Production backend root:
  `/home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api`
- Production deployment method: authenticated SFTP, staged files beside live
  targets, then same-directory SFTP rename.

| File | Previous production SHA-256 | Deployed SHA-256 |
|---|---|---|
| `app/Http/Controllers/RouterTelemetryController.php` | `91f2c8b26258e35d8b2ff9f245387ea82c51b0e057e664801463c302e82a002a` | `d8b37b8049f5695ffa6199ecb5bd4513d3ead28c2a1eb88b2f6f8458e3886ef6` |
| `app/Services/RouterTelemetryService.php` | `d2951120eb9034d50713e648aab182d70b5960456856fddc891c1db5ef9872e5` | `364c2b5ad972fdd833e9fc836eee3879667eb4a48f8739e443f2d0c7c40d0679` |

The already deployed `ClientInventory.php` and `App/Support/MacAddress.php`
were unchanged by this update. No configuration file, migration, database
record, frontend asset, RouterOS script, or scheduler entry was changed.

## Deployment and backup

- Deployment started approximately **2026-10-22 19:17 EAT** and both files
  were installed by **19:18 EAT**. Post-deployment smoke checks completed at
  approximately **19:19 EAT**; this report was recorded at **19:20 EAT**.
- Verified remote backup directory, outside the document root:
  `/home/pandatechsco/tmp/bradha-p0-update-20261022T1618Z/`
- The backup directory is mode `0700`; the two saved original PHP files are
  mode `0600`. Each was downloaded from production, uploaded to the backup,
  downloaded again, and byte-compared before replacement.
- Pre-deployment backup hashes:
  - Controller: `91f2c8b26258e35d8b2ff9f245387ea82c51b0e057e664801463c302e82a002a`
  - Service: `d2951120eb9034d50713e648aab182d70b5960456856fddc891c1db5ef9872e5`
- The update has no schema change, and no database write or migration was
  performed; therefore no database backup or migration rollback was needed.
- A first attempt to rename the service file had a mistyped destination and
  was rejected without replacing that file. The correct rename then succeeded.
  Both final production files were downloaded and byte-compared to the
  deployed sources.

### Rollback

Restore only the two files listed above. Use the same authorized SFTP account
and the verified backup directory. Download each backup, compare it locally,
upload it beside its target with a `.p0-restore` suffix, then rename the
staged file over the target:

```sh
sftp -b - "$SFTP_USER@$SFTP_HOST" <<'EOF'
get /home/pandatechsco/tmp/bradha-p0-update-20261022T1618Z/RouterTelemetryController.php /tmp/restore-RouterTelemetryController.php
get /home/pandatechsco/tmp/bradha-p0-update-20261022T1618Z/RouterTelemetryService.php /tmp/restore-RouterTelemetryService.php
EOF

# Compare against the verified local pre-deployment copies before continuing.
cmp /tmp/restore-RouterTelemetryController.php /tmp/bradha-live-check/predeploy-20261022T1618Z/RouterTelemetryController.php
cmp /tmp/restore-RouterTelemetryService.php /tmp/bradha-live-check/predeploy-20261022T1618Z/RouterTelemetryService.php

sftp -b - "$SFTP_USER@$SFTP_HOST" <<'EOF'
put /tmp/restore-RouterTelemetryController.php /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Http/Controllers/RouterTelemetryController.php.p0-restore
put /tmp/restore-RouterTelemetryService.php /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Services/RouterTelemetryService.php.p0-restore
rename /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Http/Controllers/RouterTelemetryController.php.p0-restore /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Http/Controllers/RouterTelemetryController.php
rename /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Services/RouterTelemetryService.php.p0-restore /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Services/RouterTelemetryService.php
EOF
```

Stop if either comparison fails. No `.env`, application key, database, or
router file is part of this rollback.

## Automated validation

| Check | Result |
|---|---|
| Full Laravel suite: `php artisan test --compact` | **Passed:** 85 tests, 617 assertions |
| Focused telemetry/inventory suite | **Passed:** 35 tests, 373 assertions |
| Node suite: `node --test tests/*.test.mjs` | **Passed:** 16 tests |
| TypeScript: `npm run typecheck` | **Passed** |
| Frontend: `npm run build` | **Passed**; Vite reported the existing 748 KB JavaScript chunk advisory |
| PHP syntax checks for telemetry controller/service, client inventory, MAC helper | **Passed** |
| Pint on controller, telemetry service, telemetry feature test | **Passed** |
| Pint including `ClientInventory.php` | **Failed on three existing style fixers** in that unchanged file; it was not reformatted or deployed in this update |
| `git diff --check` | **Passed** |
| `npm test -- --run` | Not a valid project script; `package.json` defines no `test` script. The correct Node test command above passed. |

## Production smoke checks

- `GET https://onlineconsole.pandatechs.co.ke/api/health`: **HTTP 200**,
  TLS verification result **0**.
- Anonymous `POST /api/v1/internal/mikrotik/telemetry` with an empty JSON
  body: **HTTP 401**, `Telemetry authentication failed.` Authentication
  remains required; no token was sent or logged.
- Authenticated browser-session requests to the dashboard read APIs
  (`/api/routers/{router}/status`, `/clients`, and `/dhcp-leases`) returned
  **HTTP 401** (`Unauthenticated.`). No authenticated API JSON or database
  rows could therefore be verified in this session.
- The available browser page rendered one recent wireless client row. Because
  authenticated API requests returned 401, that rendering is not accepted as
  evidence of a fresh API response.
- Active production JavaScript is `/assets/index-CwyfIT21.js` with SHA-256
  `d6b43641165a0f09d038653626bf5d7a28ceef157995734b74b248017425c9b8`.
  The local production build generated `/assets/index-BNnwT34S.js` with
  SHA-256 `f9f9de250f20a2134bca6b5f8ff037c680ff6d0e1b8fc2619c41de3f5d90f9b0`.
  They differ. The current frontend diff includes unrelated page changes, so
  no frontend asset was deployed from this mixed worktree.

## Live inventory and pipeline evidence

The four wireless registrations and four matching DHCP leases in the
acceptance prompt are historical operator observations. This deployment did
not access the RB951 and did not trigger a router report. The installed
RouterOS script version/checksum and scheduler were not inspected or changed.
No fresh payload, request/correlation ID, receipt validation result, new
database snapshot, or source-completeness metadata was available.

| Evidence | Expected/reference | Current observation |
|---|---|---|
| Wireless registrations | Historical RouterOS snapshot: 4 | Fresh router count unavailable. Browser rendered one recent row, not independently authenticated. |
| DHCP leases | Historical RouterOS snapshot: 4 | Fresh router count unavailable. Latest previously recorded accepted API snapshot had 1 lease; no fresh authenticated read available now. |
| MAC/IP mappings | Four historical matching pairs from operator evidence | No fresh payload or authenticated API response to verify mappings. |
| Configured IP addresses | Historical: `192.168.88.1/24` on `bridge`, `192.168.40.100/24` on `ether1` | Not re-read from router or verified in a fresh API response. |
| Active default route | Historical: `0.0.0.0/0` via `192.168.40.1%ether1` | Not re-read from router or verified in a fresh API response. |
| Router hardware MAC | Must be a valid selected bridge MAC; never loopback/zero | Fresh router MAC unavailable; no authenticated status JSON. |
| Freshness/observation time | One shared fresh RouterOS observation correlated end-to-end | No new report time, receipt ID, or source timestamps captured. |

The first unverified boundary is **before Laravel receipt**: the actual
installed script and outgoing HTTPS body were not observable through the
available cPanel SFTP and browser access. After deployment, the authenticated
read API boundary is also blocked by HTTP 401 in the available browser
session. No conclusion can be made about current database counts or source
completeness.

## Remaining actions

1. Obtain an authenticated dashboard session through the normal login flow and
   capture status, client, and DHCP API responses without saving cookies or
   bearer tokens.
2. Have an authorized RouterOS operator read the installed script version and
   scheduler, then capture one fresh report using the existing approved
   mechanism. Do not replace the script or change the scheduler as part of
   this report.
3. Correlate that report's snapshot/request ID and source counts through
   Laravel acceptance, persisted snapshot/client/lease data, authenticated
   APIs, and browser rendering.
4. Isolate the telemetry-only frontend hunks on the exact production source
   baseline before updating assets. Do not deploy the current mixed-worktree
   frontend build.

Until those steps pass, do not claim four-client live acceptance or mark the
integration `VERIFIED`.

## Follow-up P0 incident evidence — 2026-10-22 20:07 EAT

### Live pipeline trace

The available authenticated browser session successfully fetched production
APIs using its existing session. No token or cookie was copied into this
report. The live application serves the upgraded hashed JavaScript bundle
`/assets/index-BNnwT34S.js`; its SHA-256
(`f9f9de250f20a2134bca6b5f8ff037c680ff6d0e1b8fc2619c41de3f5d90f9b0`)
matches the local production build. The browser Clients page and authenticated
client API agree: **5 saved identities, 1 online, 0 offline, and 4 unknown /
stale**.

Authenticated production API snapshot for router
`3fdf0dd4-d2ab-4835-9b3a-61e0227318e2`:

- RouterOS identity/version: `RB951`, `7.23.1 (stable)`.
- `telemetryObservedAt`: `2026-10-22T17:06:02Z`;
  `telemetryReceivedAt`: `2026-10-22T17:06:05Z`.
- `GET /api/routers/{id}/status`: HTTP 200; one interface received; no valid
  router hardware MAC; no configured IP address values; no default routes.
  Interface, IP, and route success/completeness indicators are null (not
  reported).
- Wireless source: expected count null, collected/received count **1**,
  success null, complete **false**. The only fresh client was
  `90:E4:68:9C:02:DD`, with no IP or hostname.
- DHCP source: expected count null, collected/received count **1**, success
  null, complete **false**. The only lease was
  `D8:F2:CA:ED:13:31` → `192.168.88.253`, hostname `PANDATECHS`, status
  `bound`. Its source observation timestamp is null, so this lease is not
  treated as fresh.
- `GET /api/routers/{id}/clients`: HTTP 200, 5 identities:
  `D8:F2:CA:ED:13:31` → `.253`/PANDATECHS unknown-stale;
  `C6:70:FE:A6:AA:63` → `.251`/S34 unknown-stale;
  `2A:05:C5:6F:CE:69` → no IP/Unknown Device unknown-stale;
  `90:E4:68:9C:02:DD` → no IP/Unknown Device online with fresh observation;
  `66:B1:BE:26:50:17` → `.249`/Vince-s-A06 unknown-stale.
- `GET /api/routers/{id}/dhcp-leases`: HTTP 200, one lease, incomplete,
  source observed/received timestamps null. The report-level observation and
  receipt times are present.
- At the next client read, `90:E4:68:9C:02:DD` advanced to
  `2026-10-22T17:07:02Z`; it remained the only fresh online identity. The
  other four remained unknown/stale. The browser rendered this same single
  current client.

### First failing boundary and root cause evidence

The Laravel authenticated APIs show that the accepted report itself contains
one wireless record and one DHCP lease, one interface (`lo` in the latest
interface endpoint), no route records, no usable configured-address records,
and no source-completeness flags. The current local generator
[`mikrotikTelemetryScript.ts`](/home/laban/Videos/marto/src/services/mikrotikTelemetryScript.ts)
generates a RouterOS script that always includes the full interface,
configured-address, route, DHCP, and wireless collections plus their
success/completeness/count fields. The accepted production report shape is
therefore incompatible with that generator output. The first proven
discrepancy is before Laravel normalization: the request accepted by Laravel
already has only one wireless row and one lease and lacks those source fields.

The exact sub-boundary cannot be narrowed further because neither the actual
installed RouterOS script/scheduler nor the raw outgoing request body was
available through the authorized access used here. Thus this evidence does
not distinguish an old installed script from a collection/serialization
failure in the running script. No claim is made that the RB951's current live
tables contain four devices; the four/four evidence remains historical.

Laravel normalization and reconciliation did not drop the missing three
records from this report: the accepted report/API lease list contains exactly
the one received lease, and the only current inventory observation is the one
received wireless MAC. The observed mapping results are consistent with the
received report: the fresh wireless MAC has no matching DHCP lease, so no IP
or DHCP hostname is inferred; PANDATECHS is present in a lease but has no
fresh corresponding wireless observation, so it remains historically known
and is not forced online. Current source incompleteness correctly prevents
the omitted identities from being declared offline.

### Narrow backend correction

The legacy-report compatibility path had one directly verified projection
defect: when `ip_addresses` was omitted, a blank string in an interface's
`ip_address` field was treated as one collected configured address even though
the API displayed no address. The source now filters null, non-string, and
blank interface address values. A feature regression asserts that a report
with only an empty interface IP reports zero received/collected addresses and
keeps source completeness unknown.

Changed files:

- `bradha-matu-api/app/Services/RouterTelemetryService.php`
- `bradha-matu-api/tests/Feature/RouterTelemetryTest.php`

The service was deployed by SFTP with a private, verified backup at
`/home/pandatechsco/tmp/bradha-ip-fallback-20261022T1703Z/`. The deployed
service SHA-256 is
`d0011b947f0ca9553f4747b3e65e64b24aa70eb9212e08b254fe4ead202501fb`;
download-back matched the local file. The backup is mode `0700` directory /
`0600` file. No migration, config, frontend, or router change was needed for
this narrow correction.

The next accepted report after this deploy shows `ipAddressCollectedCount=0`,
`ipAddressReceivedCount=0`, and no address values, confirming the correction
is active in the production path. It does not restore missing router
collections.

### Validation and remaining blocker

- Full Laravel suite: **86 passed, 624 assertions**.
- Telemetry feature suite: **20 passed, 289 assertions**.
- Node suite: **16 passed**.
- TypeScript check: **passed**.
- Pint and PHP syntax checks on the changed PHP files: **passed**.
- `git diff --check`: **passed**.
- Production health: HTTP 200 over verified TLS.
- Anonymous telemetry POST: HTTP 401; authentication remains enforced.
- Production upgraded frontend asset hash matches the local build.

**Live inventory remains unresolved.** The first proven failing boundary is
the sender-side accepted payload shape, before Laravel normalization. Obtain
read-only evidence of the installed script version and one sanitized outgoing
payload, or have an authorized operator manually run the reviewed generator
after explicit approval. Do not alter the scheduler or install a RouterOS
script without that approval. The same fresh request must then show complete
wireless/DHCP counts and be correlated through Laravel receipt, persistence,
API, and browser before declaring the incident fixed.

## Full-console release attempt — 2026-10-22 19:29 EAT onward

The requested complete console release was built and validated, but was not
installed over production. The cPanel account's SSH service explicitly
responded that shell access is disabled. The maintained deployment procedure
requires cPanel File Manager to extract the archive, a verified production
database backup, and a new one-use deployment token in the private `.env`
before the browser migration runner may run. This session has no cPanel
File-Manager session, no database-export access, and cannot read or create the
private deployment token. SFTP alone cannot safely extract/atomically install
the complete package or query the migration ledger.

The package includes pending-migration candidates, including
`2026_10_10_000001_encrypt_router_credentials_and_add_monitoring_state.php`.
Its `up()` operation encrypts existing router credentials, and `down()` throws
because credential encryption cannot be safely reversed without restoring a
secure database backup. Production migration state was not queried. Running
the full migration runner without an independently verified database backup
and reviewed migration status would violate the production safety procedure.
No migration was invoked.

### Complete package prepared

- Source branch/commit: `main` /
  `114d686910596f9edbdc1f26b9a8225624f23877`, plus the current mixed
  uncommitted worktree (41 modified tracked paths and 55 untracked paths at
  inspection; the package builder uses the current working source).
- Package:
  `dist/bradha-matu-cpanel-20261022T163128Z.zip`
- SHA-256:
  `98d92d598902f02829378dfbf8e5472a75d43c287754d8db7e73287388478af4`
- Package size: **9.4 MB**; archive integrity check passed, with **7,673**
  entries.
- The archive includes the matched frontend entry point and hashed JS/CSS in
  both the private Laravel public directory and the public document-root
  payload. It excludes `.env`, tests, local SQLite databases, logs, and local
  Git metadata. No production secret was added to the package.
- Package is staged outside the web root at:
  `/home/pandatechsco/tmp/bradha-full-release-20261022T163128Z/`
  with directory mode `0700` and archive mode `0600`. This is private staging,
  not a deployment. SFTP download-back verification completed; the remote
  copy SHA-256 exactly matches the local package hash above.

### Full-candidate validation

- Laravel: **85 passed, 617 assertions**.
- Node: **16 passed**.
- TypeScript typecheck: **passed**.
- Frontend production build: **passed**; existing Vite large-chunk advisory
  remains.
- Composer manifest validation, migration-runner PHP syntax, and
  `git diff --check`: **passed**.
- Supported package build:
  `bash deploy/cpanel/build-package.sh` completed, including another frontend
  build, 14 RouterOS generator tests, production Composer dependency install,
  and archive generation.

### Publish gate and current outcome

No package contents were extracted into production. No public frontend entry
point or asset was replaced by this full-release attempt. The previously
deployed two-file backend telemetry update remains in place. No production
database, `.env`, auth credentials, RouterOS configuration, or scheduler was
changed.

The latest upgraded browser bundle is ready in the package but is not visible
in production. The full console therefore remains **not fully deployed** and
the live telemetry acceptance remains **not verified**. Continue only after
an authorized operator provides the cPanel File Manager/deployment-runner
access, a verified database backup, and migration ledger review. Then follow
the documented one-use runner procedure, preserve existing `.env` and
`APP_KEY`, deploy the whole package atomically, and re-run health, auth, API,
browser, and fresh RouterOS one-shot verification.

## P0 partial-wireless follow-up — 2026-10-22 19:37–19:48 UTC

**Outcome: `DEPLOYED BUT NOT VERIFIED`.** SSH shell access was subsequently
enabled. A narrow backend correction was deployed without installing the
mixed-worktree full-console package, changing the frontend bundle, migrating
the database, or touching RouterOS configuration or scheduler entries.

### Defect corrected

`RouterTelemetryService` preserved incomplete interface, IP-address, route,
and DHCP snapshots, but omitted `wirelessClients`. An incomplete wireless
report could therefore replace the last complete wireless array. Wireless
freshness and the status endpoint also used the report heartbeat timestamp
instead of the wireless source observation. `ClientInventory` then compared
saved clients against that replacement array, while the wireless summary
could count a preserved array as if all its rows came from the latest report.

The corrected behavior retains a previous complete wireless array as
last-good data, stores the current report's rows separately, preserves the
source observation timestamp, and reports incomplete/freshness state
explicitly. Client reconciliation uses only the rows in the current report;
omitted clients are not refreshed by the retained array. The wireless summary
counts current-report rows and returns collection completeness, success,
count, timestamp, and last-good metadata.

This is a real server-side data-loss defect, but it does **not** explain or
restore the missing current registrations. The newest production report still
contains only one wireless row and one DHCP row, with collection metadata
absent. No previously complete wireless source snapshot was available to
restore. The first visible missing-data boundary remains the report already
persisted in Laravel; because raw incoming bodies are not logged, the evidence
does not distinguish an outdated installed RouterOS script from a query,
serialization, or transport issue before persistence.

### Exact deployment and rollback

Deployment used the configured SSH/SFTP connection and same-directory staged
file replacement. Only these production files were changed:

| Production file | SHA-256 after deployment |
|---|---|
| `app/Services/RouterTelemetryService.php` | `f4992e53af788f84dec6195a496c0e6e8ac0d763ebd07de9630e3cebfd0a1d19` |
| `app/Services/ClientInventory.php` | `7b505bc75e6b77b37f146766cf776d3829fd8d9141dfbc002ee292fa0b6ffac4` |
| `app/Services/MikroTikRouterProvider.php` | `99226381c58655c9381131413c0aeabc906a45fd78365067364690ea93b3791e` |

The provider correction was applied to the downloaded production baseline,
not to the unrelated, broadly modified local provider file. The local
`src/types.ts` and test changes are not runtime deployment files; no frontend
runtime code changed, so the existing matched frontend assets were left
untouched.

- The first two files were installed at **19:37:56–19:37:57 UTC**. The
  targeted provider correction was installed at **19:45:59 UTC**. Final
  smoke checks completed at **19:47:52 UTC**.
- Verified backups are outside the web root:
  - `/home/pandatechsco/tmp/bradha-incomplete-wireless-20261022T193703Z/`
    (service and client inventory; original hashes
    `d0011b947f0ca9553f4747b3e65e64b24aa70eb9212e08b254fe4ead202501fb` and
    `43fd38a054f7e4eff9df058ca2401bbb298e0ff661d87a9591af45b652d56f7f`)
  - `/home/pandatechsco/tmp/bradha-wireless-projection-20261022T194456Z/`
    (provider; original hash
    `1e1b0851a217262ce67fbc5b87e38083a8526e9f1e9d928d85e3b9ce17115957`)
- Each backup directory is mode `0700`; backed-up files are mode `0600`.
  All remote backups were hash-verified before replacement; all staged files
  passed PHP syntax checks and were hash-verified before atomic rename.
- Rollback: restore only the three named files from their corresponding
  backup directories. Download each backup, verify its original hash above,
  upload beside its target with a `.p0-restore` suffix, and atomically rename
  it over the target. Then verify hashes, `/api/health`, and authenticated
  read APIs. No schema or database rollback is needed.
- No migration, configuration/cache change, database write by the deployer,
  frontend asset, RouterOS script, or scheduler entry was deployed.

### Validation and live smoke checks

- Focused telemetry feature suite: **21 passed, 316 assertions**.
- Full Laravel suite: **87 passed, 651 assertions**.
- Node test suite: **16 passed**.
- TypeScript typecheck: **passed**.
- Frontend production build: **passed**; the existing Vite large-chunk advisory
  remains. No generated assets were deployed because runtime JavaScript and
  styling did not change.
- Pint on changed service/provider/test files: **passed**. PHP syntax checks
  passed for the telemetry service, client inventory, provider, and feature
  test. `git diff --check`: **passed**.
- Production health after deployment: **HTTP 200**, TLS verification result
  `0`. Anonymous telemetry submission: **HTTP 401**. Authenticated `/status`,
  `/clients`, `/dhcp-leases`, and `/wireless` endpoints: **HTTP 200**.
- Final production hashes match the three deployed payloads in the table.

### Freshest persisted report and API/browser comparison

The latest database snapshot observed after deployment was
`2026-10-22_19_47_02`: RouterOS observation **19:47:02 UTC**, Laravel received
it at **19:47:05 UTC**. The authenticated API read at **19:47:52 UTC** returned
that same observation and receipt time. The database snapshot ID, times,
source counts, and sanitized rows were checked through cPanel PHP 8.4.25;
the API reads used the existing authenticated browser session. No token,
cookie, or raw authentication header was copied to the report.

| Check | Historical reference / expectation | Latest database, API, and browser result |
|---|---|---|
| Wireless registrations | Historical count: 4; not a current guarantee | **1 persisted and received**: `2A:05:C5:6F:CE:69`. Expected count null, collection success null, complete false, source-observed time null. |
| DHCP leases | Historical count: 4; not a current guarantee | **1 persisted and received**: `2A:05:C5:6F:CE:69` → `192.168.88.247`, hostname `HONOR-Play10`, bound. Expected count null, collection success null, complete false, source-observed time null; lease API reports stale. |
| `9C:1C:37:56:5B:35` | Historical `.248` | Not in this report or current client API. No current IP/hostname assertion. |
| `90:E4:68:9C:02:DD` | Historical `.252` | Not in this report; saved client remains unknown with no IP, last seen `19:25:02 UTC`. No historical mapping was applied. |
| `2A:05:C5:6F:CE:69` | Historical `.247` / `HONOR-Play10` | Current source and lease agree: `.247` / `HONOR-Play10`; client API and browser show online, last seen `19:47:02 UTC`. |
| `D8:F2:CA:ED:13:31` | Historical `.253` / `PANDATECHS` | Not a current wireless registration. Saved identity remains unknown, last seen `11:18:02 UTC`; historical lease/IP is not considered fresh. |
| Configured IP addresses | Historical `.1/24` and `.40.100/24` | No configured addresses in the latest snapshot/API. |
| Active default route | Historical `0.0.0.0/0` via `192.168.40.1%ether1` | No route in the latest snapshot/API. |
| Router identity/MAC | RB951; a real nonzero physical MAC is required | Identity `RB951`, RouterOS `7.23.1 (stable)`. Latest interface list contains only `lo`; physical router MAC is null (not loopback or zero). |
| Freshness/completeness | All source tables should be complete and fresh | The report heartbeat is recent, but wireless and DHCP are explicitly incomplete in projection, have no source timestamp, and are not described as fresh. |
| Dashboard counts | Live connected count must follow current complete source data | **5 saved identities, 1 online, 0 offline, 4 unknown/stale**. These are saved-client/status counts, not a claim of four current registrations. Browser row agrees with the current HONOR mapping. |

This establishes persistence, API projection, and browser consistency for the
one received MAC and its lease; it does not establish that the RouterOS tables
currently contain only one entry. The live API does not expose a receipt
correlation ID or raw payload, so `snapshot_id` and `observedAt` are the
available correlation values. A direct RouterOS read-only source snapshot and
installed-script comparison were not available in this execution context.
The known router management HTTPS port was reachable on the local network, but
the protected local RouterOS REST credential configuration was absent; no
unauthenticated or certificate-unverified router request was attempted.

### Remaining action and status

The deployed correction prevents incomplete wireless reports from replacing
last-good wireless inventory or refreshing omitted clients through retained
rows. It cannot make the current report complete. An authorized operator must
provide a fresh sanitized read-only RouterOS snapshot (wireless registrations,
DHCP leases, configured addresses, default route, and interface MACs) and the
installed script's version/collector/count/success metadata with its token
redacted. Do not change the router or scheduler to obtain this evidence.
Compare that evidence with one subsequent persisted report, authenticated API
response, and browser refresh.

**Final status: `DEPLOYED BUT NOT VERIFIED`.** The Laravel partial-report fix
is deployed and live APIs/browser are healthy, but live source completeness
and current RouterOS table counts remain unverified. Do not mark the
four-client incident fixed until a complete fresh RouterOS report traverses
the entire pipeline.

## Operator-provided zero-client evidence and trust-boundary correction — 2026-10-22

The operator ran read-only RouterOS terminal commands to count the active
`/interface wireless registration-table` and `/ip dhcp-server lease` entries
whose status is `bound`. The returned counts were **0 wireless registrations**
and **0 bound DHCP leases**. The accompanying RouterOS log line records a
cloud time correction to `Oct/22/2026 09:34:07`; the terminal transcript does
not independently state when the two count commands were run, so that log
timestamp is not treated as their exact observation time.

The live authenticated API was re-read shortly after the operator supplied
the output. It still accepted a report with **one** wireless row and **one**
bound DHCP lease for `2A:05:C5:6F:CE:69` → `192.168.88.247` /
`HONOR-Play10`. The report's wireless and DHCP collection-success flags,
expected counts, and source timestamps were absent; `wirelessClientsComplete`
was false. The latest correlated report used during verification was
`2026-10-22_20_54_02` UTC, received `20:54:05` UTC. This confirms a
sender/report-vs-router discrepancy. It does not identify whether the
installed sender script is old or whether its collection, serialization, or
transport behavior is faulty; no script export or sanitized outgoing request
body was available.

### Additional correction deployed

The missing source-success metadata was previously treated as enough evidence
to refresh a reported MAC as online. With the direct RouterOS count at zero,
that caused the console to show one online client even though the next report
was unverified. The backend now distinguishes structurally valid rows from
source-confirmed observations:

- A row can refresh inventory/last-seen state only when wireless collection
  success is explicitly true and the source observation is fresh.
- Missing success metadata leaves saved identities unknown/stale and does not
  advance their last-seen timestamps. It does not declare them offline.
- The wireless summary returns an unknown client count when collection was
  not confirmed/fresh. Partial-but-successful reports may show the number of
  rows observed, along with incomplete-source metadata.
- The Router Detail wireless panel now renders an unconfirmed count as
  `Unknown (source not confirmed)` and a confirmed partial count as
  `N observed (incomplete)`.

### Additional deployment, backups, and verification

The source-confirmation backend patch was installed at **20:48:48–20:48:49
UTC**. The guarded SFTP staging command stopped before rename on a local
hash-check quoting mismatch; staged files were then independently inspected
against exact expected hashes and installed only after those checks passed.
No partial production file replacement occurred during the stopped attempt.

The wireless summary contract and matching frontend bundle were installed at
**20:53:59–20:54:07 UTC**, after uploading the new fingerprinted JavaScript
to both production asset directories and verifying the entry-point and asset
hashes. The existing CSS hash matched the new build and was not changed.
The active frontend entry points now reference the same new hashed asset;
the old hash-named bundle was retained, not deleted.

Additional verified rollback backups, outside the web root:

- `/home/pandatechsco/tmp/bradha-zero-client-reconcile-20261022T204747Z/`
  contains the prior production `RouterTelemetryService.php` and
  `ClientInventory.php`; original hashes were
  `f4992e53af788f84dec6195a496c0e6e8ac0d763ebd07de9630e3cebfd0a1d19` and
  `7b505bc75e6b77b37f146766cf776d3829fd8d9141dfbc002ee292fa0b6ffac4`.
- `/home/pandatechsco/tmp/bradha-zero-report-ui-20261022T205244Z/` contains
  the prior provider and both production entry points; original hashes were
  `99226381c58655c9381131413c0aeabc906a45fd78365067364690ea93b3791e` and
  `bc18025c5abf26cb47e40528d78ae1e4282b287fbafed3c90cc96d376cfcdbc9`
  (the entry-point hash was identical at both paths).
  New content-addressed JavaScript assets can be rolled back by restoring
  those two entry points; leave the unused new asset in place.
- Directories are mode `0700`; backup files are mode `0600`. Download and
  verify each named backup against its recorded original hash before staged
  same-directory restore.

Deployed runtime files and hashes:

| File | SHA-256 |
|---|---|
| `bradha-matu-api/app/Services/RouterTelemetryService.php` | `0e6e998cbc46e8076a92f225f5e1fae57328ebf06db69a47c461cdb6fcced85d` |
| `bradha-matu-api/app/Services/ClientInventory.php` | `9c51bac187efecf845347389c6e176a117a3e99b422777da4b22857a6756498b` |
| `bradha-matu-api/app/Services/MikroTikRouterProvider.php` | `80fa9d2a8ae00759baa04d61291439bcda43bc1101ecddba4bd5ef7484d52866` |
| `bradha-matu-api/public/index.html` and document-root `index.html` | `51f8a67218f8548bac4460df6ff3141b5fa6d172f5a2276da7b4e972000c0849` |
| `assets/index-bwEeWSyX.js` in both production asset roots | `3ee27f2345acc1dc5a35953238c7f4d824944af9eccce48b57ae6783a275343f` |

Validation after the source-confirmation change: full Laravel suite **88
passed, 670 assertions**; focused telemetry suite included in that run; Node
suite **16 passed**; TypeScript check and Vite production build passed; Pint,
PHP syntax, and `git diff --check` passed. The pre-existing Vite large-chunk
warning remains. Production `/api/health` returned HTTP 200 with TLS
verification result 0; anonymous telemetry POST returned HTTP 401.
Authenticated `/status`, `/clients`, `/dhcp-leases`, and `/wireless` returned
HTTP 200.

The post-deployment API showed the one incoming wireless row as
**unconfirmed**, `wireless.connectedClients: null`, wireless success null,
completeness false, no source observation timestamp, and the one DHCP lease
as stale. The authenticated Clients API projected **5 saved identities,
0 online, 0 offline, 5 unknown/stale**. The live browser was reloaded with the
new asset and showed those same counts; Router Detail showed `Clients (0
current / 5 saved)` and the Wireless tab displayed
`Unknown (source not confirmed)`. This is intentionally a truthful unknown
state, not proof that zero clients currently exist beyond the operator's
direct point-in-time count.

The router report continued arriving with one row after the correction. The
current source discrepancy therefore remains the first unresolved pipeline
boundary, before or at report collection/transmission. The router was not
configured or modified. Obtain a timestamped read-only RouterOS snapshot and
compare it with the installed script's collector metadata and a sanitized
outbound payload before changing that script.

**Updated final status: `DEPLOYED BUT NOT VERIFIED`.** Misleading online
classification and connected-client count were corrected and verified live.
The underlying router sender/report discrepancy and complete inventory flow
remain unresolved.

## Telemetry sender installation preparation — 2026-10-22 21:07 UTC

The operator explicitly requested installation and attested that on the
RouterOS 7.23.1 RB951:

- `/tool fetch` to the production HTTPS health URL with
  `check-certificate=yes` succeeded.
- `:serialize to=json` produced JSON for a local probe.

Before generating the one-shot sender, the generator was corrected from
version **v1.1.0** to **v1.1.1**. The prior code refused to run unless the
router timezone was exactly `Etc/UTC` and appended `+00:00` to local time. The
new generator reads RouterOS `/system clock get gmt-offset`, encodes the
observed local time with that offset, and does not require changing the
router's clock configuration. It still requires a synchronized clock and
uses `check-certificate=yes` for the POST.

The matching production frontend was built and deployed, with entry points
and hashed JavaScript verified at both active public roots:

| File | SHA-256 |
|---|---|
| `index.html` at both active roots | `a855e91cfb50b2c98615d8d53ff21a568694a474de0857d38e781f8cfa15abb0` |
| `assets/index-BcpsB0gq.js` at both active roots | `bea9afad7302e9dee4f62d1c476e96dd8452827e78f650150edfd7dc0e59db15` |

- Prior active entry points and provider are backed up and verified in
  `/home/pandatechsco/tmp/bradha-generator-timezone-20261022T210701Z/`
  (mode `0700`, files mode `0600`). The prior index hash is
  `51f8a67218f8548bac4460df6ff3141b5fa6d172f5a2276da7b4e972000c0849`;
  prior provider hash is
  `80fa9d2a8ae00759baa04d61291439bcda43bc1101ecddba4bd5ef7484d52866`.
- Generator tests: **14 passed**. TypeScript check and Vite build passed;
  existing large-chunk advisory remains. Production health HTTP 200 with TLS
  verification result 0; new JavaScript asset HTTP 200 and SHA-256 matched.
- Official RouterOS references consulted:
  [Fetch guide](https://manual.mikrotik.com/docs/system-information-and-utilities/fetch/),
  [7.24 Fetch guide](https://manual.mikrotik.com/docs/7.24/system-information-and-utilities/fetch/),
  and [system clock reference](https://manual.mikrotik.com/docs/cli-reference/system/clock/).
  RouterOS 7.23.1-specific runtime evidence is limited to the operator's
  successful HTTPS and serialization probes; a full generated report has not
  yet been executed on that version.

The authenticated production `/mikrotik-config` page was then used to issue
the telemetry credential and render the **v1.1.1** script in the user's
browser. As designed, issuing it revoked the previous telemetry credential.
The generated script contains its one-time credential and is intentionally
not recorded, downloaded by this agent, copied into this report, or included
in logs. Script checks confirmed the generated content includes offset-aware
timestamps, wireless/DHCP success and completeness metadata, certificate
validation, and a manual one-shot step; scheduler creation remains commented
out and is not automatic.

**The generated script has not yet been imported or run on the RB951.** No
RouterOS configuration was changed by this session because there is no
authenticated RouterOS terminal connection available here. The old sender
will fail authentication after token rotation until the generated replacement
is imported. Keep the `/mikrotik-config` browser tab open, click **Copy
script**, paste it directly into the RB951 RouterOS Terminal, and run the
created script once. Do not paste its contents back into chat. Verify the
resulting wireless and DHCP source counts/API snapshot before manually adding
the scheduler. The exact script and scheduler names use router UUID prefix
`3fdf0dd4`; inspect for existing entries before importing, and stop rather
than deleting any collision without reviewing it.

Until a fresh complete one-shot report is accepted and correlated with the
router's current read-only tables, the overall inventory remains
**`DEPLOYED BUT NOT VERIFIED`**.

## v1.1.2 RouterOS route-field validation correction

The first production rejection for the v1.1.1 one-shot report was HTTP 422
with `routes.0.immediate_gateway` as the only validation error. The RouterOS
`immediate-gw` value was being serialized as a non-string; the endpoint
requires a nullable string. The generator now explicitly applies `:tostr` to
that value.

The production generator frontend was rebuilt and published to both active
asset roots on 2026-10-22:

| File | SHA-256 |
|---|---|
| `assets/index-CQ2gyDfs.js` at both roots | `098cda13ab571bc664c70df1c35f4cac6c29ad2ae22c9f81262b0f8baec00a9e` |
| `index.html` at both roots | `88d154a98c3590b7f6b71358220049875b70eb85ca42009263bb43664c69a94a` |
| Existing CSS asset, retained | `assets/index-CcA_1R2g.css` |

Before publication, both production entry points were backed up and verified
in `/home/pandatechsco/tmp/bradha-gw-serialize-20261022T212126Z/` with
directory mode `0700` and file mode `0600`. The entry points previously
referenced the v1.1.1 bundle. The live HTTPS home page, new JavaScript asset,
and `/api/health` returned HTTP 200; TLS verification result was 0 and the
served JavaScript hash matched the local build.

The authenticated production `/mikrotik-config` page was reloaded after
publication. Its generator was verified as v1.1.2 and the generated source
included the string conversion and wireless collector. Generating the new
script rotated the dedicated telemetry credential; the script is available
only in the authenticated browser page and has not been copied into this
report. The operator must copy that current script from the page, manually
replace the specifically named script on RouterOS after reviewing the
existing scheduler reference, and run it once. No RouterOS configuration
changes were made by this deployment.

Validation for this release: 14 RouterOS generator tests passed, TypeScript
typecheck passed, Vite production build passed (existing large-chunk advisory
only), and `git diff --check` passed. This does not establish that RouterOS
7.23.1 executes the revised full script or that a fresh report is accepted.
## v1.1.2 accepted report: collection array shape failure

On 2026-10-23 at 00:32 EAT, after the operator imported and manually ran the
v1.1.2 script, production received an authenticated report. The authenticated
status API reports observed time `2026-10-22T21:32:02Z` and received time
`2026-10-22T21:32:05Z` (00:32:02 and 00:32:05 EAT). This report was accepted,
but the inventory sources were incomplete:

| Source | Router IDs expected | Generator's collected count | Laravel received records | Complete |
|---|---:|---:|---:|---|
| Interfaces | 9 | 9 | 1 | No |
| IP addresses | 5 | 5 | 1 | No |
| Default routes | 10 | 10 | 1 | No |
| DHCP leases | 4 | 6 | 1 | No |
| Wireless registrations | 4 | 5 | 1 | No |

The exact first loss is the RouterOS array construction/serialization
boundary, before Laravel persistence: the generator iterated the expected
RouterOS IDs, but each collection was constructed by comma-concatenating
associative record maps. The observed collected counts equal a single record
map's field count (for example five wireless fields and six DHCP fields),
while the API received one object rather than the expected list. The
controller's legacy single-object normalization then represented that single
object as one record; it did not discard the other RouterOS records. The
fresh authenticated API returned the same one wireless row and one lease
stored in that report.

The received wireless record and lease reconcile to
`2A:05:C5:6F:CE:69` / `HONOR-Play10` / `192.168.88.247`. This is the only
client confirmed by this partial report. The dashboard showed 5 saved, 1
online, 0 offline, and 4 monitoring-stale; those are saved-inventory and
confirmed-state counts, not a claim that only one client is connected.

The generator was corrected to assign each record at an explicit numeric
array key and to use independent collected-record counters for completeness
and limits. The revised v1.1.3 frontend was deployed on 2026-10-22 to both
production asset roots:

| File | SHA-256 |
|---|---|
| `assets/index-Cq0uhTTm.js` at both roots | `6e14d4610256b7c37e2e47ceda8feafc5998ad8aeff28c84edf01018c6a30d5d` |
| `index.html` at both roots | `5e5e4789c1eb78470b3c50fa074069e7e5b8fc5b82fc14b57a23eafe18baf417` |
| Existing CSS asset, retained | `assets/index-CcA_1R2g.css` |

Before publication, both entry points were backed up and hash-verified in
`/home/pandatechsco/tmp/bradha-array-index-20261022T214236Z/` (directory
mode `0700`, files mode `0600`). The live home page, JavaScript asset, and
health endpoint returned HTTP 200 with TLS verification result 0; the served
asset hash matched the local build. The authenticated generator page then
rendered v1.1.3 and was checked for numeric-index insertion in all collector
arrays, `check-certificate=yes`, and the deferred (not automatic) scheduler
instructions. Generating v1.1.3 rotated the router's telemetry token; the
previous v1.1.2 script is no longer authorized.

The focused generator suite passed (**14 tests**), TypeScript typecheck and
Vite build passed (existing large-chunk advisory only), and
`git diff --check` passed.

At a later authenticated API read, the latest stored report was observed at
`2026-10-22T21:45:02Z` and received at `2026-10-22T21:45:07Z`. Its source
counts remained `9/1` interfaces, `5/1` IP addresses, `10/1` routes, `4/6/1`
DHCP (expected/claimed-collected/received), and `4/5/1` wireless. Every
source remained incomplete. The client API and browser showed 5 saved
clients, 1 online, 0 offline, and 4 stale/unknown. The single confirmed
client remained `2A:05:C5:6F:CE:69` / `192.168.88.247` / `HONOR-Play10`.
The other four retained historical identities/IPs and unknown status; their
current connectivity is not confirmed by this partial report.

## v1.1.3 complete production observation

The operator confirmed the harmless numeric-index RouterOS 7.23.1 JSON probe
emitted the expected two-element list. The existing scheduler was inspected
before script replacement and was disabled, with a one-minute interval and
one existing entry; no scheduler changes were made. The operator then
imported and manually ran v1.1.3.

The authenticated production status API accepted a new complete report:
observed at **2026-10-22 21:53:02 UTC (2026-10-23 00:53:02 EAT)** and received
at **21:53:05 UTC (00:53:05 EAT)**. API values were:

| Source | Collection succeeded | Expected | Collected | Received | Complete |
|---|---:|---:|---:|---:|---:|
| Interfaces | Yes | 8 | — | 8 | Yes |
| IP addresses | Yes | 2 | — | 2 | Yes |
| Default routes | Yes | 1 | — | 1 | Yes |
| Wireless registrations | Yes | 4 | 4 | 4 | Yes |
| DHCP leases | Yes | 4 | 4 | 4 | Yes |

The current DHCP lease observations were:

| MAC | IP | Hostname | Lease |
|---|---|---|---|
| `9C:1C:37:56:5B:35` | `192.168.88.248` | Empty in report | bound |
| `90:E4:68:9C:02:DD` | `192.168.88.252` | Empty in report | bound |
| `D8:F2:CA:ED:13:31` | `192.168.88.253` | `PANDATECHS` | bound |
| `2A:05:C5:6F:CE:69` | `192.168.88.247` | `HONOR-Play10` | bound |

The authenticated client API returned six saved clients: the four MACs above
were online with fresh `lastSeenAt` matching the report observation; two
historical clients (`C6:70:FE:A6:AA:63` / `S34` and
`66:B1:BE:26:50:17` / `Vince-s-A06`) were offline because they were absent
from the complete active wireless registration table. None were stale or
unknown. The API and live `/clients` browser page both showed **6 saved, 4
online, 2 offline, 0 monitoring-stale**. Blank hostnames for
`9C:1C:37:56:5B:35` and `90:E4:68:9C:02:DD` remain truthfully unavailable;
their IP addresses were reconciled from fresh DHCP leases. `Unknown Device`
is a display fallback for an empty hostname, not missing MAC/IP telemetry.

This verifies one complete, authenticated, one-shot report and its inventory
projection in production. The scheduler was still disabled at verification
time, so recurring delivery was not yet verified. The operator subsequently
approved resuming the existing one-minute schedule. No authenticated
RouterOS management session is available to this deployment process, so
that approved router-side action remains pending operator execution:

```routeros
/system scheduler enable [find where name="bm-telemetry-3fdf0dd4-schedule"]
/system scheduler print detail where name="bm-telemetry-3fdf0dd4-schedule"
```

After enabling, verify that the single existing entry is enabled and that
fresh accepted reports continue arriving at the configured interval. Until
that repeated delivery is confirmed, outcome is
**`ONE-SHOT VERIFIED; SCHEDULER ENABLE APPROVED, PENDING`**.

## Requested 10-second telemetry and signal/traffic status

The operator requested a 10-second telemetry schedule after observing delay
in reflecting a client disconnect. A two-second interval would consume the
entire 30-request/minute endpoint limit; 10 seconds produces about six
requests per minute for this one router and is within the configured limit.
The frontend generator now offers 10 seconds, retains 60/120/300 seconds,
and generates `interval=10s` only as a deferred scheduler instruction. The
existing router scheduler is still one minute and appears enabled; it must be
disabled manually before issuing a replacement credential because issuance
revokes the currently installed script's token. No new token was issued as
part of this UI change.

Current API inspection at observed time `2026-10-22T22:18:02Z` found four
complete online wireless registrations and all four clients' signal values
were `null`. Per-client RX/TX byte counters were present and changing in the
Clients page; the authenticated traffic endpoint returned aggregate
`currentRxMbps=6.455`, `currentTxMbps=11.393`, and totals. Therefore traffic
counters/rates are functioning; per-client signal is not. The official
[RouterOS registration-table CLI reference](https://manual.mikrotik.com/docs/cli-reference/interface/wireless/registration-table/)
currently documents `signal-strength` as a composite with `strength` and
`rate`, whereas the current sender passes the entire value through `:tonum`.
This is a likely reason the server receives zero/null, but the installed
RB951's RouterOS 7.23.1 value must be inspected read-only before fixing the
extractor; do not assume the current 7.26 reference has identical runtime
behavior.

The 10-second UI update passed the 14 focused generator tests, TypeScript
typecheck, and Vite build (existing large-chunk advisory only), and was
published to both production asset roots:

| File | SHA-256 |
|---|---|
| `assets/index-UidKZ7eU.js` at both roots | `f3b83bb40ac77d6fa61696f5335c325708db06dad43156a9cae8c770073b794a` |
| `index.html` at both roots | `63afca0d36732a8dbd4803a625cfb89fb0380d74f308cfcd9d131466ad5255c3` |
| Existing CSS asset, retained | `assets/index-CcA_1R2g.css` |

Both old production entry points were backed up and verified in
`/home/pandatechsco/tmp/bradha-10sec-ui-20261022T221934Z/` (directory
mode `0700`, files mode `0600`). The live generator page shows the new
10-second option (60 seconds remains selected by default). The new bundle
and health endpoint returned HTTP 200 with TLS verification result 0; the
served hash matched the build. No telemetry token was rotated, and no
RouterOS schedule or script was changed. The existing schedule still needs
to be paused before generating a replacement credential/script. RouterOS
schedule update and repeated 10-second delivery remain unverified.

### RouterOS 7.23.1 wireless signal type and parser

The operator's read-only RouterOS 7.23.1 probe established that
`/interface wireless registration-table get <id> signal-strength` returns a
string such as `-63dBm@HT20-7`, not a number. The previous sender applied
`:tonum` to the entire string; the JSON/API therefore returned null per-client
signal values. The generator now finds the `dBm` marker and converts only
the leading numeric prefix, with zero reserved as the existing unavailable
sentinel (Laravel projects that as null rather than a fabricated signal).
The release is generator v1.1.5. It has passed the focused generator tests,
TypeScript typecheck, and Vite build, but is not yet deployed or verified
against a new RouterOS report. Deployment requires a fresh protected backup;
generating its script rotates the token, so first verify the existing
scheduler is disabled.

### v1.1.5 signal parsing and 10-second script generated

The v1.1.5 frontend was deployed to both production roots after backing up
the active entry points in
`/home/pandatechsco/tmp/bradha-signal-parser-20261022T222744Z/` (directory
mode `0700`, files mode `0600`):

| File | SHA-256 |
|---|---|
| `assets/index-myfM0pCq.js` at both roots | `cc31a3df9fa265c05e447551f640c30bd7e1abdc4be2c985bd247843ae90ec8f` |
| `index.html` at both roots | `f787577e1cbaeb3eeae26c98aa6638183516da4934130606ec50c9d0eba1d918` |
| Existing CSS asset, retained | `assets/index-CcA_1R2g.css` |

The live JavaScript and health endpoint returned HTTP 200 with TLS
verification result 0, and the served bundle hash matched. The authorized
operator confirmed the one-minute scheduler disabled before generation.
Using the authenticated configuration page, a v1.1.5 RouterOS script was
generated with 10-second interval, numeric-index collection arrays,
`check-certificate=yes`, and `dBm` string parsing. The source was verified
without exposing the credential, and a sensitive `.rsc` download was
triggered. This issuance rotated the dedicated telemetry token; the old
installed script is no longer authorized. No router changes were performed
by the deployment process.

The operator then disabled the existing scheduler, replaced the older
installed script with the downloaded v1.1.5 `.rsc`, ran it manually, and
re-enabled the same scheduler at 10 seconds. The operator showed the
pre-import script did not contain the signal parser (`signal-marker-type=nil`)
but did contain the numeric-array marker; it was therefore the older
signal-unaware script. No duplicate scheduler was added.

Production accepted a fresh complete observation at
`2026-10-22T22:41:02Z` (received `22:41:05Z`), with wireless and DHCP each
4 expected / 4 received and complete. The authenticated client API returned
four current clients, all online and fresh, with parsed signal values:

| MAC | IP | Signal |
|---|---|---:|
| `9C:1C:37:56:5B:35` | `192.168.88.248` | -60 dBm |
| `90:E4:68:9C:02:DD` | `192.168.88.252` | -56 dBm |
| `D8:F2:CA:ED:13:31` | `192.168.88.253` | -63 dBm |
| `2A:05:C5:6F:CE:69` | `192.168.88.247` | -57 dBm |

The subsequent API read after an 11-second wait showed a newer accepted
report at `2026-10-22T22:41:22Z` (received `22:41:24Z`), again complete with
4 wireless registrations. This verifies recurring reports at approximately
the requested 10-second cadence across the two observed cycles. The live
Clients page showed 6 saved, 4 online, 2 offline historical clients, 0 stale,
and rendered signal values (e.g. -59, -60, -62, -51 dBm on the page read).
The authenticated traffic endpoint remained HTTP 200 with nonzero aggregate
rates (`6.518 Mbps` RX and `9.498 Mbps` TX on the first accepted v1.1.5
snapshot). Per-client RX/TX byte counters were populated. The app now has
verified one complete signal-aware report and a subsequent fresh scheduled
report; continued reliability should still be monitored over a longer period.

### Per-router onboarding and dual wireless-menu collector deployed

On 2026-10-22, the frontend generator/onboarding update was deployed to both
existing production frontend roots. Only `index.html` and the two newly
hashed assets were activated; Laravel files, router configuration, and the
existing telemetry token were not changed. Prior entry pages are backed up
with mode `0600` under:

`/home/pandatechsco/tmp/bradha-router-onboarding-20261022T230735Z/backup/`

| Production artifact | SHA-256 |
|---|---|
| `index.html` at both roots | `87423c254a7d3afd75b294a46cee8534345ccdec0bb51a392a9348c36583793c` |
| `assets/index-CzoM1Se8.js` at both roots | `65c419dbb3ff3c7b45af217bab2146d186ec1bdd0f76778a6f33fa404b82c0bd` |
| `assets/index-Yqr5KGEW.css` at both roots | `5e17470295a5813b9facf6ea6cd57bf7bdb811f673eaef97f75edcf225e26d66` |

The live HTTPS page returned the new entry page and both assets with HTTP 200.
The authenticated MikroTik configuration page rendered the per-router upload,
import, one-shot, and duplicate-safe scheduler instructions. The live client
page rendered 6 saved clients, 4 online, 2 historical offline, and 0 stale;
its current online rows were recently refreshed. The existing router
continued reporting with its already-installed v1.1.5 script. No new
credential was issued, so this deployment did not invalidate the installed
token.

The generator is now v1.1.6 and feature-detects `/interface wifi
registration-table` before falling back to `/interface wireless
registration-table`. Generated-source tests (14/14), TypeScript/build, and
the focused Laravel inventory feature test (1 test, 29 assertions) passed.
The feature test verifies that two wireless MACs in a complete report are
inserted as new inventory rows. The modern WiFi-package path remains
unverified on physical hardware; before enabling a scheduler on such a router,
manually install/run its router-specific script and confirm complete wireless
and DHCP counts in the live console.

The deployment/browser observation does not prove delivery with the Kali
laptop powered off, nor does it verify a second physical device/browser. No
router commands were run by the deployment. The operator must upload/import
the newly generated per-router `.rsc`, verify a complete accepted one-shot,
and then run the guarded scheduler command shown in the UI. Confirm the
production endpoint continues receiving reports after the laptop is
completely powered off to close the original independence acceptance check.

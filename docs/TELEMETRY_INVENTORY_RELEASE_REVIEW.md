# Telemetry client-inventory release review

**Candidate date:** 2026-10-22  
**Target:** `onlineconsole.pandatechs.co.ke`, router `3fdf0dd4-d2ab-4835-9b9a-61e0227318e2`  
**State:** The scoped backend patch was deployed to cPanel on 2026-10-22.
The RouterOS script/scheduler were not changed. Live four-device acceptance
remains pending; the authenticated dashboard still reports one DHCP lease.
This patch is not a full cPanel application ZIP.

Review archive: `dist/bradha-telemetry-inventory-review-20261022.zip`  
The sidecar `.sha256` verifies the archive; the included `.SHA256SUMS` lists
the source and test files.

## Deployment update — 2026-10-22 15:58 EAT

Deployed only these reviewed backend files via authenticated SFTP:

- `bradha-matu-api/app/Http/Controllers/RouterTelemetryController.php`
- `bradha-matu-api/app/Services/RouterTelemetryService.php`
- `bradha-matu-api/app/Services/ClientInventory.php`

The live versions were downloaded before the update and were diffed against
the candidate. The source diffs were limited to the empty-array validation,
missing DHCP metadata/signal handling, and stale/unknown status changes.
Timestamped backups were uploaded outside the web root to
`/home/pandatechsco/tmp/bradha-inventory-release-20261022T125200Z/`, with
directory mode `0700` and file mode `0600`; each backup was downloaded again
and its SHA-256 matched the pre-deployment source. Candidate files were staged
beside their targets, then replaced with SFTP renames; post-deployment
downloads matched the local candidate hashes.

Backend post-deployment checks:

- `GET /api/health`: HTTP 200, TLS verification result 0.
- Anonymous POST to the telemetry ingestion endpoint: HTTP 401 with the
  expected authentication-failure body; telemetry remains authenticated.
- PHP syntax checks passed and the full local Laravel suite passed again
  (78 tests, 507 assertions).
- The browser session was stale and initially returned API 401 responses.

At the time of the backend-only release, the frontend bundle was withheld
because the working tree contains substantial unrelated frontend changes.
The user later explicitly approved deploying the current full frontend build;
that separate deployment is recorded below.

## Release boundary

This candidate contains the telemetry ingestion, client reconciliation,
status-presentation and RouterOS generator source relevant to the four-client
inventory issue, plus regression tests and this runbook. It contains no
credentials, `.env`, database, production logs, Composer vendor tree or router
script/token.

The repository currently has unrelated staged/unstaged and untracked work.
The supported [cPanel browser deployment procedure](./CPANEL_BROWSER_DEPLOYMENT.md)
builds a complete application package; running it against this working tree
could include unrelated changes. The review bundle therefore deliberately
does not include a frontend `dist/` build and is not directly deployable.
Before making a deployable package, isolate this candidate on a clean release
branch/worktree, review its complete diff, build the frontend there, and use
the supported package builder. Do not upload the review ZIP as an application
release. The currently deployed frontend is the full workspace build because
the user explicitly approved that scope.

No database migration is included for these client metadata/status fixes.
The telemetry ingestion schema and four-client fixture must still be checked
against the actual production migration state before any deployment. No
RouterOS configuration or scheduler change is part of this candidate.

## Candidate contents

| Area | Files |
|---|---|
| Backend | `RouterTelemetryController.php`, `RouterTelemetryService.php`, `ClientInventory.php` |
| Frontend | `types.ts`, `ClientsPage.tsx`, `RouterDetailPage.tsx`, `mikrotikTelemetryScript.ts` |
| Regression tests | `RouterTelemetryTest.php`, `ClientInventoryTest.php`, `mikrotikTerminalScript.test.mjs` |
| Evidence and procedure | `MIKROTIK_PRODUCTION_CONNECTIVITY.md`, `TELEMETRY_RELIABILITY_SPRINT.md`, this document |

Key behavior changes:

- Empty source lists are valid when present; nested-row validation and maximum
  collection sizes remain enforced.
- Known client hostname, address and interface are retained when a later
  wireless registration has no DHCP match.
- A missing signal is `null`, not a synthetic `0 dBm`.
- Stale or incomplete observations report `status: unknown` and retain
  `lastConfirmedStatus` separately.
- Expected, locally collected, and server-received counts remain available
  for diagnosing incomplete source data.

The historical production HTTP 422 is not attributed to the empty-list case
without its safe `X-Telemetry-Error` category. RouterOS 7.23.1 runtime
serialization and HTTPS behavior still require a manual one-shot test.

## Validation recorded for this candidate

- Laravel: 81 tests passed, 540 assertions (latest full suite).
- RouterOS generator: 14 tests passed.
- RouterOS telemetry regression: 15 tests passed, 205 assertions.
- TypeScript/Vite production build: passed with the existing large-chunk
  advisory.
- `git diff --check`: passed. Pint passes on the updated telemetry test; the
  pre-existing `ClientInventory.php` file reports three style fixers outside
  the one-line correction and was not mass-formatted.
- These are local code tests only; they do not independently verify the
  deployed release or prove live four-client acceptance.

## Production backup and rollback plan

Do not deploy until the review gate above is closed and this candidate is
rebuilt from the isolated release worktree.

1. Record the currently deployed release/version and capture the current
   authenticated API responses for the router's clients and DHCP leases.
   Do not save bearer tokens, cookies, or Authorization headers in the release
   record.
2. In cPanel File Manager or authenticated SFTP, create a timestamped,
   owner-only backup directory **outside the document root**. Copy the exact
   current server files that the reviewed release will replace. Verify each
   backup by size and SHA-256 before proceeding.
3. Back up the production database using cPanel's approved database export
   facility, even though this candidate adds no schema migration. Store the
   export outside the document root with restricted permissions; verify that
   the export completed before deployment.
4. Back up the current public `index.html` and every currently referenced
   hashed JS/CSS asset. Keep old hashed assets until post-deployment
   verification has passed. Do not alter `.env`, `APP_KEY`, telemetry
   credentials, or router configuration.
5. Upload the reviewed complete package to a private staging location, verify
   its SHA-256, and extract/install using the documented cPanel procedure.
   Use same-filesystem temporary filenames and rename replacements atomically
   where the release procedure allows. Do not upload this source-review ZIP.
6. If any smoke check fails, restore the exact backed-up backend files and
   public `index.html` using atomic renames; retain old hashed assets. Clear
   only Laravel's application/route/view caches using the authorized cPanel
   deployment procedure. Do not restore or overwrite `.env` or `APP_KEY`.
   Since no schema change is included, do not run a database rollback.
7. Verify health and the authenticated APIs again after rollback. Keep the
   backups until the release is accepted and the rollback window is closed.

If a future isolated release adds migrations, stop and prepare a migration-
specific recovery plan before deploying; do not assume a code-file rollback
also reverses database changes.

## Deployment order and router safety gate

1. Deploy the reviewed backend and frontend release using the supported
   cPanel package procedure. Check the health endpoint and static assets.
2. Sign in and open the existing MikroTik Configuration page. Generate the
   router-specific telemetry script using its dedicated credential. Keep the
   generated `.rsc` private; do not paste it into chat or logs.
3. On the RB951 **RouterOS terminal**, inspect the exact script and scheduler
   names before replacing anything:

   ```routeros
   /system script print where name="bm-telemetry-3fdf0dd4"
   /system scheduler print detail where name="bm-telemetry-3fdf0dd4-schedule"
   ```

   Do not remove, disable, enable, or edit the existing scheduler during this
   release review. Do not change RouterOS management services or permissions.
4. From the RB951, have the authorized operator verify HTTPS certificate trust
   with the existing documented check using `check-certificate=yes`. Do not
   disable certificate verification or try HTTP.
5. Only after the release is approved, manually install/replace the confirmed
   telemetry script and run a one-shot on the RB951. Do not enable or change
   the scheduler until the report is accepted and the acceptance checks below
   pass. No router command is executed by this release artifact.

## Live acceptance checklist

Use an authenticated console session; do not put credentials in URLs or
commands. The following router-scoped endpoints should be checked in the
authenticated application:

- `GET /api/routers/3fdf0dd4-d2ab-4835-9b9a-61e0227318e2/clients`
- `GET /api/routers/3fdf0dd4-d2ab-4835-9b9a-61e0227318e2/dhcp-leases`
- `GET /api/clients`

After the manually triggered one-shot, verify:

- Server receipt and router observation timestamps are newer than the
  one-shot and within the configured freshness window.
- DHCP response reports `collectionComplete: true` and expected,
  collected, and received counts all equal four.
- Each of the four DHCP leases has the correct address/MAC association:
  `192.168.88.248` / `9C:1C:37:56:5B:35`,
  `192.168.88.252` / `90:E4:68:9C:02:DD`,
  `192.168.88.247` / `2A:05:C5:6F:CE:69` (`HONOR-Play10`),
  `192.168.88.253` / `D8:F2:CA:ED:13:31` (`PANDATECHS`).
- The client API returns exactly one client for each MAC, all associated with
  `wlan1`, current `lastSeenAt`, and `online` only when the observation is
  fresh and the collection is complete. Unknown hostnames display as
  `Unknown Device`; they do not lose their MAC/IP identity.
- The authenticated global client API contains those same router-scoped
  identities, with no duplicate rows.
- The dashboard/detail view on a second device reflects the same response.
  A stale or incomplete report must display unknown/stale state, not claim
  current online/offline status.
- Without changing the existing scheduler, confirm a second newer report is
  accepted if a currently configured run naturally occurs. Only after the
  one-shot and second report pass should an operator separately decide whether
  any scheduler change is needed.

### If any device or lease is missing

Stop; do not insert client rows manually or alter dashboard counts. Record the
safe response status and `X-Telemetry-Error` category, receipt/observation
times, and count metadata. Do not record Authorization headers or telemetry
credentials.

Compare the three collection stages: RouterOS source count, script's
pre-serialization collected count, and server-received array count. Then
compare the authenticated DHCP and client API results. If these do not locate
the loss, use a short-lived, access-controlled diagnostic in a private
staging/operations environment to capture the exact request body with the
Authorization header excluded. Restrict access, retention, and file
permissions; remove the capture immediately after diagnosis. Do not enable
unbounded request-body logging in production. Trace the actual report through
validation, normalization, persistence, and API output before changing code.

## Deployment update — frontend completed 2026-10-22

At the user's direction, the current full frontend build was then deployed,
including unrelated local UI changes. The existing production `index.html`
and its referenced hashed JS/CSS were copied to the private backup directory
and downloaded again; their hashes matched. New hashed JS/CSS assets and a
staged `index.html` were uploaded to the actual document root:
`/home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/public_html/onlineconsole.pandatechs.co.ke`.
After verifying staged asset hashes against the local build, the entrypoint
was replaced last via SFTP rename. The prior hashed assets remain available
for rollback.

Public GETs to the root HTML, new JS, and new CSS returned HTTP 200 with TLS
verification result 0; their response hashes matched the built files. The
health endpoint also remained HTTP 200. A cache-busted browser visit rendered
the deployed app; stale historical client rows displayed `unknown` and
`lastConfirmedStatus` details as intended.

The rendered router detail showed a recent telemetry receipt (around one to
two minutes old) but only **one** reported DHCP lease and one recent wireless
client, `90:E4:68:9C:02:DD`, with no IP or signal. Four older saved identities
appeared unknown/stale. However, direct same-origin requests from that browser
to the protected client and DHCP APIs returned HTTP 401 and the browser logged
401 responses. Therefore the displayed rows cannot be treated as an
authenticated current API snapshot; they may be previously fetched client
state retained by the page. This is not the required four-device acceptance.
The operator's four-device RouterOS observation has not been verified in the
authenticated live API.

The RouterOS script was not installed, and the scheduler was not changed.
The existing script's source and raw authenticated request body have not been
verified, so the point where the three omitted entries were lost is still
unknown. Do not manually add clients or change counts; next diagnosis must
compare RouterOS expected, locally collected, and server-received source
counts from an operator-run one-shot.

## Final status

- Review package: prepared locally; not a deployable package.
- Backend: the three files above are deployed and byte-verified.
- Frontend: current full build deployed at the user's direction; the build
  includes unrelated local UI changes. Root HTML and hashed JS/CSS are
  byte-verified via their public responses.
- RB951 script replacement or scheduler change: **not performed**.
- A later authenticated browser-session API inspection succeeded and
  supersedes the earlier expired-session observation below. It returned the
  latest accepted report observed at `2026-10-22T13:51:02Z` and received at
  `2026-10-22T13:51:05Z`:
  - DHCP leases: **1** (`D8:F2:CA:ED:13:31` → `192.168.88.253`,
    `PANDATECHS`); expected count and completeness were `null`, collected and
    received counts were both `1`.
  - Fresh client observations: **1** (`90:E4:68:9C:02:DD`), with no IP or
    signal value. The inventory API returned **5 saved identities** for the
    router, four of them historical/stale. The API evidence does not establish
    a direct database row count independently of the API.
  - Browser-rendered inventory: **5 saved clients**, with one fresh client,
    and **1 DHCP lease**. The API client list and rendered list agree on the
    records available in that session, but not with the four/four router
    observation supplied by the operator.
- RouterOS source-table counts from the operator's read-only observation:
  **4 wireless registrations and 4 bound DHCP leases**, all matching the
  supplied MAC/IP pairs. The actual installed script and scheduler were not
  read, and no raw authenticated HTTPS request body or request ID was
  captured. Therefore the precise sender-side boundary (source collection,
  RouterOS serialization, or request transmission) remains **unproven**.
  The first directly observed server-side discrepancy is the accepted
  snapshot/API showing one DHCP lease and one fresh wireless observation,
  versus the operator-reported four/four RouterOS source tables. There is no
  evidence that the three omitted records were present in the request body,
  so changing Laravel validation or persistence would be speculative.
- Local full-suite execution also exposed a separate inventory-path defect:
  `ClientInventory::recordSnapshot()` indexed the `Collection` returned by
  `persistSnapshot()` as if it were an array containing a `clients` key. It now
  returns that `Collection` directly. The full backend suite passes after
  this correction. This defect could break callers using the regular
  RouterOS-poll snapshot path, but it does **not** explain why the latest
  production telemetry report itself contained one DHCP lease and one fresh
  wireless observation; that sender/request boundary still requires the
  installed-script and request-body evidence described above.
- Live four-client acceptance: **not verified**. The recorded API and browser
  counts are not a successful four-client check. Do not replace the installed
  script, alter the scheduler, or claim production is fixed until an operator
  captures the installed script version and safely verifies the outgoing
  source counts and accepted report.

## Active backend path recheck — 2026-10-22 17:25 EAT

An SFTP-only inspection resolved the public front controller to
`/home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api`.
The live health endpoint returned HTTP 200 over verified TLS. However, the
live `ClientInventory.php` hash is
`0168ba8214b8896d876466e9c210ed1d7bab4e88839e51babadc0d0ac94b7395`, while
the local candidate hash is
`43fd38a054f7e4eff9df058ca2401bbb298e0ff661d87a9591af45b652d56f7f`.
The production implementation's `recordSnapshot()` already returns a
`Collection` directly; the local `['clients']` indexing defect is not present
in that production file. The local controller and telemetry-service files
also differ from the active production copies, and the local
`App\Support\MacAddress` dependency is absent from the active production path.

Therefore the locally tested correction was not safe as a one-file update:
uploading the local inventory service alone would introduce a missing-class
dependency, while the precise local return-value fix was already handled in
production's older implementation. At this point no production file had yet
been uploaded or replaced; the later controlled patch deployment is recorded
below.

## Reviewed backend patch candidate — 2026-10-22

The release boundary is limited to the backend telemetry/inventory code that
differs from the active production source:

- `RouterTelemetryController.php`
- `RouterTelemetryService.php`
- `ClientInventory.php`
- `App/Support/MacAddress.php` (new helper required by the three updated
  classes)

The active production `Router.php`, `NetworkClient.php`, `routes/api.php`, and
`config/mikrotik.php` are byte-identical to the local versions. All nine
telemetry/inventory migration source files present in production are also
byte-identical to the local versions. This patch therefore contains no
migration, Composer change, frontend bundle, or RouterOS file. The database's
Laravel migration-history table was not accessible through SFTP, but this
candidate adds no schema migration. The authenticated telemetry endpoint
already responds in production; that does not establish four-client
acceptance.

The patch archive is limited to those four runtime files, the focused
`RouterTelemetryTest.php`, this review, and a SHA-256 manifest. Deployment is
not automatic: first download and hash-backup the three existing PHP files
outside the document root; stage replacements beside their destinations;
install the new helper and replace the three files; then download and hash
verify all four installed files. If a smoke check fails, restore the three
backups and remove the newly added helper. No database rollback is needed
because no migration is included. Preserve the existing frontend, telemetry
credential, scheduler, and router configuration.

The production account permits SFTP but denies shell access. Consequently
this preparation cannot run cPanel Artisan commands or read migration
history.

## Deployment update — 2026-10-22 17:48 EAT

After explicit approval, the four-file patch was installed over SFTP in
dependency order, using staged same-directory files and atomic renames:

1. Added `app/Support/MacAddress.php`.
2. Replaced `app/Services/ClientInventory.php`.
3. Replaced `app/Services/RouterTelemetryService.php`.
4. Replaced `app/Http/Controllers/RouterTelemetryController.php`.

Before replacement, the three old production files were downloaded, uploaded
to the private directory
`/home/pandatechsco/tmp/bradha-telemetry-patch-20261022T1445Z/` (directory
mode `0700`, files `0600`), downloaded again, and byte-compared with the
captured originals. There is no previous `MacAddress.php` to restore; rollback
is to restore those three backups and remove the new helper. No database
backup was created and no migration was run: the patch has no schema change,
the migration source files were verified identical, and the host denied shell
access. This deviation from the general full-release backup checklist is
recorded; no database data was modified by this patch.

Post-install SFTP downloads matched the candidate SHA-256 hashes:

| File | SHA-256 |
|---|---|
| `app/Support/MacAddress.php` | `925b81830054ac9706d0c0f1759d229c7e3b060a3f8e9769938ded91b7593f2b` |
| `app/Services/ClientInventory.php` | `43fd38a054f7e4eff9df058ca2401bbb298e0ff661d87a9591af45b652d56f7f` |
| `app/Services/RouterTelemetryService.php` | `d2951120eb9034d50713e648aab182d70b5960456856fddc891c1db5ef9872e5` |
| `app/Http/Controllers/RouterTelemetryController.php` | `91f2c8b26258e35d8b2ff9f245387ea82c51b0e057e664801463c302e82a002a` |

Smoke checks after deployment: `GET /api/health` returned HTTP 200 with TLS
verification result 0; an unauthenticated JSON POST to the telemetry endpoint
returned HTTP 401, confirming authentication remains required. The live
authenticated dashboard opened and showed **5 saved clients, 1 reported DHCP
lease**, with the lease snapshot observed/received about **53 minutes** before
the check (`D8:F2:CA:ED:13:31` → `192.168.88.253`, `PANDATECHS`). The client
tab still showed historical/stale identities and an observation about 53
minutes old. Some historical rows rendered `0 dBm`; no new authenticated
four/four telemetry snapshot was available to verify the updated missing-
signal behavior end-to-end.

Therefore deployment is verified, but the target acceptance is not:
production still does not expose the four router-reported clients and four
leases in a fresh accepted report. The installed RouterOS script/scheduler
were not inspected or changed; no new manual telemetry report, request ID, or
raw request body was captured. Do not claim that the live inventory issue is
fixed until a fresh authenticated report shows all four registrations and
leases and the dashboard agrees.

## P0 router-details candidate — 2026-10-22

**State: local only; not deployed.** The RouterOS script and scheduler remain
unchanged. This section records the current candidate and supersedes neither
the deployment history above nor its live acceptance result.

### Proven pipeline findings

- The latest authenticated production API snapshot available during this
  investigation contained one interface (`lo`) with the all-zero MAC, one
  DHCP lease, and one fresh wireless registration. It contained no configured
  IP-address or route observations. Server and router observation timestamps
  were present for the accepted report. This is the server-side received
  evidence; it does not prove what the router collected or put on the wire.
- The local telemetry generator previously collected only the first address
  per interface, dropped its prefix, and did not collect route or full
  configured-address collections. The candidate now independently collects
  interface, IP-address, route, DHCP-lease, and wireless-registration sources
  and includes per-source success, completeness, and record counts.
- The telemetry status response used the REST-poll `gateway_observations`
  field rather than accepted telemetry routes. Therefore a telemetry report
  could contain route information without the status endpoint returning it.
- The interface status path allowed an all-zero interface MAC to be returned
  as a router MAC. The candidate only accepts a valid MAC from the selected
  bridge interface and returns unknown otherwise; it does not substitute a
  client MAC or an arbitrary first interface.
- The received production response does not contain raw request bodies or
  source-count metadata. The actual installed script and scheduler could not
  be inspected using the available cPanel access. Consequently, the exact
  point at which the four historical router observations disappear before
  the server cannot be proven from available production evidence. Do not
  attribute the one-record API snapshot to the current local generator unless
  its installed version and outgoing payload have first been verified.

### Candidate behavior and validation

The local candidate adds independent source collection metadata and preserves
the existing strict authenticated ingestion path, payload size limits,
allowlisted fields, and nested record validation. Complete-but-empty route
data is distinguishable from missing/failed route collection. The route
normalizer retains the destination, gateway, immediate gateway, distance,
active state, and routing table, and extracts the interface from an immediate
gateway such as `192.168.40.1%ether1`. Configured address observations retain
CIDR, network, and interface. Incomplete source reports preserve last-known
complete values and their source timestamps; they do not confirm disconnection.

The regression fixture covers the four reported MAC/IP pairs and matching
DHCP hostnames, client merging, repeated delivery, route parsing, two
configured addresses, all-zero MAC suppression, observation timestamps,
complete-empty versus failed sources, and partial-report preservation.

The review caught and corrected two candidate defects before release:

- CIDR and route custom validation had been nested in the interface iteration.
  An empty interface source therefore skipped those checks. The checks now
  iterate independently, with a regression test for an empty interface array
  and invalid address/route prefixes.
- Per-source freshness booleans were recorded only at receipt. They could
  remain true after the source observation aged past the freshness window.
  Status/interface/lease API reads now derive freshness from each preserved
  source observation timestamp. A time-advance regression test covers route,
  address, and DHCP freshness.

The generated RouterOS script now carries the reviewable marker
`telemetry schema v1; generator v1.1.0`. It is a source version marker, not
proof that this artifact is installed or that RouterOS 7.23.1 executes it
successfully.

The detail view labels client tab counts as current-confirmed versus saved
identities. A DHCP response identifies when its rows are the last complete
snapshot (`usingLastGoodSnapshot`) while its expected, collected, and received
counts continue to describe the latest attempted collection. This prevents a
preserved historical array from being mistaken for the current received
count.

The full telemetry service also currently aggregates traffic totals and rates
by summing every interface counter. On a bridge/VLAN topology, physical and
logical counters may overlap and inflate those aggregates. No universal
authoritative-interface policy is established for this router, so this review
does not change those totals. Confirm the interface-counter aggregation
policy separately before presenting aggregate traffic as unique network
traffic.

Latest local checks after these corrections:

- Laravel: 85 tests passed, 601 assertions.
- Node script/format suite: 16 tests passed.
- `npm run typecheck` and `npm run build`: passed. Vite emitted the existing
  large-chunk advisory.
- Pint on the changed P0 PHP files, PHP syntax checks, and `git diff --check`:
  passed.

These are local code results only. They do not validate RouterOS 7.23.1
serialization at runtime, the installed script version, a fresh telemetry
request, the production database, or the browser-rendered live inventory.

### P0 deployment gate and rollback

Do not deploy this candidate or replace the router script/scheduler until the
complete diff has been isolated and reviewed. For an approved release:

1. Build a clean release worktree containing only reviewed candidate changes;
   record the source revision and artifact hashes.
2. Record the deployed versions and capture sanitized authenticated API
   responses for router status, clients, and DHCP leases. Never persist
   Authorization headers or telemetry credentials.
3. Back up every production file to a private directory outside the web root,
   restrict directory/file permissions, then download the backups and verify
   their hashes. Back up the database using the authorized cPanel database
   export procedure before any release that changes schema or persistent
   data; this candidate has no migration.
4. Stage each reviewed file beside its destination and install with atomic
   renames. Verify installed hashes and HTTP health/authentication behavior.
   Do not run broad deployment tooling from this dirty workspace.
5. Keep the existing router scheduler unchanged. First review the generated
   RouterOS script, then perform one operator-triggered report only after the
   Laravel release is approved and ready to receive it. Do not put the
   telemetry token in logs, screenshots, or shell history.
6. Roll back by restoring the verified backups with staged same-directory
   renames and clearing only the relevant Laravel application/route/view
   caches through authorized cPanel tooling. No database rollback is needed
   for this no-migration candidate. Keep the router script and scheduler
   unchanged during application rollback.

Live acceptance remains blocked until a fresh, sanitized report or equivalent
operator evidence supplies a request/snapshot ID and proves collected,
transmitted, accepted, stored, API-returned, and browser-rendered counts for
wireless registrations, DHCP leases, routes, and configured addresses. The
four/four values, route through `192.168.40.1%ether1`, bridge MAC, two IP
addresses, and observation timestamp previously supplied by the user are
historical evidence, not a fresh acceptance test.

## P0 review audit and manual verification procedure

### Isolated-release boundary

The candidate file-by-file scope and isolation gates are summarized in
[TELEMETRY_P0_RELEASE_MANIFEST.md](./TELEMETRY_P0_RELEASE_MANIFEST.md). That
manifest is preparation only; it is not a deployable archive or approval to
deploy.

The current checkout is not a clean release baseline: it contains broad
tracked and untracked changes, and the committed `HEAD` does not contain the
telemetry controller, service, migration/model dependencies, or their
regression tests. The tracked `MikroTikRouterProvider.php`,
`RouterDetailPage.tsx`, and `types.ts` also have large unrelated diffs from
prior work. A package made directly from this working tree would mix unrelated
changes; a worktree at `HEAD` would omit required Laravel telemetry code.
Therefore no deployable release archive is being represented as prepared.
Before deployment, rebase/port the reviewed P0-only changes onto the exact
clean production source baseline, preserving all dependencies, then build and
test that isolated worktree. Exclude the unrelated UI/configuration, transport,
alias, packaging, build-output, and documentation changes from this incident's
release unless separately approved.

The reviewed dependency manifest must include the existing telemetry
credential workflow in `src/pages/MikroTikConfigPage.tsx` and
`src/components/MikroTikTerminalScriptGenerator.tsx`, their API/type
dependencies, the telemetry schema migration
`bradha-matu-api/database/migrations/2026_10_12_000004_add_router_telemetry_ingestion.php`,
and the required backend router/provider wiring. Those files are currently
mixed with broader uncommitted transport/configuration work; do not take the
whole files as a P0 patch without isolating and reviewing their unrelated
hunks.

### Exact file backup and rollback commands (SFTP-only cPanel account)

Run these from a trusted local shell after the clean release manifest has
been approved. Populate `SFTP_USER` and `SFTP_HOST` from the operator's
credential manager; use key-based SFTP or its interactive prompt, not a
password in shell history. Confirm the remote paths against the approved
manifest before running any batch:

```sh
umask 077
test -n "$SFTP_USER" && test -n "$SFTP_HOST"
BACKUP="$HOME/bradha-p0-backup-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 700 "$BACKUP"
STAMP="${BACKUP##*-}"
REMOTE_BACKUP="/home/pandatechsco/tmp/bradha-p0-release-$STAMP"
mkdir -m 700 "$BACKUP/server-backup"
```

For the known document root, the active backend root is
`/home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api`.
The following is the exact SFTP batch shape for the two telemetry PHP files;
repeat only for additional PHP files explicitly listed in the approved
manifest:

```sh
sftp -b - "$SFTP_USER@$SFTP_HOST" <<EOF
mkdir $REMOTE_BACKUP
chmod 700 $REMOTE_BACKUP
get /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Http/Controllers/RouterTelemetryController.php $BACKUP/RouterTelemetryController.php
get /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Services/RouterTelemetryService.php $BACKUP/RouterTelemetryService.php
put $BACKUP/RouterTelemetryController.php $REMOTE_BACKUP/RouterTelemetryController.php
put $BACKUP/RouterTelemetryService.php $REMOTE_BACKUP/RouterTelemetryService.php
get $REMOTE_BACKUP/RouterTelemetryController.php $BACKUP/server-backup/RouterTelemetryController.php
get $REMOTE_BACKUP/RouterTelemetryService.php $BACKUP/server-backup/RouterTelemetryService.php
chmod 600 $REMOTE_BACKUP/RouterTelemetryController.php
chmod 600 $REMOTE_BACKUP/RouterTelemetryService.php
EOF
cmp "$BACKUP/RouterTelemetryController.php" "$BACKUP/server-backup/RouterTelemetryController.php"
cmp "$BACKUP/RouterTelemetryService.php" "$BACKUP/server-backup/RouterTelemetryService.php"
sha256sum "$BACKUP/RouterTelemetryController.php" "$BACKUP/RouterTelemetryService.php" \
  > "$BACKUP/pre-release.sha256"
```

Stop on any mismatch. Stage each approved new file beside its live target with a
`.p0-new` suffix, download and hash-verify it, then use SFTP `rename` within
that same directory to install it. Do not use wildcard uploads or replace
`index.php`, `.env`, application keys, telemetry credentials, or router
files. Capture exact old/new hashes in the release record. No production
action is authorized by this procedure. Before the first production rename,
verify on a non-production path that the host's SFTP server supports
same-directory replacement rename; if it does not, use the approved cPanel
deployment runner rather than deleting a live target to make room.

For rollback, use the same SFTP connection and exact remote backup directory:

```sh
sftp -b - "$SFTP_USER@$SFTP_HOST" <<EOF
get $REMOTE_BACKUP/RouterTelemetryController.php $BACKUP/restore-RouterTelemetryController.php
get $REMOTE_BACKUP/RouterTelemetryService.php $BACKUP/restore-RouterTelemetryService.php
EOF
cmp "$BACKUP/RouterTelemetryController.php" "$BACKUP/restore-RouterTelemetryController.php"
cmp "$BACKUP/RouterTelemetryService.php" "$BACKUP/restore-RouterTelemetryService.php"

sftp -b - "$SFTP_USER@$SFTP_HOST" <<EOF
put $BACKUP/restore-RouterTelemetryController.php /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Http/Controllers/RouterTelemetryController.php.p0-restore
put $BACKUP/restore-RouterTelemetryService.php /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Services/RouterTelemetryService.php.p0-restore
rename /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Http/Controllers/RouterTelemetryController.php.p0-restore /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Http/Controllers/RouterTelemetryController.php
rename /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Services/RouterTelemetryService.php.p0-restore /home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke/bradha-matu-api/app/Services/RouterTelemetryService.php
EOF
```

Compare each restore file with its private backup before running the `rename`
commands. Repeat only for manifest entries actually deployed. Keep backups
private and do not restore the database: this candidate has no migration.
Use only the approved cPanel cache-clear procedure if required; do not run
shell commands on the cPanel host because the account has no shell access.

### Operator-run single-cycle evidence collection (after review approval)

No command below is to be run now. Do not change the production scheduler.
Use an authorized local RouterOS terminal and the approved release artifact:

1. Capture the router version and identity using read-only commands:

   ```routeros
   /system resource print
   /system package print where name="routeros"
   /system clock print
   ```

2. Record the actual installed telemetry script and scheduler names and their
   source, without changing either:

   ```routeros
   /system script print detail where name="bm-telemetry-3fdf0dd4"
   /system scheduler print detail where name="bm-telemetry-3fdf0dd4-schedule"
   ```

   The source may contain the dedicated telemetry token. Keep the raw source
   only in the restricted operator record; redact that token before sharing.
   Confirm the script marker is `telemetry schema v1; generator v1.1.0` and
   compare its read-only collection and HTTPS code with the reviewed artifact.
   Record the scheduler interval but do not alter it. If the installed code
   differs, stop and document the exact mismatch; do not silently replace it.

3. Only after the script has been approved and RouterOS 7.23.1 compatibility
   has been manually reviewed, make a temporary, manually run diagnostic copy
   of the reviewed script with one addition immediately after JSON creation:
   `:put $json`. Do not add the token to that output, RouterOS logs, or the
   saved evidence. Run that copy once with no scheduler entry and capture the
   terminal's JSON privately with permissions `0600`. This is a proposed
   operator action requiring separate approval; do not edit or run it now.
   The printed body must contain schema version, snapshot ID, observation
   time, all source success/completeness/count fields, arrays, and no
   Authorization header. Keep MAC/IP/name details restricted; redact them
   from any shared copy only after matching the records locally.

4. Let that same one-shot script submit the identical JSON to the configured
   HTTPS endpoint with `check-certificate=yes`. Record the snapshot ID,
   server response status, and `X-Telemetry-Correlation-ID` if available.
   The Laravel response/log evidence must show that same ID. Never copy the
   bearer token into curl, shell history, screenshots, or logs. Do not infer
   acceptance merely from a completed `/tool fetch`.

5. In the authenticated app/API session, inspect:

   - `GET /api/routers/3fdf0dd4-d2ab-4835-9b9a-61e0227318e2/status`
   - `GET /api/routers/3fdf0dd4-d2ab-4835-9b9a-61e0227318e2/dhcp-leases`
   - `GET /api/routers/3fdf0dd4-d2ab-4835-9b9a-61e0227318e2/clients`
   - `GET /api/clients`

   Capture response bodies from the signed-in browser's Network panel without
   exporting request headers or cookies. Correlate by snapshot ID and verify
   router observation and server receipt timestamps. In the DHCP response,
   compare the actual lease array with the four historical MAC/IP pairs; in
   the client response, require four distinct MAC identities and matching
   IPs/interfaces. Require route `0.0.0.0/0` active via gateway
   `192.168.40.1`, immediate gateway `192.168.40.1%ether1`, and configured
   addresses `192.168.88.1/24` on `bridge` and `192.168.40.100/24` on
   `ether1` only if the fresh payload reports them. Do not hardcode expected
   live values into application data.

6. In phpMyAdmin, read the row in `routers` for the router UUID and correlate
   `telemetry_snapshot_id`, `telemetry_observed_at`, `telemetry_received_at`,
   and `latest_telemetry`. Then read `network_clients` for that `router_id`,
   selecting `mac_address`, `ip_address`, `hostname`, `interface_name`,
   `status`, and `last_seen_at`. Compare the four persisted identities and
   addresses to the same snapshot. Do not edit rows.

7. Open the existing router detail/dashboard from a second device, perform a
   normal refresh after the API cache TTL, and compare the displayed source
   counts, MAC/IPs, route/interface, addresses, and observed/received times
   against that same snapshot. If any stage disagrees, stop and report the
   first boundary; do not trigger the scheduler, modify Wi-Fi, insert rows, or
   alter counts.

The final evidence record must separately state RouterOS source counts,
serialized payload counts, Laravel accepted counts, database counts, API
counts, and browser counts. A new report can establish the current number of
clients; the four/four requirement is satisfied only if that same fresh
report actually contains four wireless registrations and four DHCP leases.

## Deployment update — 2026-10-22 19:20 EAT

The telemetry controller and service were updated through SFTP with verified
backups and post-install SHA-256 comparison. The exact deployed hashes,
backup directory, test results, smoke checks, and rollback steps are recorded
in [TELEMETRY_INVENTORY_DEPLOYMENT_REPORT.md](./TELEMETRY_INVENTORY_DEPLOYMENT_REPORT.md).
The frontend bundle and RouterOS script/scheduler were not changed. Health
returned HTTP 200 and anonymous telemetry POST remained HTTP 401, but the
available browser's authenticated read APIs returned HTTP 401 and no fresh
router payload was captured. Status remains **DEPLOYED BUT NOT VERIFIED**;
do not claim live inventory acceptance.

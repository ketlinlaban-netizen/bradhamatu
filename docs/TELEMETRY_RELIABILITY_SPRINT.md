# Telemetry reliability sprint

## Status and scope

This sprint corrects stale-status semantics and adds regression protection. It
does not change router configuration or rotate credentials. A scoped,
backend-only PHP release was deployed to cPanel; the frontend bundle was not
rebuilt or replaced because the workspace contains broader unrelated changes.

**Production status: backend deployed and file-verified; recurring telemetry
and dashboard behavior remain unverified.**

## Confirmed causes and evidence

1. **RouterOS scheduling was running, but delivery failed.** New operator
   output shows repeated script warnings `failure: resolving error
   (/tool/fetch; line 126)` at approximately five-minute intervals from
   2026-10-20 through 2026-10-21. This is evidence the script was being
   invoked repeatedly, but the router could not resolve the HTTPS endpoint
   for those attempts. The output's first command `print where script=...`
   was invalid because it omitted the `/system script` menu, so it does not
   verify the script job list.
2. **A later Fetch reached the application and got HTTP 422.** The final
   warning in the submitted log is `Status 422, Unprocessable Entity` at
   2026-10-21 18:23:33. This proves that particular request got past DNS and
   HTTPS far enough to receive an HTTP response; it does not identify the
   validation/replay/clock error. The exact response category or validation
   field was not included, so the 422 cause remains unconfirmed.
3. **The scheduler was then explicitly enabled and changed to one minute.**
   The last submitted command
   `/system scheduler set [find where name="bm-telemetry-3fdf0dd4-schedule"] interval=1m disabled=no`
   returned to the prompt without an error. This is evidence the set command
   was accepted, but there is no later scheduler print or production receipt
   timestamp proving repeated delivery after the change.
4. **Previously observed cadence exceeded freshness.** Laravel's
   `MIKROTIK_TELEMETRY_STALE_SECONDS` default is 180 seconds; the repeated
   attempts were roughly five minutes apart. Those gaps exceed the freshness
   window even when the Scheduler invokes the script. Changing the cadence
   alone will not correct DNS or HTTP 422 failures.
5. **The dashboard overstated what stale telemetry proves.** Before this
   sprint's local changes, a telemetry router with no report inside the
   freshness window was mapped to `offline` and generated a critical incident
   saying it was “offline and unreachable.” Outbound telemetry cannot
   establish inbound reachability.
6. **Wireless registration data was incomplete.** The shared Clients page
   showed three saved clients and stale monitoring; the operator reported
   Vince-s-A06 as the only offline client and the other two as online. Earlier
   telemetry marked the wireless collection incomplete and reported fewer
   clients than expected. The latest pasted RouterOS check reports four
   current entries in `/interface wireless registration-table print count-only`.
   This count does not prove those four entries were successfully serialized
   and accepted in telemetry. Missing clients in a partial report are not
   proof of disconnection.
7. **There is a separate old router record.** The shared Reports page showed
   another “RB951” record at the same private IP with a last-seen age of
   20,746 days. This is not evidence about the outbound-telemetry record and
   must be reviewed separately before changing or removing it.

The latest router evidence covers failures through 2026-10-21 and a scheduler
update command afterward. No subsequent scheduler status or production
telemetry receipt was available during deployment verification. Therefore,
the scheduler's current run state, whether one-minute reports now succeed,
the latest accepted production report, and current client report cannot be
asserted. The five-minute interval between failed invocations can be
observed; a successful recurring report interval cannot be measured.

## Behavior changed

- A fresh accepted telemetry report makes a telemetry router `online` (or
  `degraded` when its current CPU value exceeds the existing threshold).
- A router that has never submitted a report is `unknown`.
- A router with a previous report older than the configured freshness window
  is `stale`, not `offline`. Its stale incident is a warning and explicitly
  says reachability is unconfirmed.
- Confirmed `offline` remains available for polling transports where a live
  connection failure is actually observed.
- Dashboard counts and the router list show stale as a separate state.
  Unknown and stale routers no longer inflate the online client/traffic
  totals or the measured-availability denominator.
- Accepted router observation time is used for client `first_seen_at` and
  `last_seen_at`; server receive time remains separate for report freshness.
- Older client observations cannot overwrite a newer client record or mark a
  newer observation offline.
- Partial wireless results update clients positively observed, preserve
  omitted clients, and flag unconfirmed states. A physically complete but
  too-old wireless snapshot is not trusted to mark omitted clients offline.
- DHCP lease snapshots now expose stale status and the detail page labels
  their age rather than presenting them as current.

The default report freshness window is now 15 seconds; it was not increased
to conceal a scheduling failure. Only a complete, sufficiently fresh
wireless observation may mark an unreported online client offline.

## Files changed

- `bradha-matu-api/app/Services/MikroTikRouterProvider.php` — distinguish
  stale/unknown telemetry from confirmed offline; correct incident wording,
  summary counts, and stale client/traffic totals.
- `bradha-matu-api/app/Http/Controllers/MikroTikConfigController.php` —
  return waiting rather than success for stale/unknown telemetry and expose
  unknown health when no report has ever arrived.
- `bradha-matu-api/app/Services/RouterTelemetryService.php` — carry
  server-receipt and router-observation times separately; determine whether
  wireless data is fresh enough to be trusted; mark lease snapshots stale.
- `bradha-matu-api/app/Services/ClientInventory.php` — enforce monotonic
  client observations and freshness-aware complete-snapshot updates.
- `bradha-matu-api/tests/Feature/RouterTelemetryTest.php` — cover stale and
  never-reported router status, incident wording, old complete wireless data,
  and atomic rollback on persistence failure.
- `bradha-matu-api/tests/Feature/ClientInventoryTest.php` — cover old
  observations not overwriting newer client state.
- `src/types.ts`, `src/utils/format.ts`, `src/pages/RoutersPage.tsx`,
  `src/pages/DashboardPage.tsx`, `src/pages/ReportsPage.tsx`, and
  `src/pages/RouterDetailPage.tsx` — represent stale/unknown status
  consistently, add the stale count/filter, label stale lease snapshots,
  and distinguish telemetry freshness from an inbound connection.
- `src/pages/MikroTikConfigPage.tsx` — display never-reported routers as
  waiting rather than connected.
- `bradha-matu-api/app/Services/MockRouterProvider.php` — maintain the
  dashboard-summary response shape for demo mode.
- `docs/MIKROTIK_PRODUCTION_CONNECTIVITY.md` — update router/runtime
  verification history and limits.
- `docs/TELEMETRY_RELIABILITY_SPRINT.md` — this evidence and action report.

No migration was added. No UI theme or router configuration UI was redesigned.

## Verification performed

Commands run from the repository:

```sh
cd bradha-matu-api
php artisan test tests/Feature/RouterTelemetryTest.php tests/Feature/ClientInventoryTest.php
```

**Result: 25 tests passed, 194 assertions.**

```sh
node --test tests/mikrotikTerminalScript.test.mjs
```

**Result: 14 passed, 0 failed.** This checks generated script text, not
RouterOS runtime execution or scheduler state.

```sh
cd bradha-matu-api
php artisan test
```

**Result: all 75 Laravel tests passed, 438 assertions.**

```sh
npm run build
```

**Result: TypeScript build and Vite build succeeded.** Vite emitted the
existing advisory that the minified JavaScript bundle exceeds 500 kB.

```sh
cd bradha-matu-api
./vendor/bin/pint --test app/Http/Controllers/MikroTikConfigController.php app/Services/ClientInventory.php app/Services/RouterTelemetryService.php app/Services/MikroTikRouterProvider.php tests/Feature/RouterTelemetryTest.php tests/Feature/ClientInventoryTest.php
git diff --check
```

Formatting and whitespace checks passed. No physical RouterOS test or
production database test was performed in this sprint.

## Production deployment and rollback

**Deployed on 2026-10-22 using SFTP.** cPanel shell access remains disabled.
Only these four backend files were replaced:

- `app/Services/MikroTikRouterProvider.php` — patched against the exact
  production version, not replaced with the broader workspace version.
- `app/Services/RouterTelemetryService.php`
- `app/Services/ClientInventory.php`
- `app/Http/Controllers/MikroTikConfigController.php`

Each previous file was backed up outside the web root under
`/home/pandatechsco/tmp/bradha-telemetry-20261022T101608Z` with directory mode
700. New files were staged beside their targets, compared byte-for-byte over
SFTP, then swapped by rename. Post-deployment downloads matched the staged
release bytes. The public HTTPS health endpoint returned HTTP 200 with
`{"status":"ok"}`. No database migration was required; the telemetry schema
was already present. The frontend bundle and public assets were not changed.

The production login page required an authorized session; none was available
for this verification. Consequently, no authenticated dashboard inspection,
new report timestamp, successive report cadence, or router-offline-from-Kali
acceptance test was performed. Do not claim recurring telemetry is fixed.
The unchanged frontend receives `unknown` for stale router status, while the
backend keeps the stale source marker and emits a warning incident.

Rollback: use the four originals in the private backup directory above and
restore them via SFTP to their original paths. No database rollback is
required. The router scheduler and RouterOS configuration were not changed.

## RouterOS read-only diagnostics

Run these commands in the **RB951 RouterOS terminal**, not in Linux or the
web console. They inspect only; they do not alter configuration. Do not print
script details/source or share bearer credentials.

```routeros
/system resource get version
/system script print where name="bm-telemetry-3fdf0dd4"
/system scheduler print detail where name="bm-telemetry-3fdf0dd4-schedule"
/system script job print where script="bm-telemetry-3fdf0dd4"
/interface wireless registration-table print count-only
/log print where topics~"script"
```

Check that the scheduler has no `X` disabled flag, that its `on-event` invokes
the exact installed script name, and that its interval is no greater than the
180-second server freshness threshold. Inspect the latest script warning
locally; redact network details and never share script source or secrets.

The latest supplied command already requested a one-minute interval and
`disabled=no`. Do not repeat the change or create a duplicate. Verify the
result read-only:

```routeros
/system scheduler print detail where name="bm-telemetry-3fdf0dd4-schedule"
```

The provided log shows DNS resolution failures followed by one HTTP 422.
Run these read-only checks from the RouterOS terminal to distinguish name
resolution, network/TLS preflight, and application validation. Do not share
the full DNS configuration if it exposes internal resolvers or topology:

```routeros
/ip dns print
:put [:resolve "onlineconsole.pandatechs.co.ke"]
/ping address=onlineconsole.pandatechs.co.ke count=3
/tool fetch url="https://onlineconsole.pandatechs.co.ke/api/health" check-certificate=yes output=none
```

The `/api/health` GET does not authenticate telemetry or change router state.
Keep certificate verification enabled. If name resolution fails, stop there
and have the network administrator correct the router's DNS/upstream
connectivity; do not substitute a hardcoded IP or disable TLS checks.

For the 422, inspect the latest RouterOS warning again after an attempt and
compare it with the production endpoint's safe `X-Telemetry-Error` category
or validation field. Do not print or share script source, bearer tokens, or
Authorization headers. A 422 may be clock-window, replay, identity, or
payload validation; do not guess which without the error category.

After fixing the actual failure, verify multiple successful, one-minute
production receive timestamps. Do not claim success from one manual run. Do
not rotate the credential unless its current script/token is known
compromised or the administrator explicitly wants revocation; rotation
immediately invalidates the router's installed token.

## Remaining blockers and next actions

1. **Operator:** verify the existing scheduler is enabled at one minute and
   run the DNS/HTTPS checks. Share only whether each succeeded and the
   sanitized error (no script source/token).
2. **Operator/release owner:** diagnose the 422 from its safe endpoint error
   category or Laravel validation field. The status code by itself is
   insufficient.
3. **Operator:** verify multiple successive production receive timestamps
   after DNS/422 failures are resolved.
4. **Administrator/operator:** sign in to the production console, verify the
   stale warning and client freshness behavior, then confirm successive
   authenticated report timestamps while Kali is powered off.
5. **Operator:** compare the current wireless registration count with the
   next accepted telemetry report and confirm that `wireless_clients_complete`
   is true before trusting absent clients as offline.
6. **Administrator:** identify the separate 20,746-day-old RB951 record and
   decide whether it is a retired duplicate. Do not merge/delete records
   without confirming their identities and references.
7. **Release owner:** if stale telemetry incidents should be persisted and
   resolved historically rather than recalculated on read, scope that as a
   separate change; current incident generation is computed from current
   router state.

## Four-client inventory follow-up

The operator directly confirmed four active `wlan1` registrations and four
matching bound DHCP leases for MAC addresses `9C:1C:37:56:5B:35`,
`90:E4:68:9C:02:DD`, `2A:05:C5:6F:CE:69`, and `D8:F2:CA:ED:13:31`.
The authenticated production router page still exposed only the last lease,
`PANDATECHS` / `192.168.88.253`; on the latest inspection the receipt was
about 15 minutes old. Its four saved client rows included stale identities
outside the four confirmed MACs. Thus the production inventory is not current
and the latest stored snapshot does not reflect the operator's confirmed
router state.

Direct code inspection found no one-item cap or reconciliation drop in
Laravel: collection normalization preserves indexed lists, each record is
validated, DHCP is joined to wireless by normalized MAC, and client identity
is `(router_id, mac_address)`. The generated RouterOS script loops over both
complete source menus. Because the active script version and raw submitted
JSON are unavailable, the precise loss point cannot yet be separated among an
old installed script, RouterOS collection, and serialization. The later HTTP
422's exact validation category also remains unavailable; do not weaken
validation or claim its historical cause is known. A reproducible validation
defect was found while adding an empty-source regression: Laravel's `required`
rule rejects an empty `dhcp_leases` list (and likewise an empty wireless list),
even though zero active records is valid. The endpoint now requires these
fields to be present arrays while retaining per-row validation and size limits.
This is a confirmed 422 cause for empty-source payloads, but does not establish
that the historical four-record report failed for the same reason.

The current local patch adds DHCP completeness plus expected, pre-serialization
collected, and server-received counts for both collections. The server marks
count-mismatched data incomplete while retaining positive observations; an
incomplete wireless report cannot mark omitted devices offline. The DHCP API
exposes all count stages. The exact four-client fixture now exercises
validation, normalized persistence, both inventory APIs, DHCP lease output,
hostnames/unknown labels, timestamps, interface names, duplicate delivery,
and a count-mismatched partial report.

Additional local fixes preserve known hostname/IP/interface values when a
new wireless observation has no matching DHCP lease, represent missing signal
as `null`, and return `status: unknown` with a separate `lastConfirmedStatus`
when monitoring is stale or collection is incomplete. The detail and global
client views render unknown status and do not fabricate a signal value.

**Validation of this local patch:** all Laravel tests passed (78 tests,
507 assertions); the generated script test passed (14 tests); Pint and
`git diff --check` passed; and the frontend TypeScript/Vite build succeeded
with the existing large-bundle warning. These are code tests only, not
RouterOS 7.23.1 runtime proof.

**Deployment status updated 2026-10-22 15:58 EAT:** the three backend files
`RouterTelemetryController.php`, `RouterTelemetryService.php`, and
`ClientInventory.php` were deployed through SFTP after comparing live source
and creating verified private backups outside the web root. Post-deployment
downloads matched local hashes. The health endpoint returned HTTP 200 with
verified TLS, anonymous telemetry ingestion remained HTTP 401, and the full
local Laravel suite passed (78 tests, 507 assertions).

At the user's direction, the current full frontend build was subsequently
deployed despite unrelated workspace UI changes. The prior live HTML and
hashed JS/CSS were backed up outside the document root; downloaded backup
hashes matched. New JS/CSS files and a staged `index.html` were uploaded to
the nested public document root, hashes verified, and the entrypoint renamed
last. HTTPS GETs to the live HTML and new assets returned HTTP 200 with TLS
verification result 0 and bytes matching the local build.

A cache-busted browser visit rendered the new UI and showed stale saved client
states as `unknown`, with the last confirmed status retained in details.
The rendered router view showed around one to two minutes since its last
report and one DHCP lease (`PANDATECHS`, `192.168.88.253`,
`D8:F2:CA:ED:13:31`); the rendered client list included one recent
`90:E4:68:9C:02:DD` row and four old rows marked stale/unknown. However,
direct same-origin requests from that browser to both protected APIs returned
401 and the browser logged 401 responses. Treat those rendered rows as
possibly previously fetched/cached UI state, not authenticated current API
evidence. The confirmed four-device observation is not verified in the
authenticated live API; acceptance remains unproven.

The updated generator has not been installed on the RB951, its scheduler has
not been changed, and no new one-shot count diagnostics are available. Keep
the existing RouterOS scheduler/configuration unchanged until an authorized
operator manually runs the reviewed one-shot and verifies four expected,
collected, and received entries for both DHCP and wireless sources.

The success criterion remains real authenticated recurring reports after
Kali is powered off, with accurate receipt times and a complete wireless
snapshot. That criterion has not yet been met or re-verified.

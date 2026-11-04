# MikroTik production connectivity

## Transport options and verified boundary

The existing Laravel provider contract is served by two live transports:

| Transport | Intended environment | RouterOS interface |
|---|---|---|
| Binary API / API-SSL | Local lab or another explicitly routed private network | Native RouterOS API protocol (not WinBox), normally TCP 8728/8729 |
| HTTPS REST | cPanel only when a verified private/VPN route exists | HTTPS `www-ssl` REST endpoint, server-configured port (default 443) |
| Router-initiated telemetry | Production when cPanel cannot reach the router privately | RouterOS sends authenticated HTTPS reports outward; no inbound router API |
| Kali Bridge diagnostics | On-demand integration checks only | Commands run explicitly in the Kali Linux terminal; no bridge daemon |
| `mock` | Explicit UI/demo mode only | Fictional data, visibly tagged `mock`; no router connection |

`ROUTER_PROVIDER` selects the default transport for newly added routers; each
router's saved server-side configuration selects Binary API/API-SSL, HTTPS
REST, or outbound telemetry independently. Kali diagnostics use the existing
read-only application tooling from the operator's local development
environment; they are not an additional server transport or production
dependency. A monitoring request cannot choose an arbitrary target URL, host,
port, or RouterOS command. Binary API and REST use the existing
`RouterProvider` mapping and encrypted API credentials; telemetry uses its
dedicated hashed bearer credential and ingestion path.

The existing router inventory record remains the connection profile: its
display name/location, host, explicit transport, port, TLS verification,
encrypted API credential, monitoring-enabled flag, last-attempt/last-success
timestamps, and status/error fields are reused. Timeouts remain configurable
through `MIKROTIK_CONNECT_TIMEOUT` and `MIKROTIK_QUERY_TIMEOUT`; the
application-wide read allowlist is the permission scope. There is deliberately
no second provider, connection table, or router-command API. Kali diagnostics
are operator-run and hold no separate router credential in the browser.

Local live access and cPanel-to-router connectivity are distinct facts. The
following lab tests were run from the Kali/Linux host. The target cPanel
host's routes to the saved private RFC1918 router network are unknown.

### Current local RB951-2n diagnostic observation

On 2026-10-12, `192.168.88.253/24` routed to `192.168.88.1`; ports 22, 80,
443, and 8728 accepted TCP while 8729 refused. Plain API 8728 was not used.
The supplied `bradha-ca.crt` chain and certificate IP identity verified for
`192.168.88.1:443` with OpenSSL. Credential-free REST access was not assumed;
after certificate validation, identity and resource were checked with the
protected Kali credential config, and identity matched `RB951`.

For the Kali-hosted Laravel environment, set this in the private
`bradha-matu-api/.env` (do not copy it into cPanel):

```dotenv
MIKROTIK_TLS_CA_FILE=/home/laban/mikrotik-certs/bradha-ca.crt
```

The actual app then used its existing Laravel `RouterRestTransport` and
`RouterConnectionTester` with TLS verification enabled and the CA configured
through the local-only `MIKROTIK_TLS_CA_FILE`. The local SQLite router record
was updated to the REST profile and its read-only password stored using the
existing encrypted model cast. Laravel observed `RB951`, `RB951-2n`, RouterOS
`7.23.1 (stable)`, `mipsbe`, and successful reads of 8 interfaces, 2 IP
addresses, 3 routes, and 2 DHCP leases. No router configuration was changed.
The separate HTTP page/app server was not running during this check.

For on-demand diagnostics, run the following in a **Kali Linux terminal**, not
the RouterOS terminal. These commands only inspect local routing and TCP
reachability; they do not authenticate or alter router configuration:

```sh
ip -brief address
ip route get 192.168.88.1
for port in 8728 8729 443; do nc -zvw2 192.168.88.1 "$port"; done
curl --max-time 5 --silent --show-error --output /dev/null \
  --write-out 'HTTPS status=%{http_code} TLS verify result=%{ssl_verify_result}\n' \
  https://192.168.88.1/rest/system/identity
```

Only use authenticated REST reads after the certificate validates. Keep
credentials outside the repository in the mode-`0600` Kali config and avoid
passing passwords directly in command-line arguments or storing them in
shell history. Compare `/system/identity` and `/system/resource` with the
intended router before further reads. The preconfigured `router.sh` supports
only its explicit read allowlist, but its `curl --user user:password`
argument may be visible to same-host process inspection while it runs; prefer
a protected curl config file when running ad-hoc diagnostics.

```sh
curl --fail --silent --show-error --max-time 5 \
  --netrc-file "$HOME/.config/routeros-lab.netrc" \
  https://192.168.88.1/rest/system/identity
curl --fail --silent --show-error --max-time 5 \
  --netrc-file "$HOME/.config/routeros-lab.netrc" \
  https://192.168.88.1/rest/system/resource
```

Verify enabled services and the monitoring account's group/policies separately
in a **RouterOS terminal** after connecting to the identity-confirmed device:

```routeros
/system identity print
/system resource print
/ip service print detail
/user active print
/user group print detail
```

Do not paste their output if it contains sensitive network information. These
RouterOS commands are not Linux shell commands and are not executed by the
console. TCP observations alone are not proof of authenticated access or user
permissions.

## Provider contract and RouterOS REST mapping

The provider contract remains the methods in `RouterProvider`. RouterOS REST
uses `GET /rest/<menu>` for the existing fixed `print` reads and maps
`.proplist` plus the existing interface filter to REST query parameters. The
dashboard/API mapping is shared, so RouterOS string values are converted by
the same application parsing used with the Binary API.

| Existing provider operation | Binary API commands | HTTPS REST equivalent |
|---|---|---|
| `getRouters`, `getRouter`, `searchRouters` | Identity, resource, interface print reads | `GET /rest/system/identity`, `/rest/system/resource`, `/rest/interface`; remaining fields/search are computed from the same Laravel inventory |
| `getStatus` | `/system/resource/print`, `/system/identity/print`, `/interface/print`, `/ip/address/print`, `/ip/route/print` | `GET /rest/system/resource`, `/rest/system/identity`, `/rest/interface`, `/rest/ip/address`, `/rest/ip/route` |
| `getInterfaces` | `/interface/print`, `/ip/address/print` with interface filter | `GET /rest/interface`, `/rest/ip/address?interface=<encoded-name>` |
| `getClients` | `/interface/wireless/registration-table/print`, `/ip/dhcp-server/lease/print` | `GET /rest/interface/wireless/registration-table`, `/rest/ip/dhcp-server/lease` |
| PPPoE inventory | `/ppp/secret/print`, `/ppp/active/print` | `GET /rest/ppp/secret`, `/rest/ppp/active`; secrets are requested without the password property |
| `getTraffic` | One-shot `/interface/monitor-traffic` and `/interface/print` | One-shot `POST /rest/interface/monitor-traffic` for each named running interface with `once=""`; then use interface counters from `GET /rest/interface` |
| `getWireless` | `/interface/wireless/print`, `/interface/wireless/registration-table/print` | `GET /rest/interface/wireless`, `/rest/interface/wireless/registration-table` |
| `getMetrics` | Derived from status and traffic operations | Same derived status and one-shot traffic reads |
| `getDashboardSummary`, `getIncidents` | Derived from routers, client, and traffic operations | Same derived operations; no additional REST command |
| `connect` | Identity/resource print reads | `GET /rest/system/identity`, `/rest/system/resource` |

The REST transport permits only allowlisted print routes and the one bounded
`monitor-traffic` POST. Its POST is not an arbitrary command executor: it
rejects other paths and argument shapes. RouterOS REST's write verbs (`PUT`,
`PATCH`, `DELETE`) are never sent. Requests require HTTPS, trusted certificate
chain and hostname verification, disabled redirects, a destination pinned to
the validated private address, finite timeouts, and a 1 MiB response ceiling.

## RouterOS version, HTTPS, and least-privilege prerequisites

MikroTik's [RouterOS REST API documentation](https://help.mikrotik.com/docs/display/ROS/REST+API)
states that REST starts in RouterOS 7.1beta4, is served by `www-ssl` (HTTPS),
uses HTTP Basic authentication, and requires the user's group to have both
`rest-api` and `api` policies plus the task policy (`read` for reads). It
specifically recommends custom groups for REST clients: the default `read`,
`write`, and `full` groups include additional capabilities, including
`reboot` and `sensitive`. The intended dedicated monitor group is therefore
limited to `read,api,rest-api`; do not add `write`, `test`, `sensitive`,
`reboot`, or user-management policy for this dashboard. Restrict that user's
source address and give it no interactive/shared administrator use.

Before enabling cPanel REST against a target, inspect that router's actual
RouterOS version, policies, `www-ssl` certificate, enabled services, firewall
and source allowlist, and test that these exact read calls succeed without
granting extra privileges. This repository does not inspect or provision a
physical router and does not ship a terminal import script. Do not assume a
RouterOS version, a default `read` group, a valid certificate, or that a
terminal setup command is safe for the target.

Never use plain HTTP `www`, `curl -k`, disabled verification, or insecure
Basic authentication. Use a CA-signed certificate with a hostname matching the
configured approved router hostname (or a correctly issued IP SAN when using
an IP), and make its chain trusted by the cPanel PHP/cURL trust store or the
explicit private CA bundle configured as `MIKROTIK_TLS_CA_FILE`.

Cloudflare Tunnel can publish the dashboard origin but does not, by itself,
route cPanel's server-side HTTPS request to a private router management IP.
REST does not bypass private-network routing restrictions. Do not publish or
port-forward RouterOS TCP 8728/8729 or HTTPS REST solely for cPanel access.

## Implemented outbound telemetry architecture

```text
Browser
  | HTTPS (dashboard/API; authentication)
  v
cPanel Apache/PHP + Laravel + database
  ^ authenticated HTTPS POST /api/v1/internal/mikrotik/telemetry
  |
MikroTik RouterOS script + Scheduler
  | outbound HTTPS only; TLS validation; per-router bearer secret
  v
Public HTTPS endpoint on onlineconsole.pandatechs.co.ke
```

Router telemetry mode stores no usable RouterOS API password and does not
connect to the saved private management address. Existing Binary API and REST
routers retain their legacy server-side polling behavior. The telemetry script
is generated locally in the existing super-admin MikroTik configuration page;
the application does not import or execute it.

## Telemetry endpoint and stored data

- `POST /api/v1/internal/mikrotik/telemetry` uses a unique random 256-bit
  bearer token per router. Only its SHA-256 hash is stored. Rotation is
  super-admin-only and the raw token is returned once.
- The endpoint limits request size to 48 KiB, applies a 30-requests/minute
  throttle, validates a fixed versioned payload and configured RouterOS
  identity, enforces a five-minute clock window, and rejects non-increasing
  report timestamps. A duplicate of the latest snapshot ID is idempotent.
- Each accepted report updates the latest snapshot, server receive time,
  client inventory and a historical `RouterMetric` row. RX/TX Mbps are
  derived from successive interface counters and router observation times;
  counter decreases are treated as resets, not negative traffic.
- Wireless registration collection is marked complete only when every ID
  returned by RouterOS was collected successfully. Incomplete snapshots still
  update system metrics and positively observed clients; unreported clients
  retain their last confirmed status and are marked stale. Only a complete
  snapshot can mark an unreported, previously online client offline. Regenerate
  and manually import the telemetry script to enable this field; older
  generated scripts are treated as incomplete for client status.
- RouterOS 7.23.1 can expose the legacy `/interface wireless` and newer
  `/interface wifi` registration tables separately. The generator now checks
  the legacy table when the newer table command succeeds but returns no
  registrations, so an empty modern table cannot hide active legacy `wlan`
  clients. HTTPS response codes are also checked when Fetch returns one.
- The authenticated dashboard reads the stored snapshot. Missing/stale
  reports are marked stale after 15 seconds by default (configurable); stale
  data is not labelled live. The Clients page reloads from Laravel every five
  seconds. This reload does not run or enable the MikroTik Scheduler.
  Telemetry routers are skipped by cPanel scheduled polling, refresh actions,
  connection tests and PPPoE live reads.
- DHCP leases and active wireless registrations are separate. A lease does
  not imply an online Wi-Fi client.

## RouterOS capabilities and reviewed script boundary

The official [RouterOS 7.24 Fetch reference](https://manual.mikrotik.com/docs/7.24/cli-reference/tool/fetch/)
documents HTTPS `POST`, `http-header-field`, `http-data` (64 KiB request-body
limit), `output=none`, and `check-certificate=yes`; certificate checking is
not enabled by default. This is 7.24 documentation and is not proof of 7.23.1
compatibility. The generated script explicitly sends JSON with a bearer
header, uses `check-certificate=yes`, has no plain-HTTP fallback, sends no
router management credentials, and refuses an oversized payload. The script
uses the established `:do ... on-error` form for Fetch error handling;
importing the file creates the read-only telemetry script only. The script
and Scheduler request `read,test`: the official
[RouterOS user-policy reference](https://help.mikrotik.com/docs/spaces/ROS/pages/8978504/User)
lists `test` as the policy that permits Fetch, while `read` permits the
telemetry reads. `write` is not required and is deliberately not granted.
It guards duplicate script names and overlapping script jobs. The scheduler
command is separate and commented until the manual report is accepted. The
script reads system resources, interfaces, DHCP leases and the legacy wireless
registration table. A no-credential HTTPS POST from the
RB951-2n was observed to receive HTTP 401 with the expected Bearer challenge.
This verifies router-to-endpoint TLS and POST reachability, not authenticated
ingestion. The first generated script using a newer `:onerror` form was marked
invalid by RouterOS 7.23.1; the generator now uses `:do ... on-error`, but
this corrected script has not yet been verified on the physical router.

The serializer is generated with RouterOS `:serialize to=json`. A manual
report from the physical RB951-2n was accepted by production on 2026-10-20 at
6:40:36 PM EAT, verifying one authenticated submission. This does not verify
recurring delivery. Earlier, a scheduled attempt had failed with RouterOS
`not enough permissions (9)` at `/tool/fetch`; the generated script and
deferred Scheduler command request `read,test`, based on the official policy
reference. The operator subsequently reported a scheduler with 35 runs, but
the last supplied scheduler listing (2026-10-20, 8:27 PM EAT) showed it
disabled with a five-minute interval. That listing does not prove those runs
were successful or that the scheduler was enabled afterward.

The default Laravel stale threshold is 15 seconds, allowing three missed
reports from a five-second Scheduler interval before client presence becomes
unconfirmed. Configure `MIKROTIK_TELEMETRY_STALE_SECONDS=15` in the Laravel
production environment and clear cached Laravel configuration when deploying.
A five-second schedule produces 12 reports per minute, below the endpoint's
30-requests-per-minute throttle for one router. Keep exactly one enabled
Scheduler entry and verify that accepted report timestamps advance; the
dashboard cannot enable or repair that router-side Scheduler.

The production client API currently returns per-client RX/TX byte counters,
and the traffic API returns aggregate byte totals and Mbps estimates. Per-client
wireless signal is currently `null` for all online devices, despite complete
wireless registration data. The generator reads RouterOS
`registration-table get signal-strength` and normalizes it as a number; the
device's actual read-only value and type must be checked before changing this
conversion. Do not report `0 dBm` as a measured signal when the value is
unavailable.

The dashboard showed three saved clients while prior telemetry did not
confirm all wireless registrations. The operator reported that two clients
were online and Vince-s-A06 was offline. The generated script compares
collected wireless rows with registration IDs and submits a completeness
flag. A complete fresh report is still required before absent clients can
reliably be marked offline.

The HTTPS certificate preflight for `onlineconsole.pandatechs.co.ke` was
attested in the configuration flow, and the accepted report confirms one
successful authenticated HTTPS POST. The exact current installed script,
repeated Scheduler behavior, Fetch timeout/response handling, complete
wireless collection, and payload size on RouterOS 7.23.1 remain unverified.
The versioned official 7.23 documentation URLs returned 404 during review;
current 7.24 documentation is not evidence of identical 7.23.1 behavior.
Automated tests inspect generated text and application behavior; they are
not a RouterOS runtime test.

The router's local certificate output supplied by the operator showed an
empty certificate listing. Therefore server-certificate trust is not assumed.
Before issuing a telemetry token, the UI requires the administrator to attest
that this harmless router-originated check succeeded:

```routeros
/tool fetch url="https://onlineconsole.pandatechs.co.ke/api/health" check-certificate=yes output=none
```

If it fails, do not install the telemetry script or weaken verification. The
router must first trust the correct public CA chain for the Laravel HTTPS
certificate; the router's local CA used to validate RouterOS REST is a
different trust relationship. This preflight and the eventual POST must be
performed and confirmed manually by an authorized operator.

The RouterOS time must be synchronized and configured to `Etc/UTC`; otherwise
the script refuses to submit. Ensure RouterOS has working outbound DNS and
HTTPS. Do not expose RouterOS API, API-SSL, Winbox, SSH, WebFig or REST ports
publicly for telemetry.

## Hosting and deployment verification boundary

The repository contains Laravel telemetry ingestion and uses the existing
database connection. Local development reports Laravel 13.35 and PHP CLI
8.4.24. On 2026-10-22, a scoped backend-only PHP release was uploaded via
SFTP because cPanel shell access is disabled. The four deployed files were
verified byte-for-byte after upload, and the public health endpoint returned
HTTP 200 over verified HTTPS. This does not prove database persistence for a
new router report, recurring request success, or authenticated dashboard
behavior. No laptop, local collector, or VPS is part of the intended telemetry
data path.

No migration was included in this deployment: the telemetry schema created
by `2026_10_12_000004_add_router_telemetry_ingestion.php` was already present.
If a future deployment needs a migration, use
[CPANEL_BROWSER_DEPLOYMENT.md](./CPANEL_BROWSER_DEPLOYMENT.md) after taking a
current database backup. There is no rollback of collected production data in
the browser runner's safe migration flow.
Legacy routers still need the existing scheduled collector. Telemetry routers
do not: RouterOS Scheduler owns their 60-second default reporting cadence.
The cPanel cron is therefore not a prerequisite for receiving telemetry.

## Bounded scheduled collection

The Laravel app now registers `routers:collect` once per minute. It performs
fixed read-only status and one-shot traffic requests, writes normalized
`RouterMetric` snapshots, updates `last_attempted_at`, `last_seen_at`, and
`last_error_category`, and retains 30 days of samples. A per-router cache lock
prevents overlapping reads; transport timeouts remain finite. Router status
requests fall back to the most recent saved metric and gateway observation
with an explicit stale marker and observation timestamp if live polling fails.

The schedule itself runs only when the host invokes `php artisan schedule:run`.
For cPanel, configure one minutely Cron Jobs entry to run it from the private
backend directory, then verify CLI PHP, run duration, database cache-lock
support, new metric rows, and router timestamps. Neither the browser migration
nor the ZIP can create that cron job. The 60-second cadence and 30-day
retention are application defaults, not proof that the host can meet them.

The collector does not prove cPanel can reach the router management subnet,
and scheduled client inventory refresh is not part of this command. Router
reachability and cron/runtime limits remain production verification items.

## Acceptance status

Local lab integration is verified from the same Linux host that runs this
Laravel checkout, including Laravel's own HTTPS REST service and read-only
data collection. The UI build and mocked tests pass, but the production
frontend was intentionally not replaced. The production login page was
reachable, but an authorized session was not available for checking the
dashboard. Direct cloud-router connectivity, a new accepted report, recurring
telemetry, and telemetry after powering off Kali remain unverified. The
second router's public IP, certificate, firewall/VPN path, and credentials
were not provided; do not expose management services publicly. Verify
successive router-originated reports with Kali powered off before claiming
production acceptance.

## Router-side constraints

For local Binary API, restrict API/API-SSL to the trusted source address and
use API-SSL with a valid trusted certificate when supported end-to-end. For
REST, enable only HTTPS `www-ssl`, use a trusted matching certificate and a
dedicated `read,api,rest-api` user group after confirming the target version
supports those policies. Keep either service restricted to the approved
collector/cPanel source address. Never broaden a service to the internet,
disable certificate checks, add `write`/user-management privileges, or expose
arbitrary RouterOS commands.

## Four-client inventory investigation

The operator directly confirmed four active `wlan1` registrations and four
matching bound DHCP leases:

| IPv4 address | MAC address | DHCP hostname |
|---|---|---|
| `192.168.88.248` | `9C:1C:37:56:5B:35` | not supplied |
| `192.168.88.252` | `90:E4:68:9C:02:DD` | not supplied |
| `192.168.88.247` | `2A:05:C5:6F:CE:69` | `HONOR-Play10` |
| `192.168.88.253` | `D8:F2:CA:ED:13:31` | `PANDATECHS` |

During this investigation, the authenticated production router detail page
still exposed only one DHCP lease, `PANDATECHS` at `192.168.88.253`, and its
last received report was about 15 minutes old on the latest inspection. The
router client tab showed four saved rows, but only `PANDATECHS` matched the
latest lease and the remaining entries were stale or had MACs outside the
four confirmed registrations. This confirms the production inventory is not
current; it does not prove the other devices are disconnected.

The Laravel ingestion path already normalizes singleton object/list
representations, validates each nested lease/client, joins DHCP metadata to
wireless clients by normalized MAC, and persists clients by router ID plus
MAC. It does not limit a collection to one record. A new feature test now
exercises the exact four MAC/IP pairs through ingestion, persistence, the
router-client API, the network-wide client API, and the DHCP lease API. It
also verifies idempotent delivery and that a count-mismatched partial report
does not mark omitted clients offline.

The generated RouterOS script now reports source-list counts, locally
collected counts, and collection-completeness flags for both DHCP leases and
wireless registrations. Laravel compares all three stages—expected,
collected before serialization, and received in the JSON array. A mismatch
leaves the report usable for positive observations but marks that collection
incomplete; wireless reconciliation will not infer disconnection from omitted
entries. These fields are optional at ingestion so existing scripts remain
compatible until an operator manually installs the updated generated script.
The DHCP API exposes the completeness flag and each count for diagnosis.

The generator's JSON contract for these collections is:

| Field | JSON type | Meaning |
|---|---|---|
| `dhcp_leases` | array of objects | `mac_address`, nullable `host_name`, `address`, `interface`, `status`, `expires_after` |
| `dhcp_leases_complete` | boolean | Lease loop completed without a RouterOS collection error |
| `dhcp_lease_count` | integer | Number of lease IDs returned by RouterOS `find` |
| `dhcp_collected_count` | integer | Number of lease objects appended before JSON serialization |
| `wireless_clients` | array of objects | `id`, `interface`, `mac_address`, numeric `signal_strength`, `bytes` as `"rx,tx"` |
| `wireless_clients_complete` | boolean | Registration loop completed and collected count matched the ID list |
| `wireless_registration_count` | integer | Number of active registration IDs returned by RouterOS `find` |
| `wireless_collected_count` | integer | Number of client objects appended before JSON serialization |

Each collection uses numeric keys (`0`, `1`, ...) to store each keyed record
as a distinct array element before it is serialized as part of the enclosing
payload with `:serialize to=json value=$payload`. This is necessary because
comma-concatenating associative record maps merges their named fields instead
of creating a list of records. Generated-source tests verify the indexed
array construction; a manual 7.23.1 one-shot is still required to confirm the
RouterOS JSON output is an array containing every collected record.

**Root-cause boundary:** production currently proves that only one DHCP
record is present in the latest stored snapshot, while the RouterOS generator
source iterates every entry. The active script source/version on the RB951
and the authenticated raw request body were not available for inspection.
Therefore it is not yet possible to distinguish an outdated installed script
from RouterOS-side collection/serialization loss. The new count fields are
designed to identify the failing stage on the next report. Do not log or share
the bearer token or the full Authorization header to diagnose this.

The exact category/field for the previously observed HTTP 422 is also
unavailable; an earlier, separate `system.uptime` validation error had been
fixed, but that does not identify the later 422. Keep validation enabled and
compare the next sanitized `X-Telemetry-Error` response category with the
server-side validation field list.

A local regression exposed and fixed one independent 422 condition: Laravel's
`required|array` validation rejects an explicitly empty DHCP or wireless list.
The endpoint now accepts present empty arrays but continues to validate every
included row and enforce collection limits. This fix does not prove the
historical 422 was caused by an empty list; the live request's safe error
category is still needed to establish that.

### Adding another router and starting its reports

The generator now tries `/interface wifi registration-table` first, then
falls back to `/interface wireless registration-table` when the WiFi menu is
unavailable. For the newer WiFi menu it reads `signal`, `rx-bytes`, and
`tx-bytes`; for the legacy menu it reads `signal-strength` and `bytes`.
Collection errors or a row-count mismatch set the wireless completeness flag
false, so the server will not infer that omitted clients disconnected.

This is runtime feature detection, not a claim that every RouterOS/device
combination has been physically tested. The production RB951-2n / RouterOS
7.23.1 was verified on the legacy wireless path. The newer `/interface wifi`
path needs a successful manual report on a router with that package before it
can be considered verified for that model. The official current CLI reference
([registration-table](https://manual.mikrotik.com/docs/cli-reference/interface/wifi/registration-table/))
lists the WiFi registration-table menu, but its current 7.26 page does not
enumerate the properties; the [WiFi guide](https://manual.mikrotik.com/docs/wireless/wifi/)
distinguishes `/interface/wifi` from the legacy wireless package. These pages
do not prove property or runtime compatibility with RouterOS 7.23.1. Check
actual read-only output on each target before relying on optional signal and
byte counters. Never disable HTTPS certificate checks.

For each router, in the authenticated `/mikrotik-config` page:

1. Add a distinct router configuration and select router-initiated telemetry.
   Enter its exact `/system identity` value. Do not use one router's generated
   script or credential on another.
2. From that router's **RouterOS terminal**, run the displayed HTTPS preflight
   with `check-certificate=yes`. Resolve CA trust or clock problems before
   proceeding; never use HTTP or disable certificate verification.
3. Generate and download the per-router `.rsc`. It contains a secret. Keep it
   private, do not paste it into chat/logs, and delete it after importing.
   Rotating the token immediately invalidates the installed script's token.
4. Upload the file using Winbox or WebFig **Files**. In that same router's
   **RouterOS terminal**, run the page's exact import and one-shot commands:

   ```routeros
   /import file-name="bradha-matu-telemetry-<router-prefix>.rsc"
   /system script run bm-telemetry-<router-prefix>
   ```

   Replace `<router-prefix>` with the eight-character ID shown by the page.
   If replacing an installation, inspect and disable only its matching
   scheduler, then remove only its exact script name before import. Do not
   delete unrelated scripts or schedulers.
5. Confirm a fresh accepted report in the console and verify that wireless
   and DHCP collections are complete and their collected counts match the
   reported counts. On a new WiFi-package router, treat a failed or incomplete
   result as unsupported until the read-only property names and output are
   checked on that device. Do not schedule a script that has not passed this
   check.
6. Run the page's guarded scheduler command in that router's **RouterOS
   terminal** only after the one-shot succeeds. It adds one entry if absent,
   updates the named entry if exactly one exists, and stops when duplicate
   entries exist. Review/remove duplicates manually rather than running a
   second add command.

Newly registered wireless MAC addresses are upserted into client inventory
from an accepted complete report. The dashboard then shows them on its normal
refresh (or after a manual refresh); DHCP leases enrich matching clients but
do not independently mark WiFi clients online. Offline historical clients
are retained. In production, v1.1.5 on the RB951-2n previously delivered
complete wireless and DHCP collections (4/4 each), populated signal values,
and showed four online clients. The v1.1.6 dual-menu generator is now deployed
in the frontend, but the RB951 still uses its installed v1.1.5 script; import
and verify a newly generated script manually before replacing that installation.
The newer `/interface wifi` path still requires a live test on hardware that
uses that package.

The v1.2.0 generator additionally collects `/interface bridge host` entries
that are dynamic and not marked local. Laravel stores these as wired clients,
uses a matching bound DHCP lease only to enrich the IP address and host name,
and gives wireless registration precedence when the same MAC appears in both
sources. Wireless and wired completeness are reconciled independently, so a
partial bridge-host report cannot mark wired clients offline. The Clients and
router-specific client tables label each saved client as Wi-Fi or wired.
Generator v1.3.0 additionally reports dynamic `/ip arp` neighbors. This
source is discovery-only: rows appear as **Neighbor (ARP)** with **unknown**
status, never online or offline solely because they remain in or disappear
from the ARP cache. When the same MAC is currently present in the Wi-Fi or
dynamic bridge source, that higher-confidence source takes precedence.
DHCP/NAT routers still hide the MAC addresses behind them; enroll that
downstream router separately or use AP/bridge mode for transparent clients.
This release was deployed on 2026-10-24 to both production frontend roots and the
three backend files below. The live HTML, JavaScript, and CSS matched the
locally verified build by SHA-256; `/api/health` returned HTTP 200 and the
anonymous `/api/clients` endpoint remained protected with HTTP 401.

| Production file | SHA-256 |
|---|---|
| `app/Http/Controllers/RouterTelemetryController.php` | `c4bebbdbae634496025a622d56f213fdeb501a9edbd4736d69a701fe8fc08159` |
| `app/Services/RouterTelemetryService.php` | `c6081e7c34f4087459ebdd811442887fdf3147eac038a8d8c188d6864b88ecff` |
| `app/Services/ClientInventory.php` | `f8ced4bd6e1c21d5f5eaea6e8f6fa6485eeb194675be495247cf8ecf2534dce1` |
| `public/index.html` (both frontend roots) | `0e8217ef2c9f3c919143f440819f0545cee7ce582b4824c4352313e205edb082` |
| `assets/index-CrYFtuxs.js` (both frontend roots) | `72821024ef5f74f2409478bc7a67303247fb5b955adb1a511feeab5ec6fc223f` |
| `assets/index-BYytWbqu.css` (both frontend roots) | `96553a736a61b53892084db834f22270d50841235575c05712d56c5d29c7346f` |

Private rollback copies of the three backend files and both prior entry pages
are retained outside the document root at
`/home/pandatechsco/tmp/bradha-arp-20261024T090800Z/backup/`. No migration,
RouterOS script, credential, or scheduler was changed. The official current
[ARP CLI reference](https://manual.mikrotik.com/docs/cli-reference/ip/arp/)
documents dynamic entries and warns that ARP entries may be stale/probing, so
ARP observations remain unconfirmed. RouterOS 7.23.1 field access still needs a
manual router-side one-shot validation. This does not expose devices hidden
behind downstream DHCP/NAT routers; enroll those routers separately or use
AP/bridge mode.

### Canonical console and RouterOS generator correction — 2026-10-24

The canonical console is now `https://onlineconsole.pandatechs.co.ke/`, as
selected by the operator. `onlineconsole.communitygroup.co.ke` was still
serving generator v1.1.6 and must not be used to generate RouterOS scripts.
The v1.1.6 script failed RouterOS 7.23.1 import at its multiline ARP `:if`
condition containing `&&`. Generator v1.3.1 replaces compound conditions
with nested `:if` statements and labels its script header and download
filename with the generator version.

The v1.3.1 frontend build is deployed only to the canonical pandatechs host.
Its live JavaScript hash matched the local build; `/api/health` returned
HTTP 200, and an unauthenticated telemetry POST returned HTTP 401. The
14 generator tests and production build passed. RouterOS acceptance and a
new authenticated telemetry report remain unverified. Generate only from
the canonical host, confirm the downloaded script header says `v1.3.1`, run
a one-shot report, and enable the scheduler only after the report is
accepted. A script source containing a telemetry token was pasted during
troubleshooting; revoke that token before generating the replacement.

No router configuration is changed automatically by the application or by
this repository's generated-file flow.

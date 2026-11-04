# Bratha Matu Console — Client Handover

**Prepared:** 24 October 2026 (EAT)  
**Canonical production console:** <https://onlineconsole.pandatechs.co.ke/>  
Use the pandatechs hostname for login, telemetry setup, and generated scripts.
**Status:** Deployed; client acceptance items remain as recorded below. This
document contains no passwords, API tokens, private keys, or generated
RouterOS scripts.

## 1. What the system does

Bratha Matu Console is a browser-based network operations dashboard for
monitoring MikroTik routers and their observed clients. Administrators can
review router status, system resources, interfaces, traffic, routes, DHCP
leases, wireless registrations, and client history. A telemetry router reports
outbound to the console; the console does not need to open a connection back to
that router.

The existing dashboard is intended for monitoring. Its MikroTik integration
uses fixed, read-oriented operations. The script generator prepares a file for
an authorized operator to review and apply manually; the console cannot import
or execute commands on a router.

## 2. Application stack and data flow

| Layer | Technology / role |
|---|---|
| Browser application | React 18, TypeScript, Vite, and Tailwind CSS |
| Server API | Laravel 13 on PHP 8.3 or newer |
| Hosting | cPanel with HTTPS; Laravel application files are kept outside the public web root |
| Database | Production MySQL/MariaDB; local development uses SQLite |
| Browser sign-in | Laravel Sanctum-backed authenticated sessions |
| Router management transports | RouterOS Binary API/API-SSL or HTTPS REST, each selected per router |
| Production-friendly router path | RouterOS initiates authenticated HTTPS telemetry POSTs to Laravel |
| Supported production test device | MikroTik RB951-2n running RouterOS 7.23.1 stable |

For telemetry, the flow is:

```text
RouterOS read-only script
    -- outbound HTTPS, certificate validation, per-router bearer token -->
Laravel telemetry endpoint and database
    -- authenticated API -->
Browser dashboard
```

The router sends system resource values, interface counters, IP/route data,
DHCP lease observations, and wireless registration observations where the
router exposes a supported wireless menu. Successive interface counters are
used to estimate traffic rates. The computer used to configure the router
(including the Kali laptop used for lab work) is not a production relay and
does not need to remain powered on.

### System capabilities and practical benefits

| Capability | What administrators can do | Practical benefit |
|---|---|---|
| Network overview | Review router totals and online, offline, degraded, stale, and unknown states, along with client and traffic summaries. | Quickly see where attention may be needed, while stale/unknown data is not mislabeled as a confirmed outage. |
| Router inventory and detail | Find routers, filter by status, open a router's detail view, and review its identity, RouterOS version, resources, interfaces, addresses, routes, and available lease data. | Keep device and site context together and investigate a specific router without treating an old observation as live. |
| Client inventory | Search/filter discovered devices and review available MAC, IP, hostname/alias, router, interface, signal, traffic counters, and last-seen information. | Help identify and compare observed devices; retain historical clients after they disconnect. |
| Traffic monitoring | Review available interface counters and rates derived from successive telemetry reports, or supported live reads for management-connected routers. | Observe traffic trends and compare activity across interfaces. Values depend on the router transport and successful, recent collection. |
| Network health | Review available CPU, memory, storage, uptime, temperature, or latency metrics per router. | Spot resource pressure where the router provides the relevant measurement; unavailable metrics remain blank rather than being invented. |
| Incidents and offline center | Review current/recent status issues and router records needing follow-up. | Give operators a place to triage reported conditions; telemetry staleness alone is not proof of physical disconnection. |
| Reports and export | Review network summaries, router inventory, incidents, and client totals; export a CSV report. | Support routine operational reviews and sharing a point-in-time summary with authorized staff. |
| Router onboarding | Super administrators can add router profiles and prepare a router-specific, reviewable setup or telemetry script. | Add routers through a controlled process without giving the web application remote terminal execution. |
| Mobile web app | Use the responsive console on smaller screens and optionally install its public app shell where the browser supports installation. | Easier access from a phone; the offline shell does not make cached router/API data current or available offline. |

The console is a monitoring and operational-visibility tool, not a network
controller or a replacement for RouterOS administration. It does not
automatically change Wi-Fi, firewall, routing, user, or service settings; it
does not promise predictive fault detection; and it cannot show a device that
the router has not reported. It helps staff make decisions from collected
observations, with report timestamps and uncertainty visible.

## 3. Project timeline

The timeline combines the repository's dated history, deployment records, and
operator-verified RouterOS results. A successful test at a given date is not a
claim that the same router remains online now.

| Date | Milestone |
|---|---|
| **8 October 2026** | The repository records the initial application work and MikroTik configuration-page work. The project began as a browser-based network operations console. |
| **9–12 October** | The Laravel API and router integration were established. The design added authenticated API access, per-router transport selection, encrypted stored management credentials, fixed read-command restrictions, TLS validation, and deployment preparation for cPanel. |
| **12 October** | The RB951-2n was checked from the local lab environment using certificate-verified HTTPS REST. The observed router reported RouterOS 7.23.1 stable; the lab read returned 8 interfaces, 2 IP addresses, 3 routes, and 2 DHCP leases. This proved local lab connectivity, not cPanel-to-router REST reachability. |
| **12–20 October** | Router-originated HTTPS telemetry was staged and debugged. Work covered RouterOS Fetch permissions, HTTPS certificate checks, bearer authentication, RouterOS value serialization, validation failures, and the distinction between a received report and a recurring schedule. The production endpoint was confirmed to reject unauthenticated requests. |
| **20–22 October** | Production reports exposed stale/incomplete inventory and payload-shape issues. Server handling was improved so stale telemetry is not described as confirmed router failure, and incomplete wireless data cannot incorrectly mark unreported clients offline. RouterOS array construction and field conversions were corrected. |
| **22 October, 22:41 UTC (23 October, 01:41 EAT)** | An accepted RB951 report was observed with wireless collection **4/4 complete** and DHCP collection **4/4 complete**. The following report, observed at 22:41:22 UTC and received two seconds later, was also complete. The signal parser returned measured readings for the four reported devices. The operator had set the existing scheduler to a 10-second interval; this was an operator action, not an automatic console change. |
| **23 October, later production check** | During the mobile/PWA production verification, the authenticated browser inventory showed **6 saved clients, 2 online, 4 offline, and 0 stale**. Client presence is time-dependent, so this later snapshot differs from the earlier 4/4 registration report and must not be interpreted as a permanent count. |
| **23 October** | The branded installable web app and responsive layout were deployed. Browser emulation checked 12 routes at 10 viewport widths (120 checks) without document-level horizontal overflow. A release-hash-based service-worker version was deployed and its update prompt/activation were observed in a browser. Physical Android installation was not tested. |

## 4. Current verified state and limits

### Verified

- The public console and API health endpoint were reachable over HTTPS during
  the 23 October production checks.
- The production telemetry endpoint requires authentication; an
  unauthenticated request is rejected.
- The RB951-2n on RouterOS 7.23.1 has successfully delivered accepted
  authenticated reports.
- At the dated 01:41 EAT acceptance check, both wireless and DHCP collections
  were complete at 4/4; signal values were returned for those wireless
  observations.
- A later browser check confirmed the deployed responsive shell, manifest,
  service worker, and public PWA icons were served successfully.
- The PWA worker caches public shell/static assets only. It excludes
  `/api/` and `/sanctum/` so authenticated API data is not stored in its cache.

### Not established by those checks

- **Current router freshness:** the last recorded complete 4/4 report is a
  historical acceptance test. The 2-online/4-offline browser observation is
  also historical. Check the live router report time and current client list
  before treating any displayed status as current.
- **Long-duration scheduler reliability:** consecutive reports were observed,
  but the records cited here do not constitute a multi-day uptime test.
- **Formal laptop-off acceptance:** telemetry is sent directly from RouterOS
  to the public console, not through Kali. However, the evidence reviewed for
  this handover does not record a dedicated test in which the laptop was
  confirmed powered off while multiple subsequent reports were checked.
- **Every MikroTik model/version:** the generator supports separate legacy
  `/interface wireless` and modern `/interface wifi` collection paths. The
  RB951 test exercises its legacy wireless path; modern Wi-Fi path behavior
  has not been physically verified on a newer router.
- **Every DHCP hostname:** RouterOS may report an empty host name when a
  device does not supply one. The console cannot infer a hostname that the
  router did not receive.
- **Android native installation:** install UI behavior was browser-emulated;
  a physical Android install and standalone session remain to be tested.
- **Cloud polling of private routers:** cPanel cannot be assumed to reach a
  private router address. HTTPS REST is only appropriate if the hosting
  provider confirms an approved VPN/private route.

## 5. Sign-in and normal dashboard use

1. Open <https://onlineconsole.pandatechs.co.ke/>.
2. Sign in using the account supplied separately by the client's authorized
   administrator. Credentials are deliberately not included in this
   handover.
3. Use the dashboard and navigation for routers, clients, traffic, network
   health, and reports.
4. On the Clients page, use the page's refresh action when a fresh inventory
   is needed. The page also refreshes on its normal cadence while open.
5. Check each router's last report/telemetry freshness before relying on its
   metrics. The public API health indicator only means the console API
   responded; it does **not** mean every router has reported recently.
6. When assigning a static IP in MikroTik, the client row displays the
   configured RouterOS static DHCP lease address. A lease is matched by MAC
   and does not itself mark the device online. If the router's last observed
   address differs, the row shows that mismatch separately.

Static DHCP lease display requires telemetry script generator v1.3.5 or
later, which reports RouterOS lease `dynamic` metadata. After this application
release is deployed, download/import the updated router script and verify one
successful report before expecting newly assigned static leases to appear.
The script only reads RouterOS state and posts telemetry; it does not change
DHCP configuration. Do not paste the generated script into chat because it
contains the router's revocable telemetry credential.

### Reading client status correctly

- A wireless client is considered present from a current wireless registration
  observation, not simply because a DHCP lease remains bound.
- A wired client is considered present from a current dynamic, non-local
  bridge-host observation. Static bridge entries and the router's own local
  bridge entry are excluded. DHCP leases can add an address and host name but
  do not independently prove that a device is online.
- Dynamic IP-to-MAC entries from the router's ARP table can discover additional
  directly visible neighbors. These appear as **Neighbor (ARP)** with
  **Unconfirmed** status: an ARP cache entry may be aged or persist after a
  device stops responding, so it is not treated as proof of online or offline
  status.
- Wired-client ingestion and Wi-Fi/wired labels were deployed on 24 October
  2026. The RB951-2n bridge-host fields still require a manual RouterOS 7.23.1
  verification and fresh accepted report before wired discovery is confirmed
  for that router.
- ARP-neighbor discovery is also deployed. ARP-only rows are labeled
  **Neighbor (ARP)** and remain **Unconfirmed**; they are never treated as
  proof that a device is online or offline. A new v1.3.0 RouterOS script must
  be manually imported and verified before that router reports ARP entries.
- The bridge-host table represents MAC addresses learned on bridged Layer-2
  interfaces. Devices on routed or otherwise non-bridged LAN segments may not
  appear in this inventory.
- A complete wireless report is needed before an absent previously-online
  client can be marked offline. If collection is incomplete, the console
  preserves last-confirmed status and marks the observation as uncertain/stale.
- ARP-only neighbors are never marked online or offline by ARP presence or
  absence; use the current Wi-Fi or bridge tables for confirmed presence.
- Historical client identities are retained for reporting; "saved clients" is
  not the same as "currently connected clients."
- A blank DHCP host name is a missing router-provided name, not an ingestion
  failure.
- A new device appears after a valid report containing its MAC address is
  accepted. It will not necessarily appear at the instant it associates.
- The default telemetry stale threshold is 15 seconds. A missing report
  beyond that period is **stale/unknown**, not proof that the router is
  unreachable.

## 6. Adding another router (super administrator)

Each router must have its own console record, exact RouterOS identity,
credential, and generated `.rsc` file. Never copy a token/script between
routers.

### A. Gather router-specific facts

Connect to the intended router through an already trusted management method
and run these read-only checks in **RouterOS Terminal**:

```routeros
/system identity print
/system resource print
/system clock print
```

Record the exact identity, board/model, RouterOS version, and confirm the
router clock is correct. Do not paste credentials, tokens, full generated
scripts, or sensitive network details into chat or support tickets.

### B. Create its console profile

1. Sign in as a super administrator and open **MikroTik Configuration**
   (`/mikrotik-config`).
2. Select **Add Router**.
3. Enter a unique display name, the router's management host/IP as inventory
   metadata, the exact RouterOS identity, and select
   **Router-initiated telemetry (no inbound router API)**.
4. Save the router. For this transport, the console will not connect to the
   entered host and does not store a RouterOS API username or password.
5. Use the telemetry section on that router's card. Choose an interval. The
   normal starting point for a fleet is **60 seconds**. The UI also offers
   10, 120, and 300 seconds.

### C. Verify HTTPS from that router

Run this in the target router's **RouterOS Terminal** before issuing a
credential:

```routeros
/tool fetch url="https://onlineconsole.pandatechs.co.ke/api/health" check-certificate=yes output=none
```

Proceed only if it succeeds with certificate verification enabled. If it
fails, stop and resolve the router clock, DNS, routing, or trust-chain issue.
Do not disable certificate checking or substitute HTTP.

### D. Generate, upload, and import the script

1. In the router card's telemetry section, confirm the preflight checkbox and
   choose **Issue token & generate script**. If replacing a script, use
   **Rotate token & generate script** only when ready to install the new file:
   rotation immediately invalidates the previously installed token.
2. Download the `.rsc` file. It contains a bearer secret. Keep it private,
   use it only on the matching router, and delete it securely after import.
3. In Winbox or WebFig, upload the `.rsc` through **Files**.
4. In that same router's **RouterOS Terminal**, use the exact import and
   one-shot commands displayed on the router card. The general import form is:

   ```routeros
   /import file-name="the-exact-downloaded-file.rsc"
   ```

5. Run the generated one-shot script manually. Importing does not start a
   scheduler and the console does not configure the router automatically.
6. In the console, verify a new report time and confirm the collected and
   received counts match the actual router counts for interfaces, wireless
   clients, and DHCP leases. Do not schedule the script if the report is
   rejected, incomplete, or stale.

The generator first attempts the modern `/interface wifi` registration table
and falls back to `/interface wireless` for legacy devices. Verify the exact
router's collection counts before relying on either path. The feature is not
a guarantee that all RouterOS releases and wireless packages behave the same.

### E. Schedule only after successful manual verification

After a valid report has been accepted:

1. Inspect the router's scheduler for the unique Bratha telemetry entry.
2. Use the guarded scheduler command shown by the console for that router and
   interval. Maintain exactly one entry; update the existing one rather than
   adding duplicates.
3. Confirm the entry is enabled, has the expected interval and event, and
   reports successfully at least twice at the expected cadence.
4. Recheck the console's report timestamp and client completeness after the
   scheduler runs.

The API currently throttles telemetry requests at 30 per minute. A 10-second
interval sends about 6 reports per minute per router; several routers may
share one public source IP. Use 60 seconds as the fleet default and review
rate-limit behavior before deploying short intervals across many routers.

## 7. Security and operating rules

- Keep RouterOS REST, Binary API, SSH, Winbox, and other management services
  private or restricted to an approved management network. Do not expose
  management ports to the Internet for this dashboard.
- Telemetry requires outbound HTTPS from the router; it does not require
  inbound access to the router.
- Keep `check-certificate=yes`. No HTTP fallback or certificate bypass is
  supported.
- Telemetry credentials are dedicated per router and revocable. Laravel keeps
  a hash, not a reusable plaintext telemetry token. Issuing a replacement
  revokes the old token immediately.
- Never use the router administrator password as the telemetry token.
- Treat a downloaded `.rsc` file, its contents, and clipboard contents as
  secrets. Do not email, commit, or post them.
- Do not delete/recreate a router profile merely to clear stale status; that
  can lose the relationship to its stored inventory and telemetry credential.
- Do not modify a router's firewall, service ports, user policy, wireless
  settings, or Scheduler without an authorized operator and a reviewed
  change/rollback plan.
- Maintain protected backups of the production database and Laravel
  `APP_KEY`. Losing or rotating `APP_KEY` without a planned credential
  migration can make encrypted management credentials unreadable.

## 8. Troubleshooting

| Symptom | Safe checks |
|---|---|
| Router says waiting / no recent report | Check its last report time, router clock, DNS, outbound HTTPS, and the verified health preflight. Inspect that exactly one expected scheduler entry is enabled. Run the generated script manually and read its safe RouterOS error. |
| HTTP 401 / authentication failed | Confirm this file belongs to this router and was generated after the latest token rotation. A rotated token makes the old script invalid. Do not paste the token; issue a replacement only when ready to replace the script. |
| HTTP 422 / validation error | Use the current generated script and exact RouterOS version. Review the safe error category/field name and compare source/collected counts. Do not loosen endpoint validation to make a malformed report appear successful. |
| Wireless/DHCP count is lower than the router's live count | Compare the RouterOS registration/lease counts with the report's collected and received counts. Incomplete snapshots do not prove missing clients are offline. Verify whether the router uses `/interface wifi` or legacy `/interface wireless`. |
| Client has an IP but is not online | A bound DHCP lease is not proof of an active wireless registration. Check the current registration table and the freshness/completeness of the report. |
| Router is stale but still responds locally | Stale means no accepted report inside the freshness window. It is not a positive test of inbound reachability. Check the scheduler, outbound DNS/TLS, and endpoint response. |
| HTTPS preflight fails | Stop. Check router date/time, DNS, internet route, and trusted public CA chain. Never work around the failure by disabling certificate checks or using HTTP. |

When requesting support, provide the router model, exact RouterOS version,
report timestamp, safe status/error category, and collection counts. Redact
tokens, Authorization headers, passwords, generated script bodies, private
keys, and any unrelated client personal data.

## 9. Deployment, backups, and support

- The cPanel deployment procedure, safe migration flow, and production
  constraints are documented in
  [CPANEL_BROWSER_DEPLOYMENT.md](./CPANEL_BROWSER_DEPLOYMENT.md).
- Router transport and private-network requirements are in
  [MIKROTIK_PRODUCTION_CONNECTIVITY.md](./MIKROTIK_PRODUCTION_CONNECTIVITY.md).
- Router profile/script generator behavior is in
  [MIKROTIK_CONFIGURATION_CENTER.md](./MIKROTIK_CONFIGURATION_CENTER.md)
  and [MIKROTIK_TERMINAL_SETUP.md](./MIKROTIK_TERMINAL_SETUP.md).
- Mobile/PWA behavior and its actual browser verification are in
  [MOBILE_RESPONSIVENESS_AND_PWA.md](./MOBILE_RESPONSIVENESS_AND_PWA.md).
- For RouterOS-specific behavior, consult the
  [official MikroTik RouterOS manual](https://manual.mikrotik.com/docs/) and
  select documentation for the router's installed version. Newer-version
  documentation is useful reference material, not proof that an untested
  feature behaves identically on RouterOS 7.23.1.

Keep the production deployment token, database credentials, super-admin
password, Laravel application key, SSH keys, and router telemetry tokens in
the client's approved password manager/secret store. Share access through the
client's authorized account-management process, not in this document.

Before accepting operational handover, the client administrator should:

- sign in and confirm the assigned role and account recovery process;
- review the currently displayed router report times and online-client counts;
- verify one router's scheduled report at the chosen cadence after the setup
  operator has left the network;
- back up the database and protect the `APP_KEY`;
- test the procedure to revoke/replace one router token without exposing it;
- test the dashboard on a real Android device if native install is required.

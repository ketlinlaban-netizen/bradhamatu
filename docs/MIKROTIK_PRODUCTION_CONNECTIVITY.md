# MikroTik production connectivity

## Transport options and verified boundary

The existing Laravel provider contract is served by two live transports:

| Transport | Intended environment | RouterOS interface |
|---|---|---|
| `mikrotik` | Local development; preserves the existing `evilfreelancer/routeros-api-php` Binary API client | TCP API/API-SSL, normally 8728/8729 |
| `rest` | cPanel only when HTTPS REST and private routing are available | HTTPS `www-ssl` REST endpoint, server-configured port (default 443) |
| `mock` | Explicit UI/demo mode only | Fictional data, visibly tagged `mock`; no router connection |

Selection is server-side through `ROUTER_PROVIDER`; no browser request selects
the transport, target URL, host, port, or RouterOS command. Both live
transports use the same `RouterProvider` mapping, router inventory, encrypted
database credentials, authentication/authorization, and application API
contract.

Both local live operation and cPanel-to-router connectivity remain distinct
facts: the operator reported the local MikroTik connection working, but this
workspace has not contacted a physical router or cPanel. The target cPanel
host's routes to the saved private RFC1918 router network are unknown.

## Provider contract and RouterOS REST mapping

The provider contract remains the methods in `RouterProvider`. RouterOS REST
uses `GET /rest/<menu>` for the existing fixed `print` reads and maps
`.proplist` plus the existing interface filter to REST query parameters. The
dashboard/API mapping is shared, so RouterOS string values are converted by
the same application parsing used with the Binary API.

| Existing provider operation | Binary API commands | HTTPS REST equivalent |
|---|---|---|
| `getRouters`, `getRouter`, `searchRouters` | Identity, resource, interface print reads | `GET /rest/system/identity`, `/rest/system/resource`, `/rest/interface`; remaining fields/search are computed from the same Laravel inventory |
| `getStatus` | `/system/resource/print`, `/system/identity/print`, `/interface/print`, `/ip/address/print` | `GET /rest/system/resource`, `/rest/system/identity`, `/rest/interface`, `/rest/ip/address` |
| `getInterfaces` | `/interface/print`, `/ip/address/print` with interface filter | `GET /rest/interface`, `/rest/ip/address?interface=<encoded-name>` |
| `getClients` | `/interface/wireless/registration-table/print`, `/ip/dhcp-server/lease/print` | `GET /rest/interface/wireless/registration-table`, `/rest/ip/dhcp-server/lease` |
| `getTraffic` | One-shot `/interface/monitor-traffic` and `/interface/print` | `POST /rest/interface/monitor-traffic` with the fixed `interface=all`, `once=""` body; then `GET /rest/interface` |
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

## Candidate topology

```text
Browser
  | HTTPS (dashboard/API; authentication)
  v
cPanel Apache/PHP + Laravel + database
  ^ authenticated HTTPS observations (future agent ingestion only)
  |
trusted outbound-only monitoring agent on Kali / private VPS / LAN host
  | private management LAN or explicitly permitted VPN
  v
MikroTik RouterOS (read-only account, restricted source IP, selected Binary API or HTTPS REST)
```

The diagram's agent-to-application ingestion is a **future design**, not
implemented in this codebase. No agent, ingestion endpoint, signing key,
replay-protection store, or external collection service exists today.

## Options

1. **Direct cPanel to router:** Use only if the cPanel provider explicitly
   supports private routing/VPN connectivity to the management subnet and
   confirms outbound HTTPS plus routing isolation. REST uses HTTPS on the
   configured private management endpoint (normally TCP 443). No such support
   is verified.
2. **Private VPN:** Viable only if both cPanel and router-side network permit a
   properly isolated VPN route. Shared hosting often cannot install VPN
   daemons or alter routes; obtain explicit provider support first.
3. **Outbound agent:** Preferred if local/Kali connectivity remains available
   but cPanel cannot route privately. Agent would collect only fixed,
   allowlisted read-only metrics locally, then POST normalized observations to
   an HTTPS endpoint. It must not tunnel arbitrary commands or send RouterOS
   credentials to the public dashboard service.
4. **Managed VPS/private server:** Safest practical alternative if cPanel
   cannot provide private routing or reliable scheduled execution. The private
   monitoring host can collect router data; cPanel may serve the UI only if a
   properly authenticated observation ingestion design is implemented.

### Recommendation pending provider confirmation

First ask the cPanel provider whether an isolated route/VPN to the private
router management network is supported. If it is, prefer the existing
server-side provider over adding a second trust boundary, subject to validating
the source restriction, certificates, and bounded scheduled collection. If it
is not supported, do not try public port exposure; use a managed private VPS
that can reach the routers and can run a bounded collector. If cPanel must
remain the dashboard host, separately design and authorize an outbound-only
agent that sends normalized, authenticated observations over HTTPS. This
conditional recommendation avoids assuming any unverified provider or network
capability; no topology is selected or deployed yet.

## Bounded collection design proposal (not implemented)

The current app polls routers in response to selected requests. This is
appropriate only for a small operator workload with short-lived caching; it
does not provide reliable periodic monitoring and can multiply router API
traffic as concurrent users navigate. Do not describe the current request
cache as persistent telemetry.

If consistent online/offline state is required, implement and test a scheduled
collector only after the cPanel network path is confirmed:

- Start with one collection cycle every 60 seconds, then tune from measured
  router count, query volume, and provider cron limits. Do not poll on every
  page load.
- Retain finite connection/query timeouts (current defaults are 3-second
  connect and 5-second query) and enforce a bounded overall cycle deadline.
  Do not retry within a cycle; record a failure and wait for the next cycle.
- Process routers in bounded batches, with one distributed lock per router
  and a cycle lock whose TTL exceeds the maximum execution window. Skip a
  locked router rather than starting duplicate collection.
- Update the existing `last_attempted_at`, `last_seen_at` (last successful
  contact), and `last_error_category` fields on every collection attempt.
  Persist normalized metric snapshots only if the product needs last-known
  values between cycles; on failure retain prior good values and show
  failure/degraded state, never mock values or zero metrics.
- Mark a successful snapshot stale after more than three cycles (initially
  180 seconds at a 60-second schedule). Display its collection timestamp
  separately from request time.
- Restrict snapshots to approved status, resource, interface, wireless/client,
  and traffic metrics already used by the app. Validate types and payload
  size; never persist credentials, raw API requests, or unbounded responses.

The interval, timeout budget, and 180-second stale threshold are proposed
starting values, not measured guarantees. The router metadata fields already
exist, but no scheduled collector, persistent metric-snapshot mechanism,
collection locks, or periodic freshness presentation exists yet. Hosting
cron/runtime limits and router reachability must be confirmed before
implementation.

## Agent design requirements if separately authorized

- Outbound-only HTTPS from agent to app; no public inbound agent control port.
- TLS certificate and hostname verification remain enabled.
- Per-agent scoped credential, preferably short-lived and rotatable, or
  request HMAC signatures with key versioning and constant-time verification.
- Include timestamp, nonce/unique event ID, and bounded clock-skew checks.
  Persist nonce/idempotency state to reject replays.
- Strict schema validation: known router IDs, fixed metric names/types/ranges,
  bounded payload size, collection timestamp, and explicit source/agent ID.
- Reject unknown router IDs, unexpected fields, stale/future timestamps,
  duplicates, oversized payloads, and unauthenticated requests.
- Rate-limit ingress, audit accepted/rejected metadata without logging tokens,
  and keep raw RouterOS credentials only on the trusted agent.
- No `command`, `terminal`, shell, RouterOS API word, credential, or
  router-configuration fields in accepted payloads.
- Mark observations stale after a documented interval. A failed collection
  must not be converted into zero or fabricated metrics.
- Bounded polling cadence, finite TCP/query timeout, one instance per router,
  and local lock/lease to prevent overlap.

This work does not add that provider or endpoint: deciding that architecture
requires the cPanel networking response and explicit approval of an external
agent trust boundary.

## Router-side constraints

For local Binary API, restrict API/API-SSL to the trusted source address and
use API-SSL with a valid trusted certificate when supported end-to-end. For
REST, enable only HTTPS `www-ssl`, use a trusted matching certificate and a
dedicated `read,api,rest-api` user group after confirming the target version
supports those policies. Keep either service restricted to the approved
collector/cPanel source address. Never broaden a service to the internet,
disable certificate checks, add `write`/user-management privileges, or expose
arbitrary RouterOS commands.

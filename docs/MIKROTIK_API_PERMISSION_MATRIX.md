# MikroTik API Permission Matrix

This matrix reflects the currently implemented server allowlist, not an assumption that all RouterOS commands have an equally scoped built-in permission. Binary commands are sent through `RouterReadOnlyGuard`; REST applies the same exact command guard and permits traffic only as a bounded one-shot monitor operation.

| Application operation | Binary API command | REST method/path | Application parameters / response use | Required RouterOS group policy |
|---|---|---|---|---|
| Identity | `/system/identity/print` | `GET /rest/system/identity` | identity `name` | `read` + transport (`api` or `rest-api`) |
| System resources / health | `/system/resource/print` | `GET /rest/system/resource` | version, board, memory, CPU, uptime and storage values; numeric strings are parsed by the provider | `read` + transport |
| Interface inventory and counters | `/interface/print` | `GET /rest/interface` | `.proplist` selects interface name/type/state/MAC/counters/MTU; string values are normalized by provider | `read` + transport |
| Interface IP addresses | `/ip/address/print` | `GET /rest/ip/address` | address/interface; interface-specific filter may be applied | `read` + transport |
| DHCP leases | `/ip/dhcp-server/lease/print` | `GET /rest/ip/dhcp-server/lease` | address, MAC, hostname, interface, status | `read` + transport |
| Wireless settings | `/interface/wireless/print` | `GET /rest/interface/wireless` | SSID, frequency, channel, mode and related read values | `read` + transport |
| Wireless registrations | `/interface/wireless/registration-table/print` | `GET /rest/interface/wireless/registration-table` | MAC, interface, signal, rates, uptime and bytes | `read` + transport |
| Live interface traffic | `/interface/monitor-traffic` | `POST /rest/interface/monitor-traffic` | only `interface=all`, bounded `once`; numeric bits/s strings are converted by provider | `read` + transport |

The REST transport strips the Binary API `/print` suffix to produce the documented RouterOS REST resource and uses HTTPS, certificate-chain and hostname verification, disabled redirects, bounded timeouts, destination restrictions, and bounded response parsing. The monitor request is not an arbitrary POST: the guard requires the exact command and arguments.

## Group policies

- Binary API / API-SSL: `read,api`.
- HTTPS REST: `read,rest-api`.
- Both transports: create separate users/groups; each should receive only its own `read` plus protocol policy.

`read` is an inherent coarse-grained RouterOS policy and may grant visibility beyond these paths. RouterOS does not offer per-command allowlists through a user group. The server-side console guard is narrower but does not narrow direct access by a RouterOS account. Test actual RouterOS release/service behavior and review policy requirements before production use. REST requires RouterOS 7.1+; Binary API script gate is RouterOS 6.49+ or 7.x. Target device versions remain unverified.

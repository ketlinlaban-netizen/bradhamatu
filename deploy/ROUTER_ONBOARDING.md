# Adding a MikroTik router to the console (master runbook)

Everything here is verified against the running code, not assumed. Two instances exist
and they are isolated tenants:

- `https://onlineconsole.pandatechs.co.ke` (host `pandatechs`, SSH available)
- `https://onlineconsole.communitygroup.co.ke` (host `communi6`, **no SSH** — cPanel
  File Manager + phpMyAdmin only)

A telemetry credential minted on one instance can never authenticate on the other; only
the SHA-256 of the token is stored.

## The numbers that decide everything

| gate | value | source | consequence if violated |
|---|---|---|---|
| report interval | **5 s** | `config/mikrotik.php` → `telemetry_stale_seconds` default **15** | any interval > 15 s leaves the console row reading `stale` most of the time. Stale = ~3 missed beats, so 5 s is the only correct answer. |
| rate limit | `throttle:30,1` keyed on **source IP** | `routes/api.php:34` | 5 s = 12 req/min, so **only 2 routers may share one public NAT egress IP**. The 3rd gets `429`. 60 s would allow 30. |
| clock skew | **300 s** | `telemetry_max_clock_skew_seconds` | `422 stale_telemetry`. These boards have **no RTC battery**: every reboot resets the clock, and a `ntp change time` jump can rewind it by days. |
| payload size | **49152 B** (script self-limits at 48000) | `telemetry_max_payload_bytes` | `413` on the server, `payload limit exceeded` in the router log. |
| identity | byte-for-byte | `RouterTelemetryController.php:366` `hash_equals` | `422 router_identity_mismatch` **after** a valid token. Scheduler looks healthy, run-count climbs, nothing is ever stored. |
| RouterOS version | **v7 only** | generated line 414 uses `:serialize` | v6 installs, schedules, reports the script is fine, and sends **zero** reports. |

## RouterOS-side blocks (Winbox CLI, paste one block at a time)

Block E prints the scheduler; the scheduler holds no secret. Never run
`/system script print` on a board carrying a collector — **any** print of a
`bm-telemetry-*` script, even `where`-filtered and even without `detail`, echoes
`source=` and therefore the bearer token in plaintext.

### A. Eligibility — run before you touch the console

```
/system resource print
/system identity print
/system package print
```

Stop unless all of these are true:

- `version` is 7.x. On v6 there is no router-initiated path available at all, because
  `rest` and `binary` both require the console to dial *in*, which is impossible from
  shared hosting behind a private address. A v6 board must be upgraded to v7 first.
- `total-memory` is at least 64 MiB and `free-memory` is comfortable — this is a 64 MiB
  RAM board class and the collector builds a JSON document in memory.
- the `name` printed by `/system identity print` is what you will type into the
  console's Identity field, exactly, same case.
- `cpu-load` is noted. It is an operator trade-off, not a gate.

### B. Reachability, TLS and clock

```
/ip address print
/ip route print where dst-address="0.0.0.0/0"
/system clock print
/system ntp client print
/tool fetch url="https://onlineconsole.communitygroup.co.ke/api/health" mode=https check-certificate=yes output=none
```

Required: a default route exists, the clock is today's date, `status: synchronized`, and
the fetch answers `status: finished code: 200`. Do not proceed on
`check-certificate=no` — that flag hides both causes of the `(6) SSL relate invalid
certificate or time` error (missing trust anchor, and a wrong clock).

`/system ntp client send-now` **does not exist** on 7.23.1 — it is a `bad command name`
there. Use `/system ntp client print` to read state, and `/system clock set` only to
recover manually.

### C. Refuse to stack collectors (leak-safe counts, must all be 0)

```
:put [:len [/system script find where name~"bm-telemetry"]]
:put [:len [/system scheduler find where name~"bm-telemetry"]]
:put [:len [/file find where name~"bradha-matu-telemetry"]]
```

Any non-zero number means a collector is already installed. One board can hold several,
and it is easy to miss — this happened on the Static site, where a pandatechs collector
and a communitygroup collector ran side by side, one of them failing forever while its
scheduler read healthy. Remove the stale one with block G **before** installing, and
scope every removal to the exact `uuid8`, never to the shared `bm-telemetry` prefix.

### D. Import (self-activating, two steps only)

There is no "start the scheduler" step. The package creates the script, creates the
scheduler with `start-time=startup policy=read,test interval=5s`, and runs itself once at
the end of the import, so the first report fires during the import.

```
/file print where type="script"
/import file-name="bradha-matu-telemetry-<uuid8>-v1.4.1.rsc"
```

Quote the name. Chrome saves repeat downloads as `...v1.4.1 (1).rsc` and the router
stores the space and parentheses verbatim, so an unquoted name gives
`Cannot open import file, file does not exist` for a file that is really there.

A `.rsc` can **only** be imported — never pasted. `:local` scope dies at each newline and
an open `do={` parks the CLI at a `value:` prompt.

### E. Verify (60 seconds after import)

```
/system scheduler print detail where name~"bm-telemetry"
:put [:len [/log find where topics~"script" && message~"telemetry:"]]
```

`run-count` must be climbing. The count must be **0**.

Filter on `topics~"script"`, not on a spelling: v1.4.x logs `Bratha telemetry:` and older
builds log `Bradha telemetry:` — the substring `radha telemetry` matches only the second,
so a `radha` filter reports a clean `0` on a board that is failing every report. Real
failures carry topic `script,warning`; the token-leaking source dumps carry `system,info`,
which is why the topic filter is both spelling-proof and secret-safe.

To force a report immediately: `/system script run bm-telemetry-<uuid8>`.

### F. Server-side truth (the console UI can lie)

```sql
SELECT id, name, identity, ip_address, monitoring_enabled,
       telemetry_snapshot_id, telemetry_observed_at, telemetry_received_at, last_seen_at
FROM routers ORDER BY created_at;
```

Accepted reports are `Log::info` and a `401` or a `router_identity_mismatch` 422 writes
**no log line at all**, while `LOG_LEVEL=notice` discards info. So the daily log cannot
prove delivery — this query can. Correct steady state:
`telemetry_observed_at <= telemetry_received_at`, both within a few seconds, both advancing.

If `telemetry_observed_at` is **ahead of** `telemetry_received_at`, the deployed
`app/Services/RouterTelemetryService.php` is missing the timezone fix (pre-2026-11-02
builds). Apply `deploy/RouterTelemetryService.timezone-fix.php` to that host, then
clear the poisoned high-water mark, otherwise one report in every three hours is accepted
and the rest are `409 replayed_telemetry`:

```sql
UPDATE routers SET telemetry_observed_at = NULL, telemetry_snapshot_id = NULL
WHERE id = '<uuid>';
```

### G. Remove one router cleanly

Order matters: scheduler first, then script, then the plaintext file. Scope by exact
`uuid8`. Verify with the block C counts returning 0.

```
/system scheduler remove [find where name="bm-telemetry-<uuid8>-schedule"]
/system script remove [find where name="bm-telemetry-<uuid8>"]
/file remove [find where name~"bradha-matu-telemetry-<uuid8>"]
```

The `.rsc` in the file store **is** the credential in plaintext, on the laptop and on the
board. Delete it after import. A `remove [find …]` against a name that does not exist is
a silent no-op — do not retype it.

## Console-side sequence

1. Create the record: **Identity = the exact string from block A**, Name, private IPv4
   address, site/location, transport **Outbound HTTPS telemetry**, interval **5 s**.
   The stored address must be private: `MIKROTIK_ALLOWED_HOSTS` ships empty on
   communitygroup and the destination policy runs on every transport, so a public IP or
   hostname is rejected at save time. Raising that variable would weaken the guard — keep
   the LAN IP.
2. Issue the telemetry credential. **Read the endpoint host in the dialog before you
   download**: it must say the tenant you are onboarding. The endpoint is derived from
   `APP_URL` and the config fallback default is the *pandatechs* host, so a copied `.env`
   renders a valid-looking script that silently ships this client's client-list and lease
   data into the other client's production console. The generator validates the URL path,
   never the host.
3. Download, rename locally to drop spaces and `(1)`.
4. Blocks A → G on the board.

Re-issuing a credential overwrites the stored hash and silently invalidates **every
earlier download**. An old `.rsc` still imports, still schedules, still looks healthy and
then returns `401` forever. Never reuse a previously downloaded file.

## New-tenant checklist (when you stand up another console)

- `APP_URL` must be the new host and must be https — it is the tenant-isolation switch,
  and a blank value fails safe with `503 telemetry_endpoint_invalid`.
- `config:cache` is **active** on communi6-style installs: after any `.env` edit, delete
  `bootstrap/cache/config.php` in File Manager or nothing changes.
- `engine => 'InnoDB'` must stay pinned in both the `mysql` and `mariadb` blocks of
  `config/database.php`; that host defaults to MyISAM, which migrates "successfully" with
  `1071` on utf8mb4 primary keys and no foreign keys.
- every database and user on the account is recreated during a deploy — read the live
  cPanel lists, never a remembered name.
- `MIKROTIK_TELEMETRY_STALE_SECONDS` stays at 15 while the interval stays at 5 s. If you
  ever raise the interval, raise the window to ~3 intervals and clear the config cache.
- confirm the timezone fix is in that instance's `RouterTelemetryService.php` before the
  first router is added.

## Known fragility worth watching

On the lab RB951, `client1` on `ether1` sits in `searching...` while a stale
`192.168.40.100/24` address remains bound, and the default route depends on it. If that
lease is flushed, the route, NTP and telemetry all go with it. That is an address-plan
problem on the bench network, not a collector bug.

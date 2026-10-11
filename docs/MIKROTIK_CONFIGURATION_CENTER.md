# MikroTik Configuration Center

## Location and access

Open `/admin/mikrotik-config` in the authenticated administrator console.
The page manages the application's saved router inventory and displays
read-only connection results. Connection testing does not configure or execute
commands on a router.

## Current behavior

- Saved router hosts, API ports, TLS settings, usernames and credentials are
  managed by the Laravel backend.
- Router passwords are encrypted at rest using `APP_KEY`, hidden from model
  serialization, and masked in API responses. They cannot be viewed again
  after saving.
- The page can test connectivity using the server-selected provider. The
  browser cannot select a transport, RouterOS command, or arbitrary network
  destination.
- Removing a router removes its application inventory record; it is not a
  RouterOS configuration operation.
- Super administrators also have a local `.rsc` setup-script generator. It is
  separate from connection testing and never uploads or executes the script.
  The generated script can create a dedicated read/API user and enable or
  source-restrict the selected existing RouterOS API service if an operator
  later imports it manually.
- The generator is fixed-template, validates addresses, ports, usernames,
  passwords and certificate prerequisites, and adds conflict guards and
  preflight/verification/rollback guidance. It does not accept arbitrary
  RouterOS commands.
- Generated scripts contain plaintext credentials. They remain in browser
  memory until cleared or the page is left, and copied/downloaded output must
  be handled as a secret. Do not email, commit, upload or store it on shared
  storage.
- No RouterOS command execution, reboot, firewall, NAT, VPN, DHCP, hotspot,
  queue or wireless configuration control is provided.

## Transport and safety boundary

The local Binary API provider uses the approved read-only command allowlist
over ports 8728/8729. The optional REST provider uses HTTPS only, validates the
TLS certificate, pins the validated private destination, rejects redirects,
and accepts only its fixed read mappings and bounded one-shot traffic monitor
request. `mock` mode is fictional demo data and is explicitly labeled.

Provider selection and allowed hosts/ports are server-side configuration. Do
not expose RouterOS ports 8728 or 8729 to the public internet, disable TLS
verification, or treat generated scripts as applied configuration. Review any
script and verify network reachability and service restrictions independently
before manually applying it outside this application.

See [MIKROTIK_API_PERMISSION_MATRIX.md](./MIKROTIK_API_PERMISSION_MATRIX.md)
for the approved monitoring reads and
[MIKROTIK_PRODUCTION_CONNECTIVITY.md](./MIKROTIK_PRODUCTION_CONNECTIVITY.md)
for cPanel network prerequisites and unverified hosting limitations.

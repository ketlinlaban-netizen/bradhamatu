# MikroTik Configuration Center

## Location and access

Open `/mikrotik-config` in the authenticated Bratha Matu Console. The existing page and its router inventory remain in place. Script generation is available to the `super_admin` role, matching the backend's `manage-router-inventory` gate. The page does not call a RouterOS terminal endpoint and never executes generated content.

## Generator workflow

1. Choose the environment explicitly: Local Development, cPanel Production, Both, or Mock/Demo. Changing environments clears endpoint values and secrets so they are not carried across environments.
2. Choose Binary API, REST over HTTPS, or Both. For Both, enter separate usernames, passwords, endpoints, and source restrictions.
3. Inspect `/system resource print` on the router and enter the verified RouterOS version. The generator accepts Binary API on 6.49+ or 7.x and REST on 7.1+; this is a version gate, not evidence that a particular physical router was tested.
4. Enter the management host, administrator-approved source IP/CIDR or VPN address, dedicated username and a strong password. API-SSL and REST require the certificate name and an explicit certificate/SAN readiness confirmation.
5. Correct every validation error. Select **Generate Script**, inspect all conflict guards and service/user changes, and run the preflight commands manually on the intended router.
6. Use **Copy Script** or **Download .rsc** only after review. The output contains plaintext password(s). Protect the clipboard and downloaded file; do not commit, email, or store it on shared storage. Use manual terminal credential entry instead when a plaintext `.rsc` artifact is unacceptable.
7. Import only after independent review: `/import file-name=bradha-matu-....rsc`. Verify the account and selected service. No import is triggered by this application.
8. Remove the downloaded file securely and clear the generated script/passwords in the page.

Local Development must include Binary API, cPanel Production must include REST over HTTPS, and Both requires Both transports. Incompatible combinations are rejected; selecting a label never silently changes the transport. Mock/Demo produces only a comment-only warning file and cannot produce executable configuration commands.

## Transport behavior

| Transport | Service | Typical port | Minimum RouterOS version | Group policy emitted |
|---|---|---:|---|---|
| Binary API | `api` | 8728, plaintext | 6.49+ / 7.x | `read,api` |
| Binary API TLS | `api-ssl` | 8729, TLS | 6.49+ / 7.x | `read,api` |
| REST over HTTPS | `www-ssl` only | 443 | 7.1+ | `read,api,rest-api` |

After an authenticated read succeeds, RouterOS default-route data is observed
per router. Multiple active equal-distance defaults are all retained and
reported as ECMP candidates; an upstream gateway is never substituted for the
saved management host. This is an on-demand live observation, not a scheduled
collector.

The service port must already match the selected port. The script refuses to change a different port. It also stops if a group/user name exists, if a service has a different existing source restriction, or if the HTTPS/API-SSL service uses a different certificate. It sets the service's address restriction to the approved source and enables that selected service only. Broad IPv4 CIDRs wider than `/16`, IPv6 CIDRs wider than `/48`, and unspecified/all-address source values are rejected. It does not configure firewall rules, NAT, other services, certificates, VPNs, or RouterOS system settings.

The application stores credentials only on the backend in encrypted form and never returns saved passwords. Generator passwords are separate transient inputs in page memory and are never sent to the Laravel API or browser storage.

## Least privilege limitation

The generated dedicated group uses `read` plus the protocol policy (`api` or `rest-api`). RouterOS `read` is a coarse built-in policy; RouterOS does not provide an application-specific command allowlist at user-group level. The console's narrower operation allowlist remains enforced on the server, but the RouterOS account may read additional RouterOS configuration. Review the permission matrix before enabling it.

## Connectivity / RB951 timeout

A REST choice does not create a route from cPanel to a private router. The process running Laravel must reach the router through an approved private route or VPN. Check router reachability, service, port/TLS, account permission, source restrictions, firewall, and RouterOS version separately. Do not expose API ports publicly or disable certificate verification.

The stored RB951 row was inspected without reading its credential fields: `192.168.88.1`, Binary API port `8728`, TLS disabled, last error category `connection_timeout`. That is a protocol/port pairing consistent with the usual plain Binary API defaults; it does not prove that the router is reachable or that its API service/account/source restrictions are correct. The timeout is not resolved, and no physical connection test or cPanel network route was verified.

See [MIKROTIK_TERMINAL_SETUP.md](./MIKROTIK_TERMINAL_SETUP.md), [MIKROTIK_SETUP_VERIFICATION.md](./MIKROTIK_SETUP_VERIFICATION.md), [MIKROTIK_SETUP_ROLLBACK.md](./MIKROTIK_SETUP_ROLLBACK.md), and [MIKROTIK_API_PERMISSION_MATRIX.md](./MIKROTIK_API_PERMISSION_MATRIX.md).

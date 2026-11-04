# MikroTik Setup Verification

The application does not inspect or import into a physical router. These are operator-run checks.

## Preflight

Run and record the actual version and current configuration before import:

```routeros
/system resource print
/system identity print
/ip service print where name~"^(api|api-ssl|www-ssl)$"
/user group print
/user print
/certificate print
```

Confirm:

- The reported RouterOS version is the exact version supplied to the generator (Binary 6.49+ or 7.x; REST 7.1+).
- The selected service exists, uses the selected port, and the selected TLS certificate is present.
- The certificate chain is trusted by the application host and its SAN matches the configured management hostname or IP.
- The service and new user can be restricted to the approved source; no public source range is allowed.
- The generated usernames and group names are not already present. The script also checks these before it changes anything.
- cPanel's Laravel host can reach the private router endpoint through an approved VPN/private route. This must be verified separately from script generation.

## Post-import

Run the exact verification commands shown beneath the generated script. Confirm group policy is only the requested `read` and protocol policy, the user has the intended group and source address, and the selected service is enabled with the intended address/certificate. Verify all other services and firewall rules remained unchanged.

Test the read-only application connection from the same host/network where Laravel runs. Verify TLS chain and hostname validation remain enabled. For REST, the RouterOS server must be 7.1+ and REST requests use HTTPS; do not test by disabling certificate checks.

## Application operation mapping

The generated permission policy is based on `RouterReadOnlyGuard` and `MikroTikRouterProvider`; the actual exact allowlist and REST equivalents are in [MIKROTIK_API_PERMISSION_MATRIX.md](./MIKROTIK_API_PERMISSION_MATRIX.md). A successful user/service configuration is not proof that all operations work: verify each operation using mocked tests or an explicitly authorized lab router.

## Evidence boundary

Automated generator tests validate output, escaping, rejected inputs and safeguards. Laravel provider tests use mocked transport responses. Neither is a physical RouterOS syntax test. No RB951 or cPanel-to-router connectivity is claimed as verified by these checks.

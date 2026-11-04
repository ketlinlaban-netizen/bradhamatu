# MikroTik Terminal Setup

## Before generating

- Verify the actual RouterOS version, model, API service ports, API/API-SSL/www-ssl enablement, certificate chain/SAN, and current `/user`, `/user group`, and `/ip service` state.
- Confirm the approved management source IP/CIDR/VPN address for each runtime. Do not use `0.0.0.0/0` or expose RouterOS management services on the public internet.
- Confirm a private route from the Laravel host to the router. For cPanel, obtain hosting-provider confirmation that the account can use the approved private VPN/route.
- Use separate accounts and restrictions for Binary API and REST when selecting both. Enter different usernames, passwords and source ranges in the corresponding fields.
- The generator requires an exact numeric version. Binary supports 6.49+ and 7.x; REST requires 7.1+. RouterOS-specific syntax is not validated against a live device by the app.

## Generate, review, import

In the authenticated console, open `/mikrotik-config` and use **Generate MikroTik Terminal Script**. Select the intended environment and transport explicitly. Local Development does not get inferred from the browser hostname. For Mock/Demo, output is comments only.

Before importing, run the generated preflight commands directly in a RouterOS terminal and record the existing service `disabled`, `port`, `address`, and `certificate` values. The script includes guard checks and stops instead of overwriting conflicting users/groups, a different service port, another source restriction, or another certificate. If a guard stops, inspect and resolve the conflict manually; do not remove an existing object automatically.

Use **Copy Script** or **Download .rsc**, inspect the complete text, then import manually:

```routeros
/import file-name=bradha-matu-<environment>-YYYY-MM-DD.rsc
```

If the selected API-SSL or HTTPS certificate is not installed and verified, stop. The script does not import a certificate or disable certificate verification. It makes no firewall changes.

## Service/port details

- `api` is plain Binary API, normally TCP 8728. The password is not protected in transit; use only in an isolated trusted management network.
- `api-ssl` is Binary API over TLS, normally TCP 8729. It requires an installed certificate suitable for the router management name.
- REST is HTTPS at `/rest/` through the RouterOS `www-ssl` service, normally TCP 443. The dedicated group requires `read,api,rest-api`; HTTP `www` is not enabled or configured by this script.
- A custom port is accepted only if the existing selected RouterOS service already has that port; a differing port is treated as a conflict.

The service `address` property and the user `address` property restrict access to the entered approved source. Existing non-empty different service restrictions cause the script to stop. RouterOS firewall policy remains untouched and must already prevent unwanted access.

## Sensitive content

The generated `.rsc` includes the plaintext user password because RouterOS user creation requires it. The artifact and clipboard are sensitive. Do not upload it, commit it, send it by email, or leave it on shared storage. If that risk is unacceptable, create the group/service after review and add the user manually in the router terminal using the password prompt/secure operator procedure; the application does not provide remote execution.

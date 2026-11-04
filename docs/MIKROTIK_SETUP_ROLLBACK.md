# MikroTik Setup Rollback

Rollback is a manual operator action. Review the pre-import service state and the generated rollback text first. This application never sends rollback commands to a router.

## Remove only generated account objects

After verifying the names correspond to this import and no other service depends on them, remove the dedicated user first and its group second:

```routeros
/user remove [find where name="<generated-username>"]
/user group remove [find where name="<generated-username>-ro"]
```

For a Both-transport script, repeat separately for each distinct generated user/group. Do not use broad `find` expressions or delete resources that predated the import. The generated preflight guards refuse to overwrite existing account/group names.

## Restore service state

Restore the exact pre-import `disabled`, `address`, and (for TLS) `certificate` values recorded from `/ip service print`. The script sets only the selected existing service's `disabled=no`, approved `address`, and required TLS certificate; it leaves ports unchanged after confirming they already match. Do not clear an existing source restriction unless the pre-import record proves it was empty.

No firewall, NAT, route, VPN, certificate-import, or unrelated RouterOS setting is changed by the generated script. If any such item changed outside this script, follow the site's independently reviewed change/rollback procedure.

## Confirm

Run `/ip service print where name="<selected-service>"`, `/user print`, and `/user group print` and compare with the preflight record. Confirm that management remains limited to the approved source and that the application reports no further use of the removed account.

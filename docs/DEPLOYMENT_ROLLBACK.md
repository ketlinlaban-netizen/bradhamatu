# Deployment rollback and backup

No production backup or rollback has been performed. These steps are
operational guidance and require an operator with account and database access.

## Before a release

1. Record the currently serving release directory, PHP version/handler, and
   configuration version without printing secret values.
2. Put a database backup outside the web root in access-controlled storage.
   Use cPanel backup tooling or the provider's database export procedure.
3. Verify the backup file is non-empty and, where practical, restore it into a
   separate staging database to validate recoverability.
4. Back up the production `.env`/secret-manager configuration and `APP_KEY`
   separately, protected by encryption and strict access. Router credential
   ciphertext cannot be decrypted if the corresponding key is lost.
5. Preserve the previous release's private application directory and public
   assets until smoke tests complete.
6. Record migration status and the exact release commit/build identifier.

Never place backups, `.env`, database dumps, `APP_KEY`, or deployment archives
under a publicly served directory.

## Application-only rollback

If database schema is compatible with the previous release:

1. Switch the document root or release pointer back to the previous validated
   release, or restore the previous reviewed public files and front controller.
2. If the release changes the transport, restore the previous known-good
   `ROUTER_PROVIDER` setting (`mikrotik`, `rest`, or demo-only `mock`) and its
   matching server-side port/trust configuration. Do not switch to Binary API
   unless API/API-SSL is privately reachable and its TLS requirements are met.
3. Restore that release's matching built assets and clear stale Laravel
   config/route/view caches using the previous release's Artisan binary.
4. Verify HTTPS, login, authenticated API, health response, assets, and logs.
5. Keep the failed release and logs private for investigation.

Do not discard encrypted router credentials or alter router configuration
during application rollback.

## Database rollback

Do not assume `php artisan migrate:rollback` is safe. In particular, the
credential-encryption migration is intentionally irreversible; it cannot
safely turn ciphertext back into plaintext.

If a schema migration must be reverted:

1. Stop writes/put the application in maintenance mode if supported.
2. Confirm the exact migration and compatibility with the previous release.
3. Restore the verified database backup to a separate database first, if
   feasible.
4. Switch database configuration only after validating the restored copy and
   preserving the failed database for investigation.
5. Restore matching application release and verify functionality.

Never run destructive schema resets on production. A database restore can
discard valid data written after the backup, so obtain explicit approval.

## Credential/key loss or rotation

Preserve the exact old `APP_KEY` until every encrypted router credential is
re-encrypted through a reviewed, tested rotation procedure. Do not change the
key as an ordinary deployment setting. If it is lost, restore a protected,
matching database/key backup; do not expose encrypted values or attempt
plaintext recovery.

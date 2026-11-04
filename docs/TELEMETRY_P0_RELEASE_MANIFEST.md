# Telemetry P0 release-isolation manifest

**Status:** Preparation only — not a deployable package and not authorization to
deploy. The production RouterOS script and scheduler remain unchanged.

## Why a package is not being built from this checkout

The current `main` worktree contains broad unrelated modifications, including
RouterOS configuration/transport work, UI changes, deployment tooling, build
output, and telemetry work mixed into shared files. `HEAD` also lacks the
telemetry controller, service, client inventory, supporting model/migration,
and regression tests. Copying the current files wholesale into a clean
worktree would either omit required functionality or import unrelated changes.
The existing `dist/bradha-telemetry-inventory-review-20261022.zip` is a review
archive, not an application release.

Therefore this manifest describes the candidate boundary, not an assertion
that every listed file is a verified production delta. Final inclusion must be
decided by comparing each path with the current cPanel source and migration
state, then porting only the reviewed P0 hunks onto that exact baseline.

## Candidate source manifest

| Path | Release treatment |
|---|---|
| `bradha-matu-api/app/Http/Controllers/RouterTelemetryController.php` | Include only the reviewed telemetry validation/ingestion changes. |
| `bradha-matu-api/app/Services/RouterTelemetryService.php` | Include only the reviewed source completeness, normalization, last-good-value, route/IP, timestamp, and status changes. |
| `bradha-matu-api/app/Services/ClientInventory.php` | Include only the reviewed MAC reconciliation and safe status-transition changes. |
| `bradha-matu-api/app/Support/MacAddress.php` | Include only if the isolated service/inventory code requires it and the production baseline lacks an equivalent helper. |
| `bradha-matu-api/app/Models/NetworkClient.php` | Include only if the required casts/relationships are absent from the production baseline. |
| `bradha-matu-api/app/Models/Router.php` | Port only telemetry-specific model changes; do not replace the file wholesale. |
| `bradha-matu-api/routes/api.php` | Port only the authenticated telemetry route if absent or incorrect in production. |
| `bradha-matu-api/app/Providers/RouterServiceProvider.php` and `bradha-matu-api/app/Providers/AppServiceProvider.php` | Port only bindings required by the telemetry path; exclude unrelated router transport changes. |
| `bradha-matu-api/app/Services/MikroTikRouterProvider.php` | Do not include wholesale. Port a telemetry-required hunk only after proving it is on the active path and reviewing its dependencies. |
| `bradha-matu-api/database/migrations/2026_10_12_000004_add_router_telemetry_ingestion.php` | Include in source only if it is part of the production migration history. Check production migration status before any migration action; do not re-run an applied migration. |
| `src/services/api.ts`, `src/services/LaravelApiProvider.ts`, `src/services/RouterService.ts` | Port only the API response mapping needed for the telemetry detail view; exclude unrelated endpoint/provider changes. |
| `src/types.ts` | Port only the telemetry response/source metadata contract; do not replace the complete file. |
| `src/pages/RouterDetailPage.tsx` | Port only telemetry inventory, freshness, route, IP, MAC, and timestamp rendering changes; preserve the existing page and theme. |
| `src/services/mikrotikTelemetryScript.ts` | Include the reviewed generator source only if it is the approved script-generation source for production. It does not update the installed router script. |
| `src/pages/MikroTikConfigPage.tsx`, `src/components/MikroTikTerminalScriptGenerator.tsx` | Include only if required for the existing credential/script workflow and after isolating the telemetry-only hunks. Do not replace the existing page or RouterProvider. |

## Non-production review inputs

Keep these out of the production upload payload:

- `bradha-matu-api/tests/Feature/RouterTelemetryTest.php`
- `bradha-matu-api/tests/Feature/ClientInventoryTest.php`
- `tests/mikrotikTerminalScript.test.mjs`
- `docs/TELEMETRY_INVENTORY_RELEASE_REVIEW.md`
- this manifest

Run the tests against the isolated release source before generating any
production package. The deployable frontend assets, if required, must be built
from that isolated source; never copy the existing mixed-worktree `dist/`.

## Exclusions

Unless separately reviewed and approved, exclude all unrelated changes to
router configuration/transport, authentication/session configuration, client
alias and PPPoE features, other dashboard pages, deployment framework,
environment examples, dependency manifests, generated build state, archives,
logs, secrets, and router configuration. Never upload `.env`, credentials,
database exports, or the RouterOS script/token as part of a Laravel package.

## Isolation and verification gates

1. Obtain the exact current production source baseline and record its revision
   or per-file hashes. Obtain the production migration status through the
   authorized cPanel process.
2. Create a clean release branch/worktree from that baseline. Port the
   candidate hunks one file at a time, preserving existing production behavior.
3. Resolve the conditional entries above against production; publish the final
   exact file manifest and old/new hashes for review. If any shared file cannot
   be cleanly separated from unrelated changes, stop and narrow the patch.
4. Run backend tests, telemetry generator tests, formatting, type checking,
   and frontend production build in the isolated worktree. Review the entire
   isolated diff and generated package contents.
5. Prepare private file backups and the database backup required by the
   approved release scope, then validate rollback files and hashes before any
   production rename. Follow the SFTP-only procedure in
   `TELEMETRY_INVENTORY_RELEASE_REVIEW.md`.
6. Deployment remains a separate approval step. Do not deploy from this
   preparation task, change the installed RouterOS script/scheduler, or claim
   live acceptance until one fresh report is correlated through receipt,
   database, API, and browser.

## Approval record

- Release baseline and final exact manifest: **pending**
- Isolated candidate build/test and diff review: **pending**
- Backup and rollback readiness: **pending**
- Production deployment approval: **not requested here**
- Router one-shot verification approval: **not requested here**

#!/usr/bin/env bash
#
# Builds the isolated cPanel release for the communitygroup console.
# Override the host with DOMAIN=other.host when the cPanel docroot differs.
#
# The package is independent from the pandatechs deployment:
#   private app : ~/bradha-matu-api-communitygroup
#   docroot     : ~/public_html/$DOMAIN
# It must be extracted on the same cPanel account but uses its own app
# directory, .env, APP_KEY, database, and one-use migration runner.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_SOURCE="$PROJECT_ROOT/bradha-matu-api"
SHARED_DEPLOY="$PROJECT_ROOT/deploy/cpanel"
OUTPUT_DIR="$PROJECT_ROOT/dist"
DOMAIN="${DOMAIN:-onlineconsole.communitygroup.co.ke}"
APP_DIR_NAME="bradha-matu-api-communitygroup"
PACKAGE_NAME="bradha-matu-communitygroup-$(date -u +%Y%m%dT%H%M%SZ).zip"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/bradha-matu-communitygroup.XXXXXX")"

cleanup() {
    rm -rf -- "$STAGE"
}
trap cleanup EXIT

if [[ ! -f "$BACKEND_SOURCE/composer.lock" || ! -f "$PROJECT_ROOT/package-lock.json" ]]; then
    printf '%s\n' "Project manifests are missing; refusing to build a deployment package." >&2
    exit 1
fi
if ! command -v zip >/dev/null 2>&1 || ! command -v composer >/dev/null 2>&1; then
    printf '%s\n' "Both zip and Composer are required to build a complete deployment package." >&2
    exit 1
fi

cd "$PROJECT_ROOT"
npm run build
node --test tests/mikrotikTerminalScript.test.mjs

PACKAGE_BACKEND="$STAGE/$APP_DIR_NAME"
PACKAGE_PUBLIC="$STAGE/public_html/$DOMAIN"
mkdir -p "$PACKAGE_BACKEND" "$PACKAGE_PUBLIC"

tar -C "$BACKEND_SOURCE" \
    --exclude='.env' \
    --exclude='.env.*' \
    --exclude='.git' \
    --exclude='tests' \
    --exclude='storage' \
    --exclude='bootstrap/cache/*.php' \
    --exclude='database/*.sqlite' \
    --exclude='database/*.sqlite-*' \
    --exclude='*.log' \
    -cf - . | tar -C "$PACKAGE_BACKEND" -xf -

cp "$SCRIPT_DIR/env.communitygroup.example" "$PACKAGE_BACKEND/.env.communitygroup.example"
cp "$SHARED_DEPLOY/private-app.htaccess" "$PACKAGE_BACKEND/.htaccess"
mkdir -p \
    "$PACKAGE_BACKEND/storage/app" \
    "$PACKAGE_BACKEND/storage/framework/cache/data" \
    "$PACKAGE_BACKEND/storage/framework/sessions" \
    "$PACKAGE_BACKEND/storage/framework/views" \
    "$PACKAGE_BACKEND/storage/logs" \
    "$PACKAGE_BACKEND/bootstrap/cache"

rm -rf -- "$PACKAGE_BACKEND/public"
mkdir -p "$PACKAGE_BACKEND/public"
cp -a "$PROJECT_ROOT/dist/." "$PACKAGE_BACKEND/public/"
cp -a "$PROJECT_ROOT/dist/." "$PACKAGE_PUBLIC/"
cp "$SCRIPT_DIR/public-index.php" "$PACKAGE_PUBLIC/index.php"
cp "$SHARED_DEPLOY/public.htaccess" "$PACKAGE_PUBLIC/.htaccess"
cp "$SCRIPT_DIR/web-migrate.php" "$PACKAGE_PUBLIC/web-migrate.php"
cp "$SHARED_DEPLOY/web-migrate.php" "$PACKAGE_PUBLIC/deployment-runner.php"
cp "$SCRIPT_DIR/DEPLOYMENT.md" "$STAGE/DEPLOYMENT.md"

composer install \
    --working-dir="$PACKAGE_BACKEND" \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction

mkdir -p "$OUTPUT_DIR"
(
    cd "$STAGE"
    zip -qr "$OUTPUT_DIR/$PACKAGE_NAME" \
        "$APP_DIR_NAME" \
        public_html \
        DEPLOYMENT.md
)

printf '\nDeployment package created: %s\n' "$OUTPUT_DIR/$PACKAGE_NAME"
printf 'Package contents:\n'
unzip -l "$OUTPUT_DIR/$PACKAGE_NAME" | tail -n 8

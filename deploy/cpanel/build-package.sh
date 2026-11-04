#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_SOURCE="$PROJECT_ROOT/bradha-matu-api"
OUTPUT_DIR="$PROJECT_ROOT/dist"
PACKAGE_NAME="bradha-matu-cpanel-$(date -u +%Y%m%dT%H%M%SZ).zip"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/bradha-matu-cpanel.XXXXXX")"

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

PACKAGE_BACKEND="$STAGE/bradha-matu-api"
PACKAGE_PUBLIC="$STAGE/public_html/onlineconsole.pandatechs.co.ke"
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

cp "$BACKEND_SOURCE/.env.cpanel.example" "$PACKAGE_BACKEND/.env.cpanel.example"
cp "$SCRIPT_DIR/private-app.htaccess" "$PACKAGE_BACKEND/.htaccess"
mkdir -p \
    "$PACKAGE_BACKEND/storage/app" \
    "$PACKAGE_BACKEND/storage/framework/cache/data" \
    "$PACKAGE_BACKEND/storage/framework/sessions" \
    "$PACKAGE_BACKEND/storage/framework/views" \
    "$PACKAGE_BACKEND/storage/logs" \
    "$PACKAGE_BACKEND/bootstrap/cache"

cp -a "$PROJECT_ROOT/dist/." "$PACKAGE_BACKEND/public/"
cp -a "$PROJECT_ROOT/dist/." "$PACKAGE_PUBLIC/"
cp "$SCRIPT_DIR/public-index.php" "$PACKAGE_PUBLIC/index.php"
cp "$SCRIPT_DIR/public.htaccess" "$PACKAGE_PUBLIC/.htaccess"
cp "$SCRIPT_DIR/web-migrate.php" "$PACKAGE_PUBLIC/web-migrate.php"
cp "$SCRIPT_DIR/account-root.htaccess" "$STAGE/.htaccess"
cp "$PROJECT_ROOT/docs/CPANEL_BROWSER_DEPLOYMENT.md" "$STAGE/DEPLOYMENT.md"

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
        bradha-matu-api \
        public_html \
        .htaccess \
        DEPLOYMENT.md
)

printf '\nDeployment package created: %s\n' "$OUTPUT_DIR/$PACKAGE_NAME"
printf 'Package contents:\n'
unzip -l "$OUTPUT_DIR/$PACKAGE_NAME" | tail -n 8

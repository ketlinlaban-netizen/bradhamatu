#!/usr/bin/env bash
#
# Bradha Matu Console — Service Status Check
#
set -euo pipefail

check() {
  local name="$1"
  local url="$2"
  if systemctl is-active --quiet "$name" 2>/dev/null; then
    printf "  [RUNNING]  %s\n" "$name"
  else
    printf "  [STOPPED]  %s\n" "$name"
  fi
}

check_url() {
  local label="$1"
  local url="$2"
  local code
  code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$url" 2>/dev/null || echo "000")
  if [ "$code" = "200" ] || [ "$code" = "302" ]; then
    printf "  [OK]       %s → HTTP %s\n" "$label" "$code"
  elif [ "$code" = "000" ]; then
    printf "  [FAIL]     %s → unreachable\n" "$label"
  else
    printf "  [WARN]     %s → HTTP %s\n" "$label" "$code"
  fi
}

echo ""
echo "═══════════════════════════════════════════════"
echo "  Bradha Matu Console — Service Status"
echo "═══════════════════════════════════════════════"
echo ""

echo "Services:"
check mariadb ""
check nginx ""

# Detect PHP-FPM version
for ver in 8.3 8.2 8.1; do
  if systemctl list-unit-files "php${ver}-fpm.service" >/dev/null 2>&1; then
    check "php${ver}-fpm" ""
    break
  fi
done

check cloudflared ""

echo ""
echo "Endpoints:"
check_url "Local app"       "http://127.0.0.1:8080"
check_url "Health check"    "http://127.0.0.1:8080/api/health"

echo ""
echo "Tunnel:"
if command -v cloudflared >/dev/null 2>&1; then
  echo "  cloudflared version: $(cloudflared --version 2>&1)"
fi

echo ""

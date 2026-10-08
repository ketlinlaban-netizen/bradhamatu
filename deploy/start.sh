#!/usr/bin/env bash
#
# Bradha Matu Console — Start Script
#
# Starts: MariaDB, php-fpm, nginx, and cloudflared tunnel.
# Safe to rerun — only starts services that are not running.
#
set -euo pipefail

echo "Starting Bradha Matu Console services..."

# MariaDB
if ! systemctl is-active --quiet mariadb 2>/dev/null; then
  systemctl start mariadb
  echo "  [OK] MariaDB started"
else
  echo "  [skip] MariaDB already running"
fi

# PHP-FPM (detect version)
PHP_FPM=""
for ver in 8.3 8.2 8.1; do
  if systemctl list-unit-files "php${ver}-fpm.service" >/dev/null 2>&1; then
    PHP_FPM="php${ver}-fpm"
    break
  fi
done

if [ -n "$PHP_FPM" ]; then
  if ! systemctl is-active --quiet "$PHP_FPM" 2>/dev/null; then
    systemctl start "$PHP_FPM"
    echo "  [OK] $PHP_FPM started"
  else
    echo "  [skip] $PHP_FPM already running"
  fi
else
  echo "  [WARN] php-fpm not found — install PHP and php-fpm"
fi

# nginx
if ! systemctl is-active --quiet nginx 2>/dev/null; then
  systemctl start nginx
  echo "  [OK] nginx started"
else
  echo "  [skip] nginx already running"
fi

# cloudflared (only if configured)
if systemctl is-enabled --quiet cloudflared 2>/dev/null; then
  if ! systemctl is-active --quiet cloudflared 2>/dev/null; then
    systemctl start cloudflared
    echo "  [OK] cloudflared started"
  else
    echo "  [skip] cloudflared already running"
  fi
else
  echo "  [skip] cloudflared not configured — run setup first"
fi

echo ""
echo "Services started. Check status with: deploy/status.sh"
echo "Local app: http://127.0.0.1:8080"

#!/usr/bin/env bash
#
# Bradha Matu Console — Stop Script
#
# Stops: cloudflared, nginx, php-fpm. Leaves MariaDB running
# (other applications may depend on it).
#
set -euo pipefail

echo "Stopping Bradha Matu Console services..."

# cloudflared
if systemctl is-active --quiet cloudflared 2>/dev/null; then
  systemctl stop cloudflared
  echo "  [OK] cloudflared stopped"
else
  echo "  [skip] cloudflared not running"
fi

# nginx
if systemctl is-active --quiet nginx 2>/dev/null; then
  systemctl stop nginx
  echo "  [OK] nginx stopped"
else
  echo "  [skip] nginx not running"
fi

# PHP-FPM
PHP_FPM=""
for ver in 8.3 8.2 8.1; do
  if systemctl is-active --quiet "php${ver}-fpm" 2>/dev/null; then
    PHP_FPM="php${ver}-fpm"
    break
  fi
done
if [ -n "$PHP_FPM" ]; then
  systemctl stop "$PHP_FPM"
  echo "  [OK] $PHP_FPM stopped"
else
  echo "  [skip] php-fpm not running"
fi

echo ""
echo "Services stopped. MariaDB left running."

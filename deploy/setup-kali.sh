#!/usr/bin/env bash
#
# Bradha Matu Console — Kali Linux Setup Script
#
# Installs and configures: PHP 8.3, Composer, MariaDB, nginx,
# php-fpm, cloudflared, and the Laravel application.
#
# SAFE TO RERUN: backs up existing configs before overwriting.
# Never deletes databases, .env files, or application code.
#
# Usage:
#   chmod +x deploy/setup-kali.sh
#   sudo ./deploy/setup-kali.sh
#
# Prerequisites:
#   - Kali Linux (Debian-based)
#   - Internet connection
#   - A Cloudflare account with a registered domain (for tunnel setup)
#
set -euo pipefail

# ── Colors ─────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

info()  { echo -e "${BLUE}[INFO]${NC}  $1"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $1"; }
fail()  { echo -e "${RED}[FAIL]${NC}  $1"; exit 1; }

# ── Paths ──────────────────────────────────────────────────────
LARAVEL_ROOT="/opt/bradha-matu-api"
APP_USER="www-data"
PHP_VERSION="8.3"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "  Community WiFi Bradha Matu Console — Kali Linux Setup"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# ── 1. Check root ──────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
  fail "Run this script as root: sudo ./deploy/setup-kali.sh"
fi

# ── 2. Detect existing installations ───────────────────────────
info "Checking existing installations..."

PHP_INSTALLED=$(command -v php 2>/dev/null && echo "yes" || echo "no")
COMPOSER_INSTALLED=$(command -v composer 2>/dev/null && echo "yes" || echo "no")
MYSQL_INSTALLED=$(command -v mariadb 2>/dev/null || command -v mysql 2>/dev/null || echo "")
NGINX_INSTALLED=$(command -v nginx 2>/dev/null && echo "yes" || echo "no")
CLOUDFLARED_INSTALLED=$(command -v cloudflared 2>/dev/null && echo "yes" || echo "no")

# ── 3. Install system packages ─────────────────────────────────
info "Installing system packages..."
apt-get update -qq

if [ "$PHP_INSTALLED" = "no" ]; then
  info "Installing PHP ${PHP_VERSION} and extensions..."
  apt-get install -y -qq \
    php${PHP_VERSION} php${PHP_VERSION}-fpm php${PHP_VERSION}-cli \
    php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-curl \
    php${PHP_VERSION}-mysql php${PHP_VERSION}-sqlite3 php${PHP_VERSION}-gd \
    php${PHP_VERSION}-bcmath php${PHP_VERSION}-zip php${PHP_VERSION}-intl \
    php${PHP_VERSION}-readline > /dev/null
  ok "PHP ${PHP_VERSION} installed"
else
  ok "PHP already installed: $(php -v | head -1)"
fi

if [ "$MYSQL_INSTALLED" = "" ]; then
  info "Installing MariaDB..."
  apt-get install -y -qq mariadb-server mariadb-client > /dev/null
  systemctl enable mariadb
  systemctl start mariadb
  ok "MariaDB installed and started"
else
  ok "MariaDB already installed: ${MYSQL_INSTALLED}"
fi

if [ "$NGINX_INSTALLED" = "no" ]; then
  info "Installing nginx..."
  apt-get install -y -qq nginx > /dev/null
  systemctl enable nginx
  systemctl start nginx
  ok "nginx installed and started"
else
  ok "nginx already installed"
fi

if [ "$COMPOSER_INSTALLED" = "no" ]; then
  info "Installing Composer..."
  apt-get install -y -qq composer > /dev/null 2>&1 || {
    EXPECTED_SIG=$(wget -qO- https://composer.github.io/installer.sig)
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_SIG=$(php -r "echo hash_file('sha384', 'composer-setup.php');")
    [ "$EXPECTED_SIG" = "$ACTUAL_SIG" ] || fail "Composer signature mismatch"
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm composer-setup.php
  }
  ok "Composer installed"
else
  ok "Composer already installed"
fi

if [ "$CLOUDFLARED_INSTALLED" = "no" ]; then
  info "Installing cloudflared..."
  ARCH=$(dpkg --print-architecture)
  case "$ARCH" in
    amd64)  CF_ARCH="amd64" ;;
    arm64)  CF_ARCH="arm64" ;;
    *)      warn "Architecture $ARCH may not be supported by cloudflared"; CF_ARCH="amd64" ;;
  esac
  curl -fsSL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${CF_ARCH}.deb" -o /tmp/cloudflared.deb
  dpkg -i /tmp/cloudflared.deb
  rm /tmp/cloudflared.deb
  ok "cloudflared installed"
else
  ok "cloudflared already installed: $(cloudflared --version 2>&1)"
fi

# ── 4. Create cloudflared user ─────────────────────────────────
if ! id -u cloudflared >/dev/null 2>&1; then
  info "Creating cloudflared system user..."
  useradd -r -s /usr/sbin/nologin -d /var/lib/cloudflared cloudflared
  mkdir -p /var/lib/cloudflared /etc/cloudflared /var/log/cloudflared
  chown cloudflared:cloudflared /var/lib/cloudflared /var/log/cloudflared
  ok "cloudflared user created"
else
  ok "cloudflared user exists"
fi

# ── 5. Create MariaDB database (interactive) ───────────────────
echo ""
warn "━━━ MariaDB Database Setup ━━━"
warn "A database and user will be created for the application."
warn "You will be prompted for the MariaDB root password."
echo ""

read -rp "Create database 'bradha_matu'? [Y/n]: " CREATE_DB
CREATE_DB=${CREATE_DB:-Y}

if [[ "$CREATE_DB" =~ ^[Yy]$ ]]; then
  read -rsp "MariaDB root password: " MYSQL_ROOT_PW
  echo ""

  DB_NAME="bradha_matu"
  DB_USER="bradha_user"
  read -rp "Database user password (leave blank to generate): " DB_PW
  if [ -z "$DB_PW" ]; then
    DB_PW=$(openssl rand -base64 24)
    echo "Generated password: $DB_PW"
  fi

  mariadb -u root -p"${MYSQL_ROOT_PW}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PW}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
  ok "Database '${DB_NAME}' and user '${DB_USER}' ready"

  # Save credentials for the user to copy into .env
  cat > /tmp/bradha-matu-db-creds.txt <<EOF
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PW}
EOF
  chmod 600 /tmp/bradha-matu-db-creds.txt
  info "Database credentials saved to /tmp/bradha-matu-db-creds.txt"
  warn "Copy these into your Laravel .env file. Delete this file after."
else
  info "Skipping database creation"
fi

# ── 6. Install nginx vhost ─────────────────────────────────────
echo ""
info "Installing nginx virtual host..."

NGINX_CONF_SRC="${SCRIPT_DIR}/nginx/bradha-matu.conf"
NGINX_CONF_DST="/etc/nginx/sites-available/bradha-matu.conf"

if [ -f "$NGINX_CONF_DST" ]; then
  cp "$NGINX_CONF_DST" "${NGINX_CONF_DST}.bak.$(date +%Y%m%d%H%M%S)"
  info "Backed up existing nginx config"
fi

mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled
cp "$NGINX_CONF_SRC" "$NGINX_CONF_DST"

# Update the root path if Laravel is installed
if [ -d "${LARAVEL_ROOT}/public" ]; then
  sed -i "s|root /opt/bradha-matu-api/public;|root ${LARAVEL_ROOT}/public;|" "$NGINX_CONF_DST"
  ok "nginx root set to ${LARAVEL_ROOT}/public"
else
  warn "Laravel not found at ${LARAVEL_ROOT}. Edit the nginx config root path after installing."
fi

# Enable the site
ln -sf "$NGINX_CONF_DST" /etc/nginx/sites-enabled/bradha-matu.conf
ok "nginx site enabled"

# Test and reload
if nginx -t 2>/dev/null; then
  systemctl reload nginx
  ok "nginx reloaded"
else
  warn "nginx config test failed — check syntax after editing the domain"
fi

# ── 7. Install cloudflared systemd service ─────────────────────
info "Installing cloudflared systemd service..."

CF_SERVICE_SRC="${SCRIPT_DIR}/systemd/cloudflared.service"
CF_SERVICE_DST="/etc/systemd/system/cloudflared.service"

if [ -f "$CF_SERVICE_DST" ]; then
  cp "$CF_SERVICE_DST" "${CF_SERVICE_DST}.bak.$(date +%Y%m%d%H%M%S)"
fi
cp "$CF_SERVICE_SRC" "$CF_SERVICE_DST"
systemctl daemon-reload
ok "cloudflared service installed (not started yet — needs tunnel config)"

# ── 8. Summary ─────────────────────────────────────────────────
echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "  Setup Complete — Next Steps"
echo "═══════════════════════════════════════════════════════════════"
echo ""
echo "  1. Install Laravel:"
echo "     composer create-project laravel/laravel ${LARAVEL_ROOT}"
echo "     cd ${LARAVEL_ROOT}"
echo "     # Copy files from laravel-contracts/ (see README.md)"
echo ""
echo "  2. Configure Laravel .env:"
echo "     - Copy DB credentials from /tmp/bradha-matu-db-creds.txt"
echo "     - Set ROUTER_PROVIDER=mock (demo) or mikrotik (live)"
echo "     - Run: php artisan migrate"
echo ""
echo "  3. Build frontend and copy to Laravel public/:"
echo "     cd ${PROJECT_ROOT}"
echo "     npm install && npm run build"
echo "     cp -r dist/* ${LARAVEL_ROOT}/public/"
echo ""
echo "  4. Configure Cloudflare Tunnel:"
echo "     cloudflared tunnel login"
echo "     cloudflared tunnel create bradha-matu"
echo "     # Copy the tunnel ID into /etc/cloudflared/config.yml"
echo "     # Replace YOUR_DOMAIN in nginx and cloudflared configs"
echo "     sudo systemctl enable cloudflared"
echo "     sudo systemctl start cloudflared"
echo ""
echo "  5. Create admin user:"
echo "     cd ${LARAVEL_ROOT} && php artisan tinker"
echo "     >>> \$u = new App\\Models\\User();"
echo "     >>> \$u->name = 'Admin'; \$u->email = 'admin@...';"
echo "     >>> \$u->password = bcrypt('...'); \$u->role = 'super_admin';"
echo "     >>> \$u->save();"
echo ""
echo "  See: deploy/DEPLOYMENT_GUIDE.md for full instructions"
echo ""

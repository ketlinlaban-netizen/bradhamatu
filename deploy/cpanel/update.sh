#!/bin/bash
###############################################################################
# Bratha Matu Console - Quick Update Script
#
# Quick deployment script for updates. Use this after pulling changes from git.
#
# Usage:
#   bash update.sh
###############################################################################

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }

HOME_DIR="$HOME"
BACKEND_DIR="$HOME_DIR/bradha-matu-api"

echo "========================================"
echo "Bratha Matu Console - Quick Update"
echo "========================================"
echo ""

# Backend updates
log_info "Updating backend..."
cd "$BACKEND_DIR"

log_info "Installing/updating Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

log_info "Running migrations..."
php artisan migrate --force

log_info "Clearing and caching config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

log_info "Clearing application cache..."
php artisan cache:clear

log_success "Backend updated!"

# Frontend updates
log_info "Frontend update..."
log_warning "If you have new frontend changes:"
echo "  1. Build frontend locally: npm run build"
echo "  2. Upload dist/ contents to public_html/"
echo "  3. Or run: php deploy/cpanel/deploy.php deploy"

echo ""
log_success "Update completed!"
echo ""
echo "Don't forget to:"
echo "  - Test the application"
echo "  - Check logs if issues occur"
echo "  - Run 'php deploy/cpanel/migrate.php status' to verify migrations"

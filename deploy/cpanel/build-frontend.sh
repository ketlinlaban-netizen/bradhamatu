#!/bin/bash
###############################################################################
# Bratha Matu Console - Frontend Build Script
#
# Build the React frontend for production deployment.
# Run this locally before uploading to cPanel.
#
# Usage:
#   bash build-frontend.sh
###############################################################################

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

echo "========================================="
echo "Bratha Matu - Frontend Build"
echo "========================================="
echo ""

log_info "Project root: $PROJECT_ROOT"

# Check if we're in the right directory
if [ ! -f "$PROJECT_ROOT/package.json" ]; then
    log_error "package.json not found in $PROJECT_ROOT"
    exit 1
fi

cd "$PROJECT_ROOT"

# Check Node.js
if ! command -v node &> /dev/null; then
    log_error "Node.js is not installed"
    exit 1
fi

log_info "Node.js version: $(node --version)"
log_info "npm version: $(npm --version)"

# Install dependencies
log_info "Installing dependencies..."
npm install

# Run type check
log_info "Running type check..."
npm run typecheck || log_warning "Type check had warnings"

# Build for production
log_info "Building for production..."
npm run build

# Verify build
if [ ! -d "$PROJECT_ROOT/dist" ]; then
    log_error "Build failed - dist directory not created"
    exit 1
fi

log_success "Build completed successfully!"

# Show build output
echo ""
log_info "Build output:"
du -sh "$PROJECT_ROOT/dist"
ls -lh "$PROJECT_ROOT/dist"

# Create deployment package
log_info "Creating deployment package..."
DEPLOY_PKG="bradha-matu-frontend-$(date +%Y%m%d_%H%M%S).tar.gz"
cd "$PROJECT_ROOT"
tar -czf "$DEPLOY_PKG" -C dist .

log_success "Deployment package created: $DEPLOY_PKG"

echo ""
echo "========================================="
echo "Next Steps:"
echo "========================================="
echo "1. Upload $DEPLOY_PKG to your cPanel server"
echo ""
echo "2. On cPanel, extract to public_html:"
echo "   cd ~/public_html"
echo "   tar -xzf ~/$DEPLOY_PKG"
echo ""
echo "3. Or use the deploy script:"
echo "   php ~/deploy/cpanel/deploy.php deploy"
echo ""
echo "========================================="

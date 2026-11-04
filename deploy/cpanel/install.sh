#!/bin/bash
###############################################################################
# Bratha Matu Console - cPanel Installation Script
#
# This script automates the initial installation on cPanel shared hosting.
# It should be run once during initial setup.
#
# Usage:
#   bash install.sh
#
# Requirements:
#   - SSH access to cPanel
#   - Git installed
#   - PHP 8.3+ with Composer
#   - Node.js 18+ (for building frontend)
###############################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
REPO_URL="https://github.com/yourusername/bradha-matu-console.git"
HOME_DIR="$HOME"
BACKEND_DIR="$HOME_DIR/bradha-matu-api"
FRONTEND_DIR="$HOME_DIR/bradha-matu-frontend"
PUBLIC_HTML="$HOME_DIR/public_html"

# Functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

check_requirements() {
    log_info "Checking requirements..."
    
    # Check PHP
    if ! command -v php &> /dev/null; then
        log_error "PHP is not installed or not in PATH"
        exit 1
    fi
    
    PHP_VERSION=$(php -r 'echo PHP_VERSION;')
    log_success "PHP version: $PHP_VERSION"
    
    # Check Composer
    if ! command -v composer &> /dev/null; then
        log_warning "Composer not found. Installing..."
        install_composer
    else
        log_success "Composer found: $(composer --version)"
    fi
    
    # Check Node.js (optional, for building frontend)
    if command -v node &> /dev/null; then
        log_success "Node.js found: $(node --version)"
    else
        log_warning "Node.js not found. Frontend must be built elsewhere."
    fi
}

install_composer() {
    log_info "Installing Composer..."
    cd "$HOME_DIR"
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar "$HOME_DIR/bin/composer" || mkdir -p "$HOME_DIR/bin" && mv composer.phar "$HOME_DIR/bin/composer"
    chmod +x "$HOME_DIR/bin/composer"
    export PATH="$HOME_DIR/bin:$PATH"
    log_success "Composer installed"
}

setup_directories() {
    log_info "Setting up directory structure..."
    
    # Create necessary directories
    mkdir -p "$HOME_DIR/backups"
    mkdir -p "$HOME_DIR/logs"
    mkdir -p "$HOME_DIR/bin"
    
    log_success "Directories created"
}

clone_repository() {
    log_info "Checking for existing installation..."
    
    if [ -d "$BACKEND_DIR" ]; then
        log_warning "Backend directory already exists. Skipping clone."
    else
        log_info "Cloning repository..."
        # Placeholder - adjust based on your repo structure
        log_warning "Manual step required: Upload your backend files to $BACKEND_DIR"
    fi
}

setup_backend() {
    log_info "Setting up Laravel backend..."
    
    if [ ! -d "$BACKEND_DIR" ]; then
        log_error "Backend directory not found: $BACKEND_DIR"
        exit 1
    fi
    
    cd "$BACKEND_DIR"
    
    # Install Composer dependencies
    log_info "Installing Composer dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction
    
    # Copy environment file
    if [ ! -f ".env" ]; then
        log_info "Creating .env file..."
        if [ -f ".env.cpanel.example" ]; then
            cp .env.cpanel.example .env
            log_success ".env created from example"
        else
            log_error ".env.cpanel.example not found"
            exit 1
        fi
    else
        log_warning ".env already exists"
    fi
    
    # Generate application key
    log_info "Generating application key..."
    php artisan key:generate --force
    
    # Create storage directories
    log_info "Setting up storage directories..."
    mkdir -p storage/framework/{sessions,views,cache}
    mkdir -p storage/logs
    mkdir -p bootstrap/cache
    
    # Set permissions
    chmod -R 755 storage bootstrap/cache
    
    log_success "Backend setup completed"
}

setup_frontend() {
    log_info "Setting up frontend..."
    
    # Check if dist directory exists
    if [ -d "$FRONTEND_DIR/dist" ]; then
        log_info "Deploying frontend to public_html..."
        
        # Backup existing public_html if needed
        if [ "$(ls -A $PUBLIC_HTML)" ]; then
            log_warning "public_html is not empty. Creating backup..."
            BACKUP_DIR="$HOME_DIR/backups/public_html_$(date +%Y%m%d_%H%M%S)"
            mkdir -p "$BACKUP_DIR"
            cp -r "$PUBLIC_HTML"/* "$BACKUP_DIR/"
            log_success "Backup created: $BACKUP_DIR"
        fi
        
        # Copy frontend files
        cp -r "$FRONTEND_DIR/dist"/* "$PUBLIC_HTML/"
        cp deploy/cpanel/.htaccess "$PUBLIC_HTML/.htaccess"
        
        log_success "Frontend deployed"
    else
        log_warning "Frontend dist not found. Build frontend first with: npm run build"
    fi
}

create_symlinks() {
    log_info "Creating symlinks..."
    
    # Create API symlink
    API_LINK="$PUBLIC_HTML/api"
    LARAVEL_PUBLIC="$BACKEND_DIR/public"
    
    if [ -L "$API_LINK" ]; then
        log_warning "API symlink already exists"
    else
        ln -s "$LARAVEL_PUBLIC" "$API_LINK"
        log_success "API symlink created: $API_LINK -> $LARAVEL_PUBLIC"
    fi
}

setup_database() {
    log_info "Database setup..."
    log_warning "Please complete the following steps manually in cPanel:"
    echo "  1. Create a MySQL/MariaDB database"
    echo "  2. Create a database user with all privileges on that database"
    echo "  3. Update .env file with database credentials:"
    echo "     - DB_DATABASE=your_database_name"
    echo "     - DB_USERNAME=your_database_user"
    echo "     - DB_PASSWORD=your_database_password"
    echo ""
    echo "  4. Run migrations: php $HOME_DIR/deploy/cpanel/migrate.php"
    echo ""
}

finalize() {
    log_success "Installation completed!"
    echo ""
    echo "========================================="
    echo "Next Steps:"
    echo "========================================="
    echo "1. Edit .env file:"
    echo "   nano $BACKEND_DIR/.env"
    echo ""
    echo "2. Configure database (see above)"
    echo ""
    echo "3. Run migrations:"
    echo "   cd ~ && php deploy/cpanel/migrate.php"
    echo ""
    echo "4. Run seeders (creates superadmin):"
    echo "   cd ~ && php deploy/cpanel/migrate.php seed"
    echo ""
    echo "5. Test your site:"
    echo "   - Frontend: https://yourdomain.com"
    echo "   - API Health: https://yourdomain.com/api/health"
    echo ""
    echo "========================================="
    echo "Deployment Scripts:"
    echo "========================================="
    echo "  php ~/deploy/cpanel/deploy.php status"
    echo "  php ~/deploy/cpanel/deploy.php deploy"
    echo "  php ~/deploy/cpanel/migrate.php status"
    echo ""
}

# Main execution
main() {
    echo "========================================="
    echo "Bratha Matu Console - cPanel Installation"
    echo "========================================="
    echo ""
    
    check_requirements
    setup_directories
    clone_repository
    setup_backend
    setup_frontend
    create_symlinks
    setup_database
    finalize
}

# Run main function
main

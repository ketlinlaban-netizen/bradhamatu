# cPanel Deployment Files - Overview

Complete reference for all cPanel deployment files in the `deploy/cpanel/` directory.

## 📁 File List

### 🚀 Main Deployment Scripts

#### `deploy.php` - Main Deployment Script
**Purpose:** Handles complete application deployment
**Usage:** `php deploy.php <command>`
**Commands:**
- `setup` - Initial setup (first time only)
- `deploy` - Deploy application updates
- `rollback` - Rollback to previous version
- `status` - Check deployment status

**Features:**
- ✅ Automated directory structure creation
- ✅ Frontend deployment from dist/
- ✅ Laravel optimization (config, route, view caching)
- ✅ Symlink management (API → Laravel public/)
- ✅ Automatic backups before deployment
- ✅ Deployment verification
- ✅ Color-coded console output
- ✅ Comprehensive logging

---

#### `migrate.php` - CLI Migration Runner
**Purpose:** Run database migrations via command line
**Usage:** `php migrate.php <command>`
**Commands:**
- `migrate` - Run pending migrations (default, safe)
- `fresh` - Drop all tables and re-run (requires confirmation)
- `rollback` - Rollback last batch
- `seed` - Run database seeders
- `status` - Show migration status

**Features:**
- ✅ Database connection testing
- ✅ Interactive confirmations for destructive operations
- ✅ Detailed migration output
- ✅ Automatic seeder prompts
- ✅ Comprehensive error logging

---

#### `web-migrate.php` - Web-Based Migration Runner ⭐
**Purpose:** Run migrations through web browser (no SSH needed!)
**Access:** `https://yourdomain.com/web-migrate.php`

**Features:**
- ✅ **No SSH required** - perfect for limited cPanel access
- ✅ Secure authentication with secret key (auto-generated)
- ✅ Beautiful web UI with real-time output
- ✅ All migration operations in one interface
- ✅ Color-coded terminal output
- ✅ Session-based authentication
- ✅ Automatic .env integration
- ✅ Built-in safety confirmations for destructive operations

**Security:**
- Secret key stored in .env (MIGRATION_SECRET_KEY)
- Session-based authentication
- Should be deleted after initial setup

**Operations Available:**
- 📊 Migration Status
- 🗄️ Database Info
- ▶️ Run Migrations
- 🌱 Run Seeders
- ⏪ Rollback
- 🧹 Clear Caches
- 💣 Fresh Migration (with confirmation)

---

### 🛠️ Installation & Setup Scripts

#### `install.sh` - Automated Installation
**Purpose:** Complete first-time setup automation
**Usage:** `bash install.sh`

**What it does:**
1. Checks requirements (PHP, Composer, Node.js)
2. Installs Composer if missing
3. Sets up directory structure
4. Installs Laravel dependencies
5. Creates .env from template
6. Generates application key
7. Sets correct permissions
8. Creates storage directories
9. Deploys frontend
10. Creates API symlink

---

#### `update.sh` - Quick Update Script
**Purpose:** Fast updates after code changes
**Usage:** `bash update.sh`

**What it does:**
1. Updates Composer dependencies
2. Runs pending migrations
3. Rebuilds Laravel caches
4. Clears old caches

---

#### `build-frontend.sh` - Frontend Build Script
**Purpose:** Build React frontend for production (run locally)
**Usage:** `bash build-frontend.sh`

**What it does:**
1. Checks Node.js installation
2. Installs npm dependencies
3. Runs TypeScript type check
4. Builds production bundle
5. Creates deployment package (.tar.gz)
6. Shows deployment instructions

---

### 🏥 Health & Monitoring

#### `health-check.php` - Comprehensive Health Check
**Purpose:** Verify entire deployment health
**Usage:** `php health-check.php`

**Checks:**
- ✅ PHP version and extensions
- ✅ Directory structure
- ✅ File permissions
- ✅ Symlinks validity
- ✅ Environment configuration
- ✅ Database connection
- ✅ Migration table status
- ✅ Laravel application boot
- ✅ Frontend files presence
- ✅ Composer dependencies

**Output:** Color-coded report with summary

---

### 📄 Configuration Files

#### `.htaccess` - Apache Configuration
**Purpose:** Frontend routing and API proxy
**Features:**
- SPA routing (all requests → index.html)
- API proxy (/api → Laravel backend)
- HTTPS redirect
- Security headers
- Cache control for assets
- CORS configuration
- Directory listing protection

---

### 📚 Documentation

#### `README.md` - Main documentation entry point
Quick overview and file organization

#### `QUICKSTART.md` - 5-Minute Deployment Guide
Fast-track deployment for experienced users

#### `DEPLOYMENT_STEPS.md` - Complete Step-by-Step Guide
Detailed deployment instructions with:
- Prerequisites checklist
- Initial setup procedures
- Configuration instructions
- Update procedures
- Troubleshooting guide
- Security checklist
- Backup/restore procedures

#### `FILES_OVERVIEW.md` - This file
Complete reference of all deployment files

---

## 📊 Quick Reference Matrix

| Task | File to Use | SSH Required | Skill Level |
|------|-------------|--------------|-------------|
| **First Time Setup** | `install.sh` | Yes | ⭐⭐ |
| **Deploy Updates** | `deploy.php deploy` | Yes | ⭐⭐ |
| **Quick Update** | `update.sh` | Yes | ⭐ |
| **Run Migrations (CLI)** | `migrate.php` | Yes | ⭐⭐ |
| **Run Migrations (Web)** | `web-migrate.php` | No | ⭐ |
| **Health Check** | `health-check.php` | Yes | ⭐ |
| **Build Frontend** | `build-frontend.sh` | Local | ⭐⭐ |
| **Check Status** | `deploy.php status` | Yes | ⭐ |

---

## 🎯 Recommended Workflow

### Initial Deployment
1. Build frontend locally: `bash build-frontend.sh`
2. Upload files to cPanel (bradha-matu-api, deploy, dist)
3. SSH in and run: `bash deploy/cpanel/install.sh`
4. Configure .env file
5. Run migrations via web: `https://yourdomain.com/web-migrate.php`
6. Deploy: `php deploy/cpanel/deploy.php deploy`
7. Delete web-migrate.php
8. Run health check: `php deploy/cpanel/health-check.php`

### Regular Updates
1. Build frontend locally
2. Upload dist/ to server
3. SSH in and run: `bash deploy/cpanel/update.sh`
4. Or use: `php deploy/cpanel/deploy.php deploy`

### Emergency Rollback
```bash
php deploy/cpanel/deploy.php rollback
```

---

## 🔐 Security Best Practices

1. **Delete web-migrate.php after initial setup**
2. Keep .env outside public_html
3. Use strong MIGRATION_SECRET_KEY
4. Enable HTTPS (SSL certificate)
5. Set APP_DEBUG=false in production
6. Use secure database passwords
7. Regular backups
8. Monitor logs regularly

---

## 📞 Support & Logs

### Log Files Locations
```
~/deploy.log              - Deployment logs
~/migration.log           - Migration logs
~/web-migration.log       - Web migration logs
~/bradha-matu-api/storage/logs/laravel.log - Application logs
```

### Quick Diagnostics
```bash
# Check deployment status
php deploy/cpanel/deploy.php status

# Run health check
php deploy/cpanel/health-check.php

# Check migration status
php deploy/cpanel/migrate.php status

# View recent logs
tail -50 ~/deploy.log
```

---

## 🎓 Learning Path

**Beginner:** Start with `QUICKSTART.md` + `web-migrate.php`  
**Intermediate:** Use `install.sh` + CLI scripts  
**Advanced:** Customize scripts for your workflow

---

**Version:** 1.0  
**Last Updated:** 2025-10-11  
**Maintainer:** Bratha Matu Development Team

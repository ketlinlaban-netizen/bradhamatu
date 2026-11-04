# cPanel Deployment - Quick Start

**Fastest way to deploy Bratha Matu Console to cPanel.**

## 📦 Before You Start

1. **Build Frontend Locally:**
   ```bash
   cd /path/to/project
   npm install
   npm run build
   ```

2. **Prepare Files:**
   - `dist/` folder (frontend build)
   - `bradha-matu-api/` folder (Laravel backend)
   - `deploy/cpanel/` folder (deployment scripts)

## 🚀 5-Minute Deployment

### 1. Upload to cPanel

**Via SSH:**
```bash
# Upload using SCP
scp -r bradha-matu-api cpaneluser@yourdomain.com:~/
scp -r deploy cpaneluser@yourdomain.com:~/
scp -r dist cpaneluser@yourdomain.com:~/
```

**Via cPanel File Manager:**
- Upload `bradha-matu-api` to home directory
- Upload `deploy` to home directory
- Upload `dist` contents to `public_html`

### 2. SSH into cPanel

```bash
ssh cpaneluser@yourdomain.com
```

### 3. Run Auto-Install

```bash
cd ~
chmod +x deploy/cpanel/*.sh deploy/cpanel/*.php
bash deploy/cpanel/install.sh
```

### 4. Configure Database

**In cPanel:**
1. Create MySQL database (e.g., `cpaneluser_bradha`)
2. Create database user with all privileges
3. Note down credentials

**Update .env:**
```bash
nano ~/bradha-matu-api/.env
```

Set these values:
```env
DB_DATABASE=cpaneluser_bradha
DB_USERNAME=cpaneluser_bradha
DB_PASSWORD=your_password

SUPERADMIN_EMAIL=admin@yourdomain.com
SUPERADMIN_PASSWORD=YourSecurePassword123!
```

Save (Ctrl+X, Y, Enter)

### 5. Run Migrations

**Option A: Web Interface (Easier - No SSH Required)**

1. Upload `deploy/cpanel/web-migrate.php` to `public_html/`
2. Visit: `https://yourdomain.com/web-migrate.php`
3. Copy the secret key shown (saved to .env automatically)
4. Login with the secret key
5. Click "Run Migrations" then "Run Seeders"
6. **Save the superadmin credentials shown!**
7. Delete `web-migrate.php` for security

**Option B: SSH/Command Line**

```bash
cd ~
php deploy/cpanel/migrate.php
php deploy/cpanel/migrate.php seed
```

**Save the superadmin credentials shown!**

### 6. Deploy

```bash
php deploy/cpanel/deploy.php setup
php deploy/cpanel/deploy.php deploy
```

### 7. Verify

Visit:
- Frontend: https://yourdomain.com
- API: https://yourdomain.com/api/health
- Login with superadmin credentials

✅ **Done!**

## 🔄 Quick Updates

```bash
# After building frontend locally and uploading dist/
cd ~
bash deploy/cpanel/update.sh
```

## ❤️ Health Check

```bash
php deploy/cpanel/health-check.php
```

## 📚 Full Documentation

See [DEPLOYMENT_STEPS.md](./DEPLOYMENT_STEPS.md) for detailed guide.

## 🆘 Common Issues

**500 Error:**
```bash
chmod -R 755 ~/bradha-matu-api/storage
chmod -R 755 ~/bradha-matu-api/bootstrap/cache
cd ~/bradha-matu-api && php artisan config:clear
```

**API 404:**
```bash
rm ~/public_html/api
ln -s ~/bradha-matu-api/public ~/public_html/api
```

**Database Error:**
```bash
cd ~/bradha-matu-api
php artisan db:show
```

## 📞 Support

Check logs:
```bash
tail -50 ~/deploy.log
tail -50 ~/bradha-matu-api/storage/logs/laravel.log
```

---

**Time to deploy:** ~5 minutes  
**Difficulty:** ⭐⭐☆☆☆ (Easy)

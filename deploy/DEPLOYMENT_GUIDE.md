# Community WiFi Bradha Matu — Complete Deployment Guide

**Run the NOC console locally on Kali Linux, expose it via Cloudflare Tunnel, and monitor real MikroTik routers — without deploying to cPanel.**

---

## Table of Contents

1. [Repository Inspection Report](#1-repository-inspection-report)
2. [Prerequisites & Missing Information](#2-prerequisites--missing-information)
3. [Cloudflare Tunnel Configuration](#3-cloudflare-tunnel-configuration)
4. [cloudflared Service Configuration](#4-cloudflared-service-configuration)
5. [Kali Linux Setup Script](#5-kali-linux-setup-script)
6. [Environment Variable Documentation](#6-environment-variable-documentation)
7. [Laravel Deployment & Startup Guide](#7-laravel-deployment--startup-guide)
8. [Public Hostname Configuration Guide](#8-public-hostname-configuration-guide)
9. [MikroTik Network Connectivity Guide](#9-mikrotik-network-connectivity-guide)
10. [Troubleshooting Guide](#10-troubleshooting-guide)
11. [Acceptance Test Report](#11-acceptance-test-report)
12. [Rollback Procedure](#12-rollback-procedure)

---

## 1. Repository Inspection Report

### What Exists

| Component | Location | Status |
|---|---|---|
| React frontend (Vite + TypeScript) | `src/` | Complete, build passes |
| Frontend service layer | `src/services/` | `LaravelApiProvider` wired to Laravel API |
| Frontend auth | `src/auth/AuthContext.tsx` | Calls Laravel `/api/auth/login` with Sanctum |
| API client | `src/services/api.ts` | Centralized fetch with Bearer token, 401 redirect |
| Laravel contracts | `laravel-contracts/` | Controllers, services, models, migrations, config |
| MikroTik binary API client | `laravel-contracts/app/Services/MikroTikApiClient.php` | Pure-PHP, TCP 8728/8729, challenge-response login |
| MikroTik provider | `laravel-contracts/app/Services/MikroTikRouterProvider.php` | Binary API primary, REST API fallback |
| Mock provider (Laravel) | `laravel-contracts/app/Services/MockRouterProvider.php` | 24 fake routers, deterministic demo data |
| Read-only guard | `laravel-contracts/app/Services/RouterReadOnlyGuard.php` | Blocks WRITE operations at service layer |
| Router service | `laravel-contracts/app/Services/RouterService.php` | All calls pass through guard |
| API routes | `laravel-contracts/routes/api.php` | GET-only + auth + health + rate-limited login |
| Database migrations | `laravel-contracts/database/migrations/` | routers, router_metrics, incidents (UUID PKs) |
| MikroTik config | `laravel-contracts/config/mikrotik.php` | Router inventory, protocol selection |
| Deployment infrastructure | `deploy/` | Setup script, systemd, nginx, cloudflared configs |

### What Does NOT Exist Yet (Must Be Created on Your Machine)

- A working Laravel installation (the contracts are templates, not a running app)
- MariaDB database (setup script creates it)
- nginx virtual host (setup script installs it)
- cloudflared tunnel (requires your Cloudflare account login)
- Admin user (created via tinker after Laravel install)

### Frontend Build

The frontend builds successfully (`npm run build` → `dist/`). The built files are copied into Laravel's `public/` directory so the SPA and API are served from the same origin, eliminating CORS issues.

---

## 2. Prerequisites & Missing Information

### What You Need Before Starting

| Requirement | Why | How to Get It |
|---|---|---|
| Kali Linux laptop | Local hosting target | Already have |
| Domain name | Cloudflare hostname | Register at any registrar (e.g., Cloudflare Registrar, Namecheap) |
| Cloudflare account | Tunnel management | Free at [cloudflare.com](https://cloudflare.com) |
| Domain added to Cloudflare | DNS + tunnel routing | Add zone in Cloudflare dashboard, update nameservers at registrar |
| MikroTik router(s) | Monitoring targets | Physical routers with RouterOS |
| Network reachability to routers | API calls | Same LAN, VPN, or private management network |

### What You Do NOT Need

- cPanel or shared hosting
- A VPS or cloud server
- Docker (optional, not required)
- Any paid Cloudflare plan (free tier supports tunnels)

### Information You Must Provide During Setup

1. **Your domain name** (replaces `YOUR_DOMAIN` in configs)
2. **MariaDB root password** (for database creation)
3. **MikroTik router IPs and credentials** (for live mode)
4. **Admin email and password** (for console login)

---

## 3. Cloudflare Tunnel Configuration

### Architecture

```
Admin Browser
  → Cloudflare HTTPS (console.YOUR_DOMAIN)
  → Cloudflare Tunnel
  → cloudflared (Kali Linux)
  → nginx (127.0.0.1:8080)
  → Laravel public/ directory
  → PHP-FPM → MariaDB + MikroTik API
```

### Mode A — Quick Tunnel (Temporary Testing)

A quick tunnel gives you a random `trycloudflare.com` URL for immediate testing. **This is NOT a permanent address** — it changes every time you restart cloudflared.

```bash
# Start a quick tunnel pointing to the local app
cloudflared tunnel --url http://127.0.0.1:8080
```

Cloudflare prints a URL like `https://random-words-xxxx.trycloudflare.com`. Open it in your browser. This is useful for verifying the app works through a tunnel before setting up a named tunnel.

**Limitations:**
- URL changes on every restart
- No custom hostname
- No persistent configuration
- Not suitable for production

### Mode B — Named Tunnel (Persistent Production)

This is the recommended approach for a stable hostname.

#### Step 1: Install cloudflared

Already handled by `deploy/setup-kali.sh`. To verify:

```bash
cloudflared --version
```

#### Step 2: Authenticate cloudflared with your Cloudflare account

```bash
cloudflared tunnel login
```

This opens a browser window. **You must complete this step in your browser.** Select the domain you want to use. cloudflared saves a certificate to `~/.cloudflared/cert.pem`.

> **STOP HERE if you have not added your domain to Cloudflare yet.** Log in to the Cloudflare dashboard, click "Add a Site", enter your domain, and follow the nameserver instructions. Return here after DNS is active.

#### Step 3: Create the tunnel

```bash
cloudflared tunnel create bradha-matu
```

This outputs:
```
Created tunnel bradha-matu with id <TUNNEL_ID>
```

Note the tunnel ID. It also creates a credentials file at `~/.cloudflared/<TUNNEL_ID>.json`.

#### Step 4: Copy the credentials to the system location

```bash
sudo mkdir -p /etc/cloudflared
sudo cp ~/.cloudflared/<TUNNEL_ID>.json /etc/cloudflared/
sudo cp ~/.cloudflared/cert.pem /etc/cloudflared/
sudo cp deploy/cloudflared/config.yml /etc/cloudflared/config.yml
```

#### Step 5: Edit the config

Open `/etc/cloudflared/config.yml` and replace:
- `<TUNNEL_ID_FROM_CLOUDFLARED_TUNNEL_CREATE>` with your actual tunnel ID
- `YOUR_DOMAIN` with your actual domain

```bash
sudo nano /etc/cloudflared/config.yml
```

#### Step 6: Create the DNS route

```bash
cloudflared tunnel route dns bradha-matu console.YOUR_DOMAIN
```

This creates a CNAME record `console.YOUR_DOMAIN` → `<TUNNEL_ID>.cfargotunnel.com` in Cloudflare DNS. **This action modifies your DNS** — it only adds the one CNAME record, it does not alter existing records.

#### Step 7: Set correct permissions

```bash
sudo chown -R cloudflared:cloudflared /etc/cloudflared
sudo chmod 600 /etc/cloudflared/<TUNNEL_ID>.json
```

---

## 4. cloudflared Service Configuration

### systemd Service

The service file is at `deploy/systemd/cloudflared.service`. The setup script installs it to `/etc/systemd/system/cloudflared.service`.

**Security features:**
- Runs as unprivileged `cloudflared` user (not root)
- `NoNewPrivileges`, `ProtectSystem`, `ProtectHome` hardening
- Restart on failure with 5-second backoff
- Starts after network is online

### Enable and Start

```bash
# After completing the tunnel setup above:
sudo systemctl daemon-reload
sudo systemctl enable cloudflared    # Start on boot
sudo systemctl start cloudflared     # Start now
```

### Verify

```bash
sudo systemctl status cloudflared
sudo journalctl -u cloudflared -f    # Live logs
```

### Credential Safety

- Tunnel credentials (`<TUNNEL_ID>.json`) are in `/etc/cloudflared/` with `600` permissions
- Owned by `cloudflared` user, not readable by other users
- Never appear in application logs, source code, or frontend JavaScript
- The setup script does not print credentials

---

## 5. Kali Linux Setup Script

The script at `deploy/setup-kali.sh` automates:

1. **PHP 8.3** + extensions (mbstring, xml, curl, mysql, gd, bcmath, zip, intl)
2. **Composer** (PHP dependency manager)
3. **MariaDB** (database server)
4. **nginx** (web server, listens on 127.0.0.1:8080)
5. **php-fpm** (PHP FastCGI processor)
6. **cloudflared** (Cloudflare tunnel client)
7. **cloudflared system user** (unprivileged service account)
8. **MariaDB database** creation (interactive, prompts for root password)
9. **nginx virtual host** installation (backs up existing config)
10. **systemd service** for cloudflared

### Running It

```bash
cd /path/to/project
chmod +x deploy/setup-kali.sh
sudo ./deploy/setup-kali.sh
```

**Safe to rerun:** The script detects existing installations and skips them. It backs up nginx and systemd configs before overwriting. It never deletes databases, .env files, or application code.

### Other Scripts

| Script | Purpose |
|---|---|
| `deploy/start.sh` | Start MariaDB, php-fpm, nginx, cloudflared |
| `deploy/stop.sh` | Stop cloudflared, nginx, php-fpm (leaves MariaDB running) |
| `deploy/status.sh` | Check all services + test endpoints |

---

## 6. Environment Variable Documentation

### Frontend (.env in project root)

| Variable | Required | Default | Description |
|---|---|---|---|
| `VITE_API_URL` | No | `/api` | Laravel API base URL. Use `/api` when frontend is served from Laravel. Use `https://console.YOUR_DOMAIN/api` when frontend is separate. |

### Laravel (.env in Laravel root)

| Variable | Required | Default | Description |
|---|---|---|---|
| `APP_ENV` | Yes | — | `production` or `local` |
| `APP_DEBUG` | Yes | — | `false` in production (never expose errors) |
| `APP_URL` | Yes | — | `https://console.YOUR_DOMAIN` |
| `APP_KEY` | Yes | — | Run `php artisan key:generate` |
| `DB_DATABASE` | Yes | — | MariaDB database name |
| `DB_USERNAME` | Yes | — | MariaDB username |
| `DB_PASSWORD` | Yes | — | MariaDB password |
| `ROUTER_PROVIDER` | Yes | `mock` | `mock` (demo) or `mikrotik` (live) |
| `MIKROTIK_API_PROTOCOL` | No | `auto` | `auto`, `binary`, or `rest` |
| `MIKROTIK_TIMEOUT` | No | `10` | API timeout in seconds |
| `MIKROTIK_ROUTER_01_IP` | If mikrotik | — | Router IP address |
| `MIKROTIK_ROUTER_01_USER` | If mikrotik | `monitoring` | Read-only API username |
| `MIKROTIK_ROUTER_01_PASS` | If mikrotik | — | API password (store in .env only) |
| `MIKROTIK_ROUTER_01_API_PORT` | No | `8728` | Binary API port |
| `MIKROTIK_ROUTER_01_API_TLS` | No | `false` | Use TLS (port 8729) |
| `MIKROTIK_ROUTER_01_SSL` | No | `true` | REST API HTTPS |
| `MIKROTIK_ROUTER_01_VERIFY` | No | `false` | Verify REST TLS cert |

**No real secrets appear in this document.** Fill in real values in `.env` only. Never commit `.env` to git.

### Template

A complete template is at `deploy/laravel-env-template.txt`.

---

## 7. Laravel Deployment & Startup Guide

### Step 1: Create the Laravel Project

```bash
sudo mkdir -p /opt/bradha-matu-api
sudo chown $USER:$USER /opt/bradha-matu-api
composer create-project laravel/laravel /opt/bradha-matu-api
cd /opt/bradha-matu-api
```

### Step 2: Copy the Contract Files

```bash
# From the project root:
cp -r laravel-contracts/app/Http/Controllers/* /opt/bradha-matu-api/app/Http/Controllers/
cp -r laravel-contracts/app/Http/Middleware/* /opt/bradha-matu-api/app/Http/Middleware/
cp -r laravel-contracts/app/Services/* /opt/bradha-matu-api/app/Services/
cp -r laravel-contracts/app/Models/* /opt/bradha-matu-api/app/Models/
cp -r laravel-contracts/app/Providers/* /opt/bradha-matu-api/app/Providers/
cp laravel-contracts/config/mikrotik.php /opt/bradha-matu-api/config/
cp laravel-contracts/routes/api.php /opt/bradha-matu-api/routes/
cp -r laravel-contracts/database/migrations/* /opt/bradha-matu-api/database/migrations/
```

### Step 3: Register the Service Provider

Edit `/opt/bradha-matu-api/config/app.php`, add to the `providers` array:

```php
App\Providers\RouterServiceProvider::class,
```

### Step 4: Install Sanctum (if not already)

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

### Step 5: Configure .env

```bash
cp deploy/laravel-env-template.txt /opt/bradha-matu-api/.env
nano /opt/bradha-matu-api/.env
```

Fill in:
- `APP_KEY` (run `php artisan key:generate`)
- Database credentials (from the setup script output)
- `APP_URL=https://console.YOUR_DOMAIN`
- `ROUTER_PROVIDER=mock` (start with demo mode)

### Step 6: Run Migrations

```bash
cd /opt/bradha-matu-api
php artisan migrate
```

### Step 7: Build and Install the Frontend

```bash
cd /path/to/project
npm install
npm run build
cp -r dist/* /opt/bradha-matu-api/public/
```

### Step 8: Set Permissions

```bash
cd /opt/bradha-matu-api
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Step 9: Create Admin User

```bash
php artisan tinker
```

```php
$user = new App\Models\User();
$user->name = 'Network Administrator';
$user->email = 'admin@communitywifi';
$user->password = bcrypt('your-secure-password');
$user->role = 'super_admin';
$user->save();
```

> **Note:** The `users` table needs a `role` column. Add a migration:
> ```bash
> php artisan make:migration add_role_to_users_table --table=users
> ```
> Then in the migration:
> ```php
> $table->string('role')->default('viewer');
> ```

### Step 10: Configure Trusted Proxies

In `bootstrap/app.php` (Laravel 11):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: '*',
        headers: Request::HEADER_X_FORWARDED_FOR |
                 Request::HEADER_X_FORWARDED_PROTO |
                 Request::HEADER_X_FORWARDED_HOST);
    $middleware->append(App\Http\Middleware\SecurityHeaders::class);
})
```

### Step 11: Start Everything

```bash
./deploy/start.sh
```

### Step 12: Verify Locally

```bash
curl http://127.0.0.1:8080/api/health
# Expected: {"status":"ok","timestamp":"...","provider":"mock"}
```

---

## 8. Public Hostname Configuration Guide

### If Your Domain Is NOT Yet in Cloudflare

1. **Sign in to Cloudflare** → [dash.cloudflare.com](https://dash.cloudflare.com)
2. **Click "Add a Site"** → enter your domain
3. **Select the Free plan**
4. **Review DNS records** — Cloudflare scans existing records. Do not change them.
5. **Change nameservers** — Cloudflare gives you two nameservers. Go to your domain registrar (where you bought the domain) and replace the existing nameservers with Cloudflare's.
6. **Wait for propagation** — Can take 10 minutes to 24 hours. Cloudflare emails you when active.

> **Before changing nameservers:** Understand that this redirects ALL DNS for your domain to Cloudflare. If you have existing websites, email, or other services on this domain, verify that Cloudflare imported their DNS records correctly before switching.

### Configure the Hostname (After Domain Is Active)

1. **Create the tunnel** (see Section 3, Mode B, Steps 2-3)
2. **Route DNS:**
   ```bash
   cloudflared tunnel route dns bradha-matu console.YOUR_DOMAIN
   ```
3. **Update nginx config** — replace `YOUR_DOMAIN` with your domain:
   ```bash
   sudo nano /etc/nginx/sites-available/bradha-matu.conf
   sudo nginx -t && sudo systemctl reload nginx
   ```
4. **Start cloudflared:**
   ```bash
   sudo systemctl enable cloudflared
   sudo systemctl start cloudflared
   ```
5. **Test:**
   ```bash
   curl https://console.YOUR_DOMAIN/api/health
   ```

### Optional: Cloudflare Access (Additional Protection)

Cloudflare Access adds an identity check before traffic even reaches your server. This is a second authentication layer on top of Laravel's login.

1. In Cloudflare dashboard → **Zero Trust** → **Access** → **Applications**
2. Click **Add an application** → **Self-hosted**
3. Set the domain to `console.YOUR_DOMAIN`
4. Create a policy: allow your email address (or a list of admin emails)
5. Choose an identity provider: Cloudflare One-time PIN (simplest — no Google/OAuth needed)

With this enabled, visitors must authenticate with Cloudflare AND then log in to Laravel. Two independent layers.

---

## 9. MikroTik Network Connectivity Guide

### Critical Understanding

The Cloudflare Tunnel exposes your **web console** to the internet. It does NOT create a network path from your Kali Linux laptop to remote MikroTik routers. The Laravel backend must be able to reach router IPs directly over TCP 8728/8729 (binary API) or HTTPS 443 (REST API).

### Three Connectivity Scenarios

#### Scenario 1: Routers on the Same LAN

If your Kali laptop and the MikroTik router are on the same network:

```
Kali Linux (192.168.88.50) ←→ MikroTik (192.168.88.1) :8728
```

Just set `MIKROTIK_ROUTER_01_IP=192.168.88.1` in Laravel `.env`. No VPN needed.

#### Scenario 2: Routers at Remote Sites

If routers are at different physical locations, you need a private network path:

**Option A: WireGuard VPN (recommended)**

Install WireGuard on Kali Linux and on each MikroTik router (RouterOS 7+ supports WireGuard natively):

```
Kali Linux (10.0.0.1) ← WireGuard tunnel → MikroTik (10.0.0.2) :8728
```

On the MikroTik:
```
/wireguard/add name=wg1 listen-port=13231 private-key="<KEY>"
/ip/address/add address=10.0.0.2/24 interface=wg1
/wireguard/peers/add interface=wg1 public-key="<KALI_PUBLIC_KEY>" endpoint-address=<KALI_PUBLIC_IP> endpoint-port=13231 allowed-address=10.0.0.1/32
/ip/service/set api address=10.0.0.1/32
```

On Kali Linux, set `MIKROTIK_ROUTER_01_IP=10.0.0.2`.

**Option B: SSH Tunnel**

```bash
ssh -L 8728:192.168.88.1:8728 user@remote-site-gateway
```

Then set `MIKROTIK_ROUTER_01_IP=127.0.0.1` and `MIKROTIK_ROUTER_01_API_PORT=8728`.

**Option C: Site-to-Site IPsec**

If you already have IPsec tunnels between sites, ensure the MikroTik API port is reachable through the tunnel.

#### Scenario 3: Routers Behind NAT (No Direct Access)

If you cannot reach the router directly, consider:
- Reverse SSH tunnel from the router to your Kali laptop
- MikroTik's Cloud DDNS feature (`/ip/cloud/set ddns-enabled=yes`)
- A management VPN at the router site

### What You Must NEVER Do

- **Never publish MikroTik API ports (8728, 8729) to the public internet.** The API does not have brute-force protection or rate limiting.
- **Never put router credentials in frontend code or browser localStorage.** They stay in Laravel `.env` only.
- **Never use the Cloudflare Tunnel to expose router management ports.** The tunnel is for the web console only.

### Router Setup (Quick Reference)

On each MikroTik router:

```
# Enable binary API (restricted to Laravel server IP)
/ip service set api disabled=no address=<LARAVEL_IP>/32

# Create read-only user
/user group add name=api-read policy=read,api,rest-api
/user add name=monitoring group=api-read password="LONG_RANDOM_PASSWORD"
/user set monitoring address=<LARAVEL_IP>/32

# Verify
/user print
/ip service print
```

For full setup including TLS certificates and REST API fallback, see `laravel-contracts/MIKROTIK_SETUP_GUIDE.md`.

---

## 10. Troubleshooting Guide

### The page won't load (blank screen)

| Check | Command |
|---|---|
| nginx running? | `sudo systemctl status nginx` |
| php-fpm running? | `sudo systemctl status php8.3-fpm` |
| Local app responds? | `curl http://127.0.0.1:8080` |
| Laravel .env valid? | `cd /opt/bradha-matu-api && php artisan config:clear` |
| Storage writable? | `ls -la /opt/bradha-matu-api/storage` (owner: www-data) |

### Cloudflare Tunnel not working

| Check | Command |
|---|---|
| cloudflared running? | `sudo systemctl status cloudflared` |
| Tunnel logs | `sudo journalctl -u cloudflared -n 50` |
| DNS route exists? | `cloudflared tunnel route dns bradha-matu console.YOUR_DOMAIN` (re-run to verify) |
| Config valid? | `cat /etc/cloudflared/config.yml` |
| Credentials file? | `ls -la /etc/cloudflared/*.json` |
| Quick tunnel test | `cloudflared tunnel --url http://127.0.0.1:8080` (bypasses named tunnel) |

### Login fails (401)

| Check | Command |
|---|---|
| User exists? | `cd /opt/bradha-matu-api && php artisan tinker` then `App\Models\User::all()` |
| Password correct? | Re-create user with known password |
| Rate limited? | Login is rate-limited to 5 attempts/minute. Wait 60 seconds. |
| API reachable? | `curl -X POST http://127.0.0.1:8080/api/auth/login -H 'Content-Type: application/json' -d '{"email":"...","password":"..."}'` |

### Routers show "offline" (Live Mode)

| Check | Command |
|---|---|
| Router reachable? | `ping <ROUTER_IP>` |
| API port open? | `nc -zv <ROUTER_IP> 8728` |
| API service enabled? | On router: `/ip service print` |
| User has access? | On router: `/user print` (check address restriction) |
| Firewall blocking? | On router: `/ip firewall filter print` |
| Laravel logs | `tail -50 /opt/bradha-matu-api/storage/logs/laravel.log` |
| Provider set to mikrotik? | Check `.env`: `ROUTER_PROVIDER=mikrotik` |

### CSRF / Session errors through tunnel

- Ensure `APP_URL=https://console.YOUR_DOMAIN` in Laravel `.env`
- Ensure trusted proxies are configured (Section 7, Step 10)
- Clear configs: `php artisan config:clear && php artisan route:clear && php artisan cache:clear`

### "502 Bad Gateway" from nginx

- php-fpm not running: `sudo systemctl start php8.3-fpm`
- Socket path mismatch: check `fastcgi_pass` in nginx config matches your PHP version
- Wrong root path: verify `root` in nginx config points to `public/` directory

---

## 11. Acceptance Test Report

| Test | Method | Expected | Status |
|---|---|---|---|
| Frontend builds | `npm run build` | Exit code 0 | **PASS** |
| Laravel app health | `GET /api/health` | 200 JSON | NOT TESTED (Laravel not installed) |
| Local app accessible | `curl http://127.0.0.1:8080` | HTML response | NOT TESTED (nginx not configured) |
| Cloudflare Tunnel status | `systemctl status cloudflared` | active (running) | NOT TESTED (tunnel not created) |
| Public HTTPS accessible | `curl https://console.YOUR_DOMAIN/api/health` | 200 JSON | NOT TESTED (domain not configured) |
| DNS resolution | `dig console.YOUR_DOMAIN` | CNAME to cfargotunnel.com | NOT TESTED |
| Admin login | POST /api/auth/login with credentials | 200 + token | NOT TESTED (user not created) |
| Session persistence | Login, reload page, still authenticated | Stays logged in | NOT TESTED |
| API authentication | GET /api/routers without token | 401 | NOT TESTED |
| CSRF validation | POST without CSRF token | 419 | NOT TESTED |
| SPA routing | Navigate to /routers directly | Page loads | NOT TESTED |
| Asset loading | CSS/JS files load | 200 | NOT TESTED |
| Database connectivity | `php artisan migrate` | Tables created | NOT TESTED |
| Demo router listing | `ROUTER_PROVIDER=mock`, GET /api/routers | 24 routers | NOT TESTED |
| Offline router display | Demo data includes offline routers | Shows in UI | NOT TESTED |
| Live MikroTik connectivity | `ROUTER_PROVIDER=mikrotik`, GET /api/routers | Real router data | NOT TESTED (no router configured) |
| Read-only enforcement | No mutation endpoints exist | Confirmed in routes | **PASS** (code review) |
| Service restart | `deploy/start.sh` after stop | All services start | NOT TESTED |
| Reboot recovery | Reboot, cloudflared starts automatically | systemd enables | NOT TESTED |
| Credential exposure check | `grep -r "MIKROTIK_ROUTER_01_PASS" src/` | No results | **PASS** (credentials only in .env) |
| Log exposure check | Laravel logs don't contain passwords | Log level = warning | **PASS** (code review) |

**Summary:** 3 tests pass (build, read-only enforcement, credential safety). 18 tests are NOT TESTED because they require a running Laravel installation, a Cloudflare account login, a configured domain, and/or a reachable MikroTik router — none of which can be performed from this environment.

**To complete the remaining tests:** Follow Sections 3-9 above. Each step includes the exact commands to run. Mark each test as PASS or FAIL as you go.

---

## 12. Rollback Procedure

### Undo Cloudflare Tunnel

```bash
# Stop and disable cloudflared
sudo systemctl stop cloudflared
sudo systemctl disable cloudflared

# Remove the DNS route (this removes the CNAME from Cloudflare DNS)
cloudflared tunnel route dns bradha-matu console.YOUR_DOMAIN --delete

# Delete the tunnel
cloudflared tunnel delete bradha-matu

# Remove config and credentials
sudo rm /etc/cloudflared/config.yml
sudo rm /etc/cloudflared/*.json
sudo rm /etc/cloudflared/cert.pem
sudo rm /etc/systemd/system/cloudflared.service
sudo systemctl daemon-reload
```

### Undo nginx Configuration

```bash
sudo rm /etc/nginx/sites-enabled/bradha-matu.conf
sudo rm /etc/nginx/sites-available/bradha-matu.conf
# If a backup exists:
sudo cp /etc/nginx/sites-available/bradha-matu.conf.bak.* /etc/nginx/sites-available/bradha-matu.conf 2>/dev/null
sudo nginx -t && sudo systemctl reload nginx
```

### Stop Services

```bash
./deploy/stop.sh
```

### Remove cloudflared

```bash
sudo apt remove cloudflared
```

### Laravel Application

The Laravel application and database are **not** removed by rollback. To remove them manually:

```bash
# Drop database (DESTRUCTIVE — only if you want to lose all data)
mariadb -u root -p -e "DROP DATABASE bradha_matu; DROP USER 'bradha_user'@'localhost';"

# Remove application
sudo rm -rf /opt/bradha-matu-api
```

> **Warning:** Dropping the database is irreversible. Only do this if you are certain you want to lose all data.

### What Rollback Does NOT Touch

- Your domain registration
- Your Cloudflare account
- Your Cloudflare DNS zone (other records remain)
- Your MikroTik router configuration
- Your Kali Linux system packages (PHP, MariaDB, nginx remain installed)
- Your existing applications or websites

---

## Security Checklist (Final)

- [ ] `APP_DEBUG=false` in production
- [ ] `APP_KEY` generated and set
- [ ] Database password is strong (not default)
- [ ] `ROUTER_PROVIDER` set correctly (mock for demo, mikrotik for live)
- [ ] MikroTik API user has `policy=read,api,rest-api` only
- [ ] MikroTik API service restricted to Laravel server IP
- [ ] MikroTik API password is strong and stored in `.env` only
- [ ] nginx serves only the `public/` directory (never project root)
- [ ] nginx blocks access to `.env`, `.sql`, `.log` files
- [ ] cloudflared runs as unprivileged user
- [ ] cloudflared credentials have `600` permissions
- [ ] Cloudflare Access enabled (optional, recommended)
- [ ] Admin password is strong
- [ ] Login rate limiting active (5 attempts/minute)
- [ ] Security headers middleware active
- [ ] Trusted proxies configured for Cloudflare
- [ ] No mutation endpoints exist in API routes
- [ ] RouterReadOnlyGuard active in service layer
- [ ] No router credentials in frontend code
- [ ] Laravel logs at `warning` level (no passwords logged)

# MikroTik API Configuration Guide

Complete step-by-step guide to connect your MikroTik routers to the Bradha Matu NOC Console.

---

## Overview

The console talks to MikroTik routers using two protocols:

1. **Binary API** (primary) — native TCP protocol on port 8728 (plain) or 8729 (TLS)
2. **REST API** (fallback) — HTTPS on port 443 at `/rest/`

Set `api_protocol` to `auto` (default) and the system tries binary first, then falls back to REST automatically.

The binary client sends `=name=` and `=password=` with the initial `/login` sentence, as documented for RouterOS 7. Use API-SSL or a trusted management network because credentials on plain TCP port 8728 are not encrypted. The configuration form defaults to API-SSL on port 8729 and verifies the TLS certificate; for a self-signed certificate, either install its CA certificate in the Laravel host trust store or explicitly disable certificate verification for that router.

---

## Step 1: Enable the API Service on the Router

Open Winbox or terminal on your MikroTik router and run:

### Binary API (TCP 8728)

```
/ip service set api disabled=no
```

Restrict to your Laravel server's IP only:

```
/ip service set api address=<LARAVEL_SERVER_IP>/32
```

### Binary API with TLS (TCP 8729) — Recommended

First, create a self-signed certificate:

```
/certificate add name=api-ca common-name=api-ca key-usage=key-cert-sign,crl-sign days-valid=3650
/certificate sign api-ca
:delay 3s
/certificate add name=api-cert common-name=<ROUTER_IP> subject-alt-name=IP:<ROUTER_IP> days-valid=365
/certificate sign api-cert ca=api-ca
:delay 3s
```

Then enable the API-SSL service:

```
/ip service set api-ssl certificate=api-cert disabled=no address=<LARAVEL_SERVER_IP>/32
```

### REST API (HTTPS port 443) — Fallback

```
/ip service set www-ssl certificate=api-cert disabled=no address=<LARAVEL_SERVER_IP>/32
```

---

## Step 2: Create a Read-Only User

This user can only read data — it cannot change any router configuration.

```
/user group add name=api-read policy=read,api,rest-api
/user add name=monitoring group=api-read password="USE_A_LONG_RANDOM_PASSWORD"
/user set monitoring address=<LARAVEL_SERVER_IP>/32
```

**Important:** The `policy=read,api,rest-api` grants only read access. Even if a bug tried to send a write command, the router would reject it. This is your second layer of defense (the first being the ReadOnlyGuard in the application code).

---

## Step 3: Configure the Laravel Backend

Edit your `.env` file:

```env
# Use real MikroTik API (not mock)
ROUTER_PROVIDER=mikrotik

# API protocol: auto (binary first, REST fallback), binary, or rest
MIKROTIK_API_PROTOCOL=auto

# ── Router 1 ──
MIKROTIK_ROUTER_01_IP=192.168.88.1
MIKROTIK_ROUTER_01_USER=monitoring
MIKROTIK_ROUTER_01_PASS=your-long-random-password
MIKROTIK_ROUTER_01_PROTOCOL=auto
MIKROTIK_ROUTER_01_API_PORT=8729
MIKROTIK_ROUTER_01_API_TLS=true

# ── Router 2 (example) ──
MIKROTIK_ROUTER_02_IP=192.168.89.1
MIKROTIK_ROUTER_02_USER=monitoring
MIKROTIK_ROUTER_02_PASS=your-long-random-password
MIKROTIK_ROUTER_02_PROTOCOL=auto
MIKROTIK_ROUTER_02_API_PORT=8729
MIKROTIK_ROUTER_02_API_TLS=true
```

Then add the router to `config/mikrotik.php`:

```php
[
    'id' => 'router-02',
    'name' => 'Malindi-01-A',
    'ip_address' => env('MIKROTIK_ROUTER_02_IP', '192.168.89.1'),
    'api_username' => env('MIKROTIK_ROUTER_02_USER', 'monitoring'),
    'api_password' => env('MIKROTIK_ROUTER_02_PASS', ''),
    'location' => 'Malindi',
    'site' => 'Coast',
    'api_protocol' => env('MIKROTIK_ROUTER_02_PROTOCOL', 'auto'),
    'api_port' => (int) env('MIKROTIK_ROUTER_02_API_PORT', 8729),
    'api_use_tls' => env('MIKROTIK_ROUTER_02_API_TLS', false),
    'use_ssl' => true,
    'verify_cert' => true,
    'created_at' => '2024-01-15T00:00:00Z',
],
```

---

## Step 4: Configure the Frontend

The frontend needs to know where the Laravel API is. Set this in the frontend `.env`:

```env
VITE_API_URL=http://localhost:8000/api
```

For production, point this to your Laravel server:

```env
VITE_API_URL=https://api.yourdomain.com/api
```

---

## Step 5: Configure CORS in Laravel

Allow the frontend origin to call the Laravel API. In `config/cors.php`:

```php
'paths' => ['api/*'],
'allowed_origins' => ['https://console.yourdomain.com'],
'allowed_methods' => ['GET', 'POST'],
'allowed_headers' => ['*'],
```

---

## Step 6: Create Admin Users in Laravel

Set credentials in `.env` and run the idempotent seeder:

```bash
SUPERADMIN_NAME="Network Administrator" \
SUPERADMIN_EMAIL=admin@bradhamatu.com \
SUPERADMIN_PASSWORD="your-long-random-password" \
php artisan db:seed --class=SuperAdminSeeder
```

The seeder creates or updates that account with the `super_admin` role. Never commit the real password.

---

## Step 7: Test the Connection

1. Start the Laravel server: `php artisan serve`
2. Start the frontend dev server
3. Log in with the admin credentials you created
4. The dashboard should show your routers with live data

If routers show as "offline":
- Check the router IP is reachable from the Laravel server: `ping <ROUTER_IP>`
- Check the API service is enabled: `/ip service print`
- Check the user has correct policies: `/user print detail`
- Check the firewall allows the Laravel server: `/ip firewall filter print`
- Check Laravel logs: `storage/logs/laravel.log`

---

## Protocol Reference

### Binary API Wire Format

| Component | Format |
|---|---|
| Word | `<encoded-length><content>` |
| Sentence | `word1 word2 ... \x00` (zero-length terminator) |
| Command | `/system/resource/print` |
| Attribute | `=.proplist=cpu-load,uptime` |
| Query | `?type=ether` |
| Data reply | `!re` + `=key=value` pairs |
| Done | `!done` |
| Error | `!trap` + `=message=<error>` |

### Login Flow

```
Client:  /login
Router:  !done =ret=<challenge>

Client:  /login =name=monitoring =response=<md5_hash>
Router:  !done   (success) or !trap =message=<error>
```

The response is `MD5(0x00 + password + challenge_bytes)` in hex.

### Ports

| Service | Port | Protocol | Use |
|---|---|---|---|
| api | 8728 | TCP | Binary API (plain) |
| api-ssl | 8729 | TCP+TLS | Binary API (encrypted) |
| www-ssl | 443 | HTTPS | REST API fallback |

---

## Security Checklist

- [ ] API service restricted to Laravel server IP only
- [ ] Read-only user created with `policy=read,api,rest-api`
- [ ] User restricted to Laravel server IP
- [ ] Strong password on the monitoring user
- [ ] TLS enabled for API (api-ssl on port 8729) if possible
- [ ] Firewall rules allow only the Laravel server to reach API ports
- [ ] Laravel `.env` passwords are strong and not committed to git
- [ ] CORS configured to allow only the frontend domain
- [ ] Sanctum authentication enabled on all API routes
- [ ] No write/modification endpoints exist in `routes/api.php`

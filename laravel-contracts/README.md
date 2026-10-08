# Community WiFi Bradha Matu — Laravel Backend with MikroTik Integration

A complete Laravel 11 backend that integrates directly with MikroTik RouterOS using both the native binary API protocol and the REST API.

## Architecture

```
React Frontend (NOC Console)
        ↓ HTTP GET only
Laravel API (READ-ONLY)
        ↓ RouterService → ReadOnlyGuard → Provider
        ├─ MockRouterProvider (demo, no real routers needed)
        └─ MikroTikRouterProvider
                ├─ Binary API (primary)    ← TCP 8728/8729
                └─ REST API (fallback)     ← HTTPS /rest/
                        ↓
                MikroTik Router(s)
```

The server talks to MikroTik routers directly — no CORS issues because all API calls are server-to-server.

## Dual API Protocol Support

### Binary API (Primary)
The native RouterOS binary API protocol communicates over TCP (port 8728 plain, 8729 TLS). It uses a custom wire format:

- **Word encoding**: Length-prefixed bytes (1-5 byte header depending on data size)
- **Sentences**: Command word + attribute words + zero-length terminator
- **Login**: Challenge-response using MD5(`\x00` + password + challenge)
- **Query words**: Filter results with `?name=x`, `?type=ether`, `?#|` (OR), `?#&` (AND), `#!` (NOT)
- **Replies**: `!re` (data), `!done` (complete), `!trap` (error), `!fatal` (connection close)

The `MikroTikApiClient` class implements this protocol from scratch in pure PHP — no external packages required.

### REST API (Fallback)
If the binary API connection fails, the provider automatically falls back to the REST API (HTTP GET/POST to `/rest/` endpoints). This requires the `www-ssl` service to be enabled on the router.

### Protocol Selection
Set `api_protocol` per router or globally:
- `auto` — Binary API first, REST fallback (recommended, default)
- `binary` — Binary API only
- `rest` — REST API only

## Security Model

- **100% read-only**: Only `print`/`getall`/`monitor` commands are sent. No `set`, `add`, `remove`, `reboot`.
- **ReadOnlyGuard**: Classifies every operation as READ or WRITE, blocks WRITE at the service layer
- **Read-only MikroTik user**: Create a user with `policy=read,api,rest-api` — even if a bug tried to mutate, the router rejects it
- **No terminal, no commands**: Only structured API queries, never raw CLI commands

## Setup

### 1. Install Laravel

```bash
composer create-project laravel/laravel bradha-matu-api
cd bradha-matu-api
```

### 2. Copy these files into your Laravel project

```
app/Http/Controllers/    ← all controllers
app/Services/            ← RouterProvider, RouterService, ReadOnlyGuard,
                            MikroTikApiClient, MikroTikRouterProvider,
                            MockRouterProvider
app/Models/              ← Router, RouterMetric, Incident
app/Providers/           ← RouterServiceProvider
config/mikrotik.php      ← router configuration
database/migrations/     ← schema migrations
routes/api.php           ← API routes
```

### 3. Register the ServiceProvider

Add to `config/app.php` 'providers' array:

```php
App\Providers\RouterServiceProvider::class,
```

### 4. Configure routers

Add to `.env`:

```env
# Use 'mock' for demo data, 'mikrotik' for real routers
ROUTER_PROVIDER=mock

# API protocol: 'auto' (binary first, REST fallback), 'binary', or 'rest'
MIKROTIK_API_PROTOCOL=auto

# Router 1
MIKROTIK_ROUTER_01_IP=192.168.88.1
MIKROTIK_ROUTER_01_USER=monitoring
MIKROTIK_ROUTER_01_PASS=your-read-only-password
MIKROTIK_ROUTER_01_PROTOCOL=auto
MIKROTIK_ROUTER_01_API_PORT=8728
MIKROTIK_ROUTER_01_API_TLS=false
MIKROTIK_ROUTER_01_SSL=true
MIKROTIK_ROUTER_01_VERIFY=false
```

### 5. Run migrations

```bash
php artisan migrate
```

## MikroTik Router Setup

### Enable the Binary API (recommended)

```
# Enable the API service (TCP 8728)
/ip/service/set api disabled=no address=<laravel-server-ip>/32

# For TLS (recommended, TCP 8729):
/certificate/add name=api-ca common-name=api-ca key-usage=key-cert-sign,crl-sign days-valid=3650
/certificate/sign api-ca
:delay 3s
/certificate/add name=api-ssl common-name=192.168.88.1 subject-alt-name=IP:192.168.88.1 days-valid=365
/certificate/sign api-ssl ca=api-ca
:delay 3s
/ip/service/set api-ssl certificate=api-ssl disabled=no address=<laravel-server-ip>/32
```

### Enable the REST API (for fallback)

```
/ip/service/set www-ssl certificate=api-ssl disabled=no address=<laravel-server-ip>/32
```

### Create a read-only user

```
# Create a read-only user group
/user/group/add name=api-read policy=read,api,rest-api

# Create the monitoring user
/user/add name=monitoring group=api-read password="use-a-long-random-password"

# Restrict access to the Laravel server only
/user/set monitoring address=<laravel-server-ip>/32
```

References:
- Binary API: https://help.mikrotik.com/docs/display/ROS/API
- REST API:   https://help.mikrotik.com/docs/display/ROS/REST+API

## API Endpoints

All endpoints require authentication via Sanctum token.

### Auth
| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/auth/login` | Login, returns token |
| POST | `/api/auth/logout` | Logout |
| GET | `/api/auth/me` | Current user |

### Dashboard
| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/dashboard` | Network summary |

### Routers
| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/routers` | List all routers (filter: `?status=online`) |
| GET | `/api/routers/search?q=` | Search routers |
| GET | `/api/routers/{id}` | Single router details |
| GET | `/api/routers/{id}/status` | System resource status |
| GET | `/api/routers/{id}/interfaces` | Network interfaces |
| GET | `/api/routers/{id}/clients` | Connected clients |
| GET | `/api/routers/{id}/traffic` | Traffic stats |
| GET | `/api/routers/{id}/wireless` | Wireless info |
| GET | `/api/routers/{id}/metrics` | Historical metrics |
| GET | `/api/routers/{id}/connect` | Test read-only connection |

### MikroTik Configurations
| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/mikrotik-configs` | List all configs (passwords masked) |
| POST | `/api/mikrotik-configs` | Add a new router config (password stored, never returned) |
| DELETE | `/api/mikrotik-configs/{id}` | Delete a config |
| POST | `/api/mikrotik-configs/{id}/connect` | Test connection and return live system data |

### Global
| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/clients` | All clients network-wide |
| GET | `/api/traffic` | Aggregate traffic |
| GET | `/api/network-health` | All routers health |
| GET | `/api/incidents` | All incidents |

## Binary API Protocol Details

### Word Length Encoding

| Length Range | Bytes | Encoding |
|---|---|---|
| 0 ≤ len ≤ 0x7F | 1 | len |
| 0x80 ≤ len ≤ 0x3FFF | 2 | len \| 0x8000 |
| 0x4000 ≤ len ≤ 0x1FFFFF | 3 | len \| 0xC00000 |
| 0x200000 ≤ len ≤ 0xFFFFFFF | 4 | len \| 0xE0000000 |
| len ≥ 0x10000000 | 5 | 0xF0 + 4-byte len |

### Sentence Structure

```
/command/path/print        ← command word
=.proplist=name,type       ← API attribute word
?type=ether                ← query word (filter by type)
?#|                        ← query operator (OR)
\x00                       ← zero-length word (terminator)
```

### Reply Types

| Reply | Meaning |
|---|---|
| `!re` | Data item — contains `=key=value` attribute words |
| `!done` | Command completed successfully |
| `!trap` | Error — contains `=message=` and `=category=` |
| `!fatal` | Connection closing — contains reason |

### Login Flow (Challenge-Response)

```
Client sends:  /login
Router replies: !done =ret=<challenge_hex>

Client sends:  /login =name=<user> =response=<md5_hex>
Router replies: !done  (auth success) or !trap =message=<error>
```

The response is computed as: `MD5(0x00 + password + challenge_bytes)` in hex.

## Forbidden Endpoints (DO NOT CREATE)

```
PUT    /api/routers/{id}          — no config changes
PATCH  /api/routers/{id}          — no modifications
DELETE /api/routers/{id}          — no deletions
POST   /api/routers/{id}/reboot   — no reboots
POST   /api/routers/{id}/command  — no terminal
POST   /api/routers/{id}/firewall — no firewall edits
POST   /api/routers/{id}/password — no password changes
```

The absence of these endpoints IS the security model.

## Switching Providers

Set `ROUTER_PROVIDER` in `.env`:

- `mock` — fake data for development (24 routers, 1284 clients)
- `mikrotik` — real MikroTik API calls (binary + REST)

No code changes needed. Controllers, routes, and frontend are identical.

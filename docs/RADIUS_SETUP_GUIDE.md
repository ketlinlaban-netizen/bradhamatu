# RADIUS Integration — FreeRADIUS + MikroTik Setup Guide

This guide walks you through configuring FreeRADIUS and MikroTik routers to work with Bradha Matu's billing system.

## Architecture Overview

```
MikroTik Router  ──RADIUS auth──▶  FreeRADIUS Server  ──SQL──▶  Supabase/PostgreSQL
     │                                                              │
     │                                                          Bradha Matu API
     │                                                          (provisions users)
     ▼
  Customer Device
```

When RADIUS is enabled, the Bradha Matu API writes user credentials, rate-limit groups, and session timeouts directly into the FreeRADIUS database tables. MikroTik routers authenticate against FreeRADIUS, which reads those tables to accept or reject connections and apply bandwidth limits.

## 1. Install FreeRADIUS with PostgreSQL backend

### On your server (Debian/Ubuntu):

```bash
sudo apt update
sudo apt install freeradius freeradius-postgresql freeradius-utils
```

### On Kali/other (using the deploy script):

```bash
cd /opt/bradha-matu
bash deploy/setup-freeradius.sh
```

## 2. Configure FreeRADIUS to use PostgreSQL

Edit `/etc/freeradius/3.0/mods-available/sql`:

```
driver = "rlm_sql_postgresql"
dialect = "postgresql"
server = "your-supabase-host.supabase.co"
port = 5432
login = "postgres"
password = "your-supabase-db-password"
radius_db = "postgres"
```

Enable the SQL module:

```bash
sudo ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
```

Edit `/etc/freeradius/3.0/mods-enabled/sql` and uncomment:

```
read_clients = yes
client_table = "nas"
```

This makes FreeRADIUS read NAS (router) configuration from the `nas` table instead of static config files.

## 3. Configure the RADIUS site

Edit `/etc/freeradius/3.0/sites-enabled/default` and ensure the `sql` module is listed in these sections:

- `authorize` — add `sql` after `pap`
- `authenticate` — keep `pap`, `chap`, `mschap`
- `accounting` — add `sql` at the top
- `session` — add `sql`
- `post-auth` — add `sql`

## 4. Enable RADIUS in Bradha Matu

Add these to your `.env`:

```
RADIUS_ENABLED=true
RADIUS_NAS_SECRET=your-secure-nas-secret-here
RADIUS_NAS_TYPE=mikrotik
RADIUS_AUTH_TYPE=Cleartext-Password
RADIUS_RATE_LIMIT_ATTR=Mikrotik-Rate-Limit
RADIUS_SESSION_TIMEOUT_ATTR=Max-All-Session
RADIUS_IDLE_TIMEOUT=600
RADIUS_INTERIM_INTERVAL=300
RADIUS_AUTO_REGISTER_NAS=true
```

When `RADIUS_ENABLED=true`, the activation system provisions users through FreeRADIUS tables instead of direct MikroTik API calls. When `false`, it falls back to direct MikroTik hotspot user management.

## 5. Register your MikroTik router as a NAS

Two options:

### Option A: Automatic sync (recommended)

From the admin console, go to System → RADIUS → click "Sync NAS". This registers every router in the database as a NAS entry in the `nas` table.

### Option B: Manual API call

```bash
curl -X POST https://your-api-domain/api/admin/radius/nas \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"nasname":"192.168.88.1","secret":"your-secure-nas-secret","shortname":"Main Router"}'
```

## 6. Configure MikroTik as a RADIUS client

### In Winbox or terminal:

```
/radius add
  address=<freeradius-server-ip>
  secret=your-secure-nas-secret
  service=hotspot
  authentication-port=1812
  accounting-port=1813
  timeout=3s
```

For PPPoE, use `service=ppp` instead.

### Enable RADIUS authentication for hotspot:

```
/ip hotspot profile set [find name="hsprof1"]
  use-radius=yes
  radius-accounting=yes
  interim-update=00:05:00
```

### For PPPoE:

```
/ppp profile set [find name="default"]
  use-radius=yes
  radius-accounting=yes
  interim-update=00:05:00
```

## 7. How provisioning works

When a customer pays for a package:

1. **ActivationService** checks if RADIUS is enabled
2. If enabled, `RadiusService` creates:
   - A `radcheck` entry with the username and Cleartext-Password
   - A `radcheck` entry with Max-All-Session timeout (if the package has a duration)
   - A `radusergroup` entry mapping the user to a bandwidth group
   - A `radgroupreply` entry with `Mikrotik-Rate-Limit` attribute for the group
3. The MikroTik router authenticates the user via RADIUS
4. FreeRADIUS checks the `radcheck` table for credentials
5. FreeRADIUS returns the rate limit and session timeout from `radgroupreply`
6. MikroTik applies the bandwidth limit and session duration

When a plan expires:

1. `ExpiryService` calls `ActivationService::deactivate()`
2. `RadiusService` disconnects active sessions and removes the user from `radcheck`, `radreply`, and `radusergroup`
3. The user is disconnected and can no longer authenticate

## 8. Bandwidth group naming convention

Groups are named `bm-{rx_kbps}-{tx_kbps}-{duration_minutes}`.

For example, a 5Mbps down / 2Mbps up package for 1440 minutes (24 hours):
- Group: `bm-5120-2048-1440`
- Rate limit reply: `5M/2M`
- Session-Timeout: `86400` (seconds)

## 9. Monitoring

From the admin console → System → RADIUS, you can:

- View RADIUS status (enabled, NAS count, active sessions)
- Manage NAS entries (add, delete, sync from routers)
- View active sessions with username, IP, duration, data usage
- Disconnect active sessions
- View accounting history (all past sessions)
- View per-user data usage with session details
- View authentication logs (radpostauth)
- View and inspect bandwidth groups

## 10. Troubleshooting

### User cannot connect

1. Check FreeRADIUS is running: `systemctl status freeradius`
2. Test authentication manually:
   ```bash
   radtest username password <freeradius-ip> 1812 your-nas-secret
   ```
3. Check the `radcheck` table has the user:
   ```sql
   SELECT * FROM radcheck WHERE username = 'bm-xxxxx';
   ```
4. Check FreeRADIUS logs: `tail -f /var/log/freeradius/radius.log`
5. Run FreeRADIUS in debug mode: `freeradius -X`

### Bandwidth limits not applied

1. Check the `radgroupreply` table has the `Mikrotik-Rate-Limit` attribute for the group
2. Verify the user is in the correct group in `radusergroup`
3. Ensure MikroTik is set to use RADIUS for the hotspot/PPPoE profile

### Accounting not updating

1. Verify `radius-accounting=yes` on the MikroTik hotspot/PPPoE profile
2. Check the `radacct` table for entries:
   ```sql
   SELECT * FROM radacct WHERE username = 'bm-xxxxx' ORDER BY acctstarttime DESC LIMIT 5;
   ```
3. Ensure the `accounting` section in the FreeRADIUS site config includes `sql`

### NAS not responding

1. Verify the NAS entry exists: `SELECT * FROM nas WHERE nasname = '192.168.88.1';`
2. Check the secret matches between the NAS table and MikroTik RADIUS config
3. Ensure port 1812/1813 is open on the FreeRADIUS server firewall

# Copilot Prompt — Install Laravel & Run Migrations

Copy and paste the prompt below into GitHub Copilot Chat or your AI assistant:

---

I have a Laravel backend project with contracts (controllers, models, services, migrations, routes, config) in a folder called `laravel-contracts/`. I need you to set up a fresh Laravel 11 project and integrate these files. Do the following step by step:

## Step 1 — Create a new Laravel 11 project

```bash
composer create-project laravel/lavelength bradha-matu-api
cd bradha-matu-api
```

If that fails, use:
```bash
composer create-project laravel/laravel bradha-matu-api
cd bradha-matu-api
```

## Step 2 — Install Sanctum (for API token authentication)

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

## Step 3 — Copy the contract files into the Laravel project

Copy these files from `laravel-contracts/` into the corresponding locations in the new Laravel project:

```
laravel-contracts/app/Http/Controllers/*.php  →  app/Http/Controllers/
laravel-contracts/app/Http/Middleware/*.php   →  app/Http/Middleware/
laravel-contracts/app/Models/*.php            →  app/Models/
laravel-contracts/app/Services/*.php          →  app/Services/
laravel-contracts/app/Providers/RouterServiceProvider.php → app/Providers/
laravel-contracts/config/mikrotik.php         →  config/mikrotik.php
laravel-contracts/routes/api.php              →  routes/api.php
laravel-contracts/database/migrations/*.php   →  database/migrations/
```

Overwrite any existing files (the Laravel defaults for `routes/api.php` and models like `Router.php` should be replaced with our versions).

## Step 4 — Register the RouterServiceProvider

Add this line to the `providers` array in `config/app.php`:

```php
App\Providers\RouterServiceProvider::class,
```

## Step 5 — Register middleware

In `bootstrap/app.php` (Laravel 11 format), add the custom middleware to the middleware array or register them in `app/Http/Kernel.php` (Laravel 10 format). The middleware files are:

- `App\Http\Middleware\SecurityHeaders` — adds security headers to all responses
- `App\Http\Middleware\TrustCloudflareProxies` — trusts Cloudflare proxy IPs

## Step 6 — Configure the database

Edit `.env` and set the database connection. For MySQL:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bradha_matu
DB_USERNAME=root
DB_PASSWORD=your_password
```

Or for SQLite (quick start):

```env
DB_CONNECTION=sqlite
```

Then create the database:

```bash
# For MySQL:
mysql -u root -p -e "CREATE DATABASE bradha_matu;"

# For SQLite:
touch database/database.sqlite
```

## Step 7 — Generate the application key

```bash
php artisan key:generate
```

## Step 8 — Run database migrations

```bash
php artisan migrate
```

This will create the following tables:
- `routers` — MikroTik router inventory (with api_port and api_use_tls columns)
- `router_metrics` — historical metrics per router
- `incidents` — network incidents
- `users` — admin users (Laravel default)
- `personal_access_tokens` — Sanctum token storage

## Step 9 — Run Sanctum migrations (if not already run)

```bash
php artisan migrate
```

Sanctum's migrations should run automatically with `php artisan migrate`. If they don't, run:

```bash
php artisan vendor:publish --tag=sanctum-migrations
php artisan migrate
```

## Step 10 — Create an admin user (for API login)

Open `php artisan tinker` and run:

```php
\User::create([
    'name' => 'Admin',
    'email' => 'admin@bradhamatu.com',
    'password' => bcrypt('your-secure-password'),
]);
```

Or create a seeder:

```bash
php artisan make:seeder AdminUserSeeder
```

Then edit `database/seeders/AdminUserSeeder.php`:

```php
public function run(): void
{
    \App\Models\User::create([
        'name' => 'Admin',
        'email' => 'admin@bradhamatu.com',
        'password' => bcrypt('your-secure-password'),
    ]);
}
```

Run the seeder:

```bash
php artisan db:seed --class=AdminUserSeeder
```

## Step 11 — Configure the MikroTik provider

In `.env`, set:

```env
# Use 'mock' for demo data, 'mikrotik' for real routers
ROUTER_PROVIDER=mock
MIKROTIK_API_PROTOCOL=auto
MIKROTIK_TIMEOUT=10
```

For real routers, add per-router config:

```env
MIKROTIK_ROUTER_01_IP=192.168.88.1
MIKROTIK_ROUTER_01_USER=monitoring
MIKROTIK_ROUTER_01_PASS=your-read-only-password
MIKROTIK_ROUTER_01_API_PORT=8728
MIKROTIK_ROUTER_01_API_TLS=false
```

## Step 12 — Enable API routes

Ensure `routes/api.php` is loaded. In Laravel 11, check `bootstrap/app.php`:

```php
->withRouting(
    api: __DIR__.'/../routes/api.php',
    // ...
)
```

In Laravel 10, ensure `app/Providers/RouteServiceProvider.php` has:

```php
Route::middleware('api')
    ->prefix('api')
    ->group(base_path('routes/api.php'));
```

## Step 13 — Serve the application

```bash
php artisan serve
```

The API will be available at `http://localhost:8000/api/`.

## Step 14 — Test the API

```bash
# Login
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@bradhamatu.com","password":"your-secure-password"}'

# Use the returned token for subsequent requests
curl http://localhost:8000/api/health
curl http://localhost:8000/api/routers -H "Authorization: Bearer <token>"

# List MikroTik configs (passwords masked)
curl http://localhost:8000/api/mikrotik-configs -H "Authorization: Bearer <token>"

# Add a MikroTik router config
curl -X POST http://localhost:8000/api/mikrotik-configs \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"name":"Test Router","host":"192.168.88.1","username":"monitoring","password":"secret","apiPort":8728}'

# Connect to a router (tests live MikroTik API and returns system data)
curl -X POST http://localhost:8000/api/mikrotik-configs/<id>/connect \
  -H "Authorization: Bearer <token>"
```

## Troubleshooting

- **Migration error about `api_port` column**: Make sure migration `2024_01_01_000004_add_api_port_to_routers_table.php` was copied. Run `php artisan migrate:fresh` to start clean.
- **Sanctum `personal_access_tokens` table missing**: Run `php artisan vendor:publish --tag=sanctum-migrations` then `php artisan migrate`.
- **Controller not found**: Run `composer dump-autoload`.
- **Route not found**: Ensure `routes/api.php` is registered in `bootstrap/app.php` or `RouteServiceProvider.php`.
- **MikroTik connection fails**: Set `ROUTER_PROVIDER=mock` first to test the API without a real router, then switch to `mikrotik` once the router is configured.

# bradhamatu

[![Open in Bolt](https://bolt.new/static/open-in-bolt.svg)](https://bolt.new/~/sb1-ut21fm3i)

## Laravel API

The Laravel 13 backend is in [`bradha-matu-api/`](./bradha-matu-api/). It uses SQLite by default for local development and mock router data unless configured otherwise.

```bash
cd bradha-matu-api
composer install
cp -n .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
php artisan serve
```

See [`laravel-contracts/COPILOT_PROMPT.md`](./laravel-contracts/COPILOT_PROMPT.md) for setup details and [`laravel-contracts/README.md`](./laravel-contracts/README.md) for MikroTik configuration.

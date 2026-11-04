# bradhamatu

[![Open in Bolt](https://bolt.new/static/open-in-bolt.svg)](https://bolt.new/~/sb1-ut21fm3i)

## Laravel API

The Laravel 13 backend is in [`bradha-matu-api/`](./bradha-matu-api/). It uses SQLite for local development and connects to routers stored in the Laravel database by default. The explicit mock provider is only for fictional demo data.

```bash
cd bradha-matu-api
composer install
cp -n .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
php artisan serve
```

See [`bradha-matu-api/README.md`](./bradha-matu-api/README.md) for setup, super-admin seeding, router registration, and RouterOS security requirements.

Build the cPanel release archive with `bash deploy/cpanel/build-package.sh`.
The upload/extract/browser migration procedure is in
[`docs/CPANEL_BROWSER_DEPLOYMENT.md`](./docs/CPANEL_BROWSER_DEPLOYMENT.md).
See also [`docs/CPANEL_DEPLOYMENT_AUDIT.md`](./docs/CPANEL_DEPLOYMENT_AUDIT.md)
and [`docs/MIKROTIK_PRODUCTION_CONNECTIVITY.md`](./docs/MIKROTIK_PRODUCTION_CONNECTIVITY.md).
The package can be built and tested locally, but deployment to cPanel and
server-to-router connectivity still require verification on the actual host.

## Online console SSH access

The reusable connection script is [`scripts/connect-onlineconsole.sh`](./scripts/connect-onlineconsole.sh).
It connects to `pandatechsco@169.58.129.32` on port `6622` and opens an
interactive shell in `/home/pandatechsco/public_html/onlineconsole.pandatechs.co.ke`.

The key has been converted to `~/.ssh/pandatechsco_onlineconsole` for this
machine. To recreate it, convert the encrypted PuTTY private key and restrict
the resulting unencrypted key to owner-only access:

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
puttygen "ssh acess tools/id_rsa (4).ppk" \
  --old-passphrase "ssh acess tools/passphrase" \
  --new-passphrase /dev/null \
  -O private-openssh-new \
  -o ~/.ssh/pandatechsco_onlineconsole
chmod 600 ~/.ssh/pandatechsco_onlineconsole
```

Run `./scripts/connect-onlineconsole.sh` to connect directly without an
agent or passphrase prompt. This convenience means the private key is
unencrypted: anyone who can read the key file can authenticate as this account.
Keep it outside the repository and retain the owner-only `600` permissions.
The server's host-key verification remains enabled.

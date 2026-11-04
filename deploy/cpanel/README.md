# cPanel deployment scripts

The supported package and browser migration flow is documented in
[../../docs/CPANEL_BROWSER_DEPLOYMENT.md](../../docs/CPANEL_BROWSER_DEPLOYMENT.md).
Build the package from the repository root with:

```sh
bash deploy/cpanel/build-package.sh
```

The scripts `install.sh`, `deploy.php`, `migrate.php`, `update.sh`, and the
old `.htaccess` are legacy utilities. They target a different filesystem
layout and must not be used for the supplied cPanel domain. The supported ZIP
keeps the Laravel application outside the document root and includes a
restricted, one-use migration page; it excludes `.env`, local databases, and
logs.

The browser runner accepts only `migrate --force` plus creation of the initial
super-admin on an empty user table. It has no destructive reset or rollback.

# TCT Website Plugins

Custom WordPress plugins for [teecorleytravels.com](https://teecorleytravels.com).

| Plugin | Purpose |
|---|---|
| [`tct-reader-kit`](tct-reader-kit/) | Sidebar table of contents + newsletter signup with subscriber storage |

## Ship checklist

Never edit the live site to find out whether something works. Order of operations:

1. **Back up first.** hPanel → Files → Backups. Hostinger's automatic backups are
   **weekly**, so take a manual one before anything risky.
2. `./scripts/smoke-test.sh` — must print `ALL CHECKS PASSED`.
3. Build the zip and upload it in wp-admin.

The smoke test exists because v1.0.0 shipped a bug that broke `manage_options` across
the whole site (the Settings menu vanished). It reproduces that failure in seconds
locally. It is verified to *fail* when the bug is reintroduced — a check that only ever
passes is worthless.

## Local WordPress

A local copy of the site lives at `~/Sites/tct-local`, matched to production
(WordPress 6.8.6, same theme). It exists so changes get tested somewhere nobody can see
before they touch teecorleytravels.com.

```bash
cd ~/Sites/tct-local && php -S localhost:8080 router.php
```

Then <http://localhost:8080> — admin at `/wp-admin`, user `tee`. The password is in
`~/Sites/.tct-local-adminpass` (the database password is in `~/Sites/.tct-local-dbpass`;
both are mode 600 and neither is in this repo).

The database runs on Homebrew MariaDB:

```bash
brew services start mariadb
```

WP-CLI needs a raised memory limit for some commands, so invoke it as:

```bash
php -d memory_limit=512M /opt/homebrew/bin/wp --path=~/Sites/tct-local <command>
```

Two known quirks, neither worth fixing: WP-CLI 2.12 prints a harmless PHP 8.5
deprecation notice on every run (it resets `error_reporting` in its own bootstrap, so
`WP_CLI_PHP_ARGS` cannot suppress it), and local PHP is 8.5 while the server runs 8.2.

## Building an installable zip

```bash
cd tct-website-plugins
zip -rq ../tct-reader-kit.zip tct-reader-kit -x '*.DS_Store'
```

Then upload via **Plugins → Add New → Upload Plugin** in wp-admin.

## Before shipping a change

Requires PHP (`brew install php`):

```bash
for f in $(find tct-reader-kit -name '*.php'); do php -l "$f"; done
```

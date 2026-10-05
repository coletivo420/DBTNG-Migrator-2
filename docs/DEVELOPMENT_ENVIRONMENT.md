# Virtualmin Development Environment

## Purpose

`bdtgn.toca.net.br` is the canonical disposable integration environment for DBTNG Migrator 2. It exists to exercise real Drupal 11 behavior with both MariaDB/MySQL and SQLite, including database-role switching, snapshot/rebuild logic and later continuous synchronization.

This document is an execution runbook for Codex and human developers. Authorization boundaries are normative in [../AGENTS.md](../AGENTS.md).

## 1. Privilege model

Start as a normal administrative/developer shell user.

When Virtualmin or package operations require administrative privileges, use:

```bash
sudo -v
```

The human may type the sudo/root password directly into the terminal prompt. The password must never be pasted into chat, stored in Git, echoed, passed as a command argument or written into a reusable file.

The Virtualmin CLI is expected to require root privileges. Application operations must then switch back to the dedicated domain owner.

## 2. Read-only discovery first

Before creating or changing anything, collect:

```bash
id
hostnamectl
command -v virtualmin
command -v php
command -v composer
command -v git
command -v mariadb || command -v mysql
command -v sqlite3
command -v mariadb-dump || command -v mysqldump
command -v gzip

sudo virtualmin list-domains --domain bdtgn.toca.net.br --multiline || true
sudo virtualmin list-domains --domain toca.net.br --multiline || true
sudo virtualmin list-plans --name-only || true
sudo virtualmin help create-domain
sudo virtualmin help modify-web

php -v
php -m | grep -Ei 'pdo|mysql|mysqli|sqlite|mbstring|xml|curl|gd|intl|openssl|zlib'
composer --version
sqlite3 --version
mariadb --version 2>/dev/null || mysql --version
(mariadb-dump --version 2>/dev/null || mysqldump --version) || true
```

Also verify DNS resolution for `bdtgn.toca.net.br` before requesting Let's Encrypt.

Do not assume Apache vs Nginx, PHP-FPM layout, username, home directory, MariaDB database prefix, PHP version or DNS ownership. Discover them.

## 3. Virtualmin provisioning decision

If `bdtgn.toca.net.br` already exists:

- confirm it is the dedicated DBTNG development domain;
- reuse it rather than creating a duplicate;
- never overwrite an unrelated existing site.

If it does not exist:

- if `toca.net.br` is managed by Virtualmin, prefer a dedicated child/sub-server for `bdtgn.toca.net.br`;
- otherwise create `bdtgn.toca.net.br` as its own Virtualmin virtual server;
- use the syntax reported by the installed `virtualmin help create-domain`; do not assume an option unsupported by that installed version;
- enable only features needed by the test site: Unix/home directory, web, SSL/TLS, MariaDB/MySQL and log rotation; mail is unnecessary unless a future test explicitly needs it;
- keep the installed Virtualmin plan/template defaults unless the project requires a documented override.

Virtualmin supports both top-level/child virtual servers and MySQL/MariaDB databases. Prefer its API over hand-editing shared web-server configuration.

## 4. Password handling for domain creation

Generate a strong development-domain credential without exposing it in shell history. Where the installed Virtualmin command supports `--passfile`, use a protected temporary file:

```bash
passfile="$(mktemp)"
chmod 600 "$passfile"
openssl rand -base64 36 > "$passfile"

# Run the inspected/supported virtualmin create-domain command with --passfile.
# Do not print the file contents.

rm -f "$passfile"
```

Never use the root password as a domain, database or Drupal password.

## 5. TLS and DNS

Request Let's Encrypt only after the hostname resolves to the server and HTTP validation can succeed.

Do not use `--skip-warnings` to suppress a DNS/certificate conflict without understanding it.

If DNS for `toca.net.br` is managed by Virtualmin, Codex may create or repair only the record(s) needed for `bdtgn.toca.net.br`.

## 6. PHP and system packages

Drupal 11.4 supports PHP 8.3, 8.4 and 8.5. Prefer an already installed supported PHP version and keep the web SAPI and CLI used by Composer/Drush aligned.

Required database connectivity includes PDO plus the MariaDB/MySQL and SQLite PHP drivers.

If a required package/extension is missing, Codex may install the **specific missing package** with sudo. Do not perform broad upgrades or removals as part of project setup.

After a per-domain web/PHP change, validate configuration before any necessary service reload/restart.

## 7. Filesystem layout

After Virtualmin creates the site, discover its actual owner and home path. Use a layout equivalent to:

```text
<domain-home>/
├── apps/dbtng-site/              # Drupal recommended-project
├── src/DBTNG-Migrator-2/         # Git checkout of this repository
└── private/dbtng/                 # SQLite and other private test artifacts
```

Recommended permissions:

- `private/dbtng/`: `0700`
- SQLite database files: `0600`

Keep all SQLite databases and manifests outside the public document root.

## 8. Drupal installation

Run application commands as the Virtualmin domain owner, not root.

Create the host application with Composer:

```bash
composer create-project drupal/recommended-project:^11.4 <domain-home>/apps/dbtng-site
cd <domain-home>/apps/dbtng-site
composer require drush/drush:^13.7
```

Use the repository checkout as a Composer `path` repository with symlinking enabled, then require:

```text
coletivo420/dbtng-migrator-2:@dev
```

Do not maintain a copied second version of the module under `web/modules/custom`.

Set the Virtualmin document root to the Drupal project's `web` directory using the installed Virtualmin-supported mechanism. Prefer `virtualmin modify-web`/domain-local configuration over manual global web-server edits.

## 9. Database resources

Create a dedicated MariaDB/MySQL database owned by `bdtgn.toca.net.br` using Virtualmin. The installed command can be inspected with:

```bash
sudo virtualmin help create-database
sudo virtualmin list-databases --domain bdtgn.toca.net.br --multiline
```

Create the SQLite database under `<domain-home>/private/dbtng/`.

The site must be able to test both role assignments:

```text
A. MariaDB/MySQL PRIMARY -> SQLite STANDBY
B. SQLite PRIMARY        -> MariaDB/MySQL STANDBY
```

## 10. Runtime topology settings

Use a local, uncommitted `settings.local.php` or equivalent deployment include based on [examples/settings.dbtng.php.example](examples/settings.dbtng.php.example).

The development selector is:

```text
DBTNG_DEV_PRIMARY=mysql
DBTNG_DEV_PRIMARY=sqlite
```

It must map the selected engine to `$databases['default']['default']` and the other engine to `$databases['dbtng_standby']['default']`.

Secrets must come from protected environment/server configuration, not repository files.

## 11. Native backup / restore tests

Before implementing custom dump/restore code, inspect the installed native database clients and the upstream projects listed in [UPSTREAM_COMPONENTS.md](UPSTREAM_COMPONENTS.md).

Required tests:

1. create/download MariaDB/MySQL `.sql.gz`;
2. restore it into the dedicated standby database;
3. create/download a live SQLite `.sqlite` snapshot;
4. restore it into the dedicated SQLite standby path;
5. repeat with populated destination using `backup_then_clear`;
6. repeat with disposable data using explicit `clear`;
7. verify destructive preparation refuses the active primary;
8. verify safety backups remain private and restorable.

Do not copy a live SQLite main file with `cp` as the backup implementation. Exercise WAL mode explicitly.

## 12. Import/bootstrap and smoke testing

The development environment must explicitly exercise the empty-destination import requirement before continuous synchronization work is considered complete.

### MariaDB/MySQL -> SQLite

1. install/populate Drupal using MariaDB/MySQL as primary;
2. ensure the configured SQLite destination is absent or contains no user-defined objects;
3. run the DBTNG import once the command is implemented;
4. validate the imported SQLite database;
5. verify a second import attempt is rejected because the destination is no longer empty;
6. for a `full` import, perform a controlled topology selection and boot Drupal from SQLite.

### SQLite -> MariaDB/MySQL

1. reset only the dedicated development data;
2. install/populate Drupal using SQLite as primary;
3. create an empty dedicated MariaDB/MySQL destination;
4. run the DBTNG import;
5. validate schema/data;
6. verify a second import attempt is rejected;
7. perform a controlled topology selection and boot Drupal from the imported MariaDB/MySQL database.

### Non-empty rejection

For each destination engine, create one harmless user-defined test object before import and prove that preflight fails **without adding, dropping or modifying anything**.

Until the import command itself exists, it is acceptable to reinstall/reset the **dedicated development data** between topology tests. Do not pretend an empty alternate database is already a synchronized standby.

For each primary topology after initialization:

1. set the runtime primary selector;
2. ensure the matching replication profile is valid;
3. install/boot Drupal;
4. enable DBTNG Migrator 2;
5. run `vendor/bin/drush status`;
6. run `vendor/bin/drush core:requirements --severity=2`;
7. run `vendor/bin/drush cache:rebuild`;
8. exercise a basic HTTP request;
9. record versions and results in the development notes/PR.

SQLite-primary testing must run with `full` standby profile. MariaDB/MySQL-primary testing may run both `full` and opt-in `clean`.

## 13. Reset policy

The development site is disposable, but deletion is scoped:

- development Drupal content may be reset;
- the DBTNG development MariaDB database may be recreated;
- development SQLite files may be deleted/recreated;
- the `bdtgn.toca.net.br` virtual server may be recreated when necessary.

Do not delete or alter other Virtualmin domains, databases or user data.

## 14. Evidence expected from Codex

After provisioning or integration work, report:

- detected OS/Virtualmin/web-server/PHP versions;
- exact domain owner/home path;
- Drupal and Drush versions;
- MariaDB/MySQL and SQLite versions;
- which primary topology was tested;
- native backup tool/backend selected for each engine;
- backup/restore and clear policies tested;
- commands/tests run and their exit status;
- any package or service changed with sudo;
- remaining failures or assumptions.

Never include passwords or full DSNs in that report.

## 15. Reference documentation

Use installed command help as the final authority because Virtualmin versions can differ. Current upstream references:

- Virtualmin command-line API: https://www.virtualmin.com/docs/development/command-line-api/
- Virtualmin create-domain: https://www.virtualmin.com/docs/development/api-programs/create-domain/
- Virtualmin create-database: https://www.virtualmin.com/docs/development/api-programs/create-database/
- Virtualmin modify-web: https://www.virtualmin.com/docs/development/api-programs/modify-web/
- Drupal database requirements: https://www.drupal.org/docs/getting-started/system-requirements/database-server-requirements
- Drupal PHP requirements: https://www.drupal.org/docs/getting-started/system-requirements/php-requirements
- Drush installation/compatibility: https://www.drush.org/latest/install/
- Drush sql:dump: https://www.drush.org/14.x/commands/sql_dump/
- Drush sql:drop: https://www.drush.org/14.x/commands/sql_drop/
- Backup and Migrate: https://www.drupal.org/project/backup_migrate
- SQLite Backup: https://www.drupal.org/project/sqlite_backup
- SQLite Online Backup API: https://www.sqlite.org/backup.html
- PHP SQLite3::backup(): https://www.php.net/manual/en/sqlite3.backup.php

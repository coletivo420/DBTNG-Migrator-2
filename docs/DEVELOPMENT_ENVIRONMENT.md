# Virtualmin Development Environment

## Purpose

`dbtng.toca.net.br` is the canonical disposable integration environment for DBTNG Migrator 2. It exists to exercise real Drupal 11 behavior with both MariaDB/MySQL and SQLite, including database-role switching, snapshot/rebuild logic and later continuous synchronization.

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

sudo virtualmin list-domains --domain dbtng.toca.net.br
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

Also verify DNS resolution for `dbtng.toca.net.br` before requesting Let's Encrypt.

Do not assume Apache vs Nginx, PHP-FPM layout, username, home directory, MariaDB database prefix, PHP version or DNS ownership. Discover them.

Do not use `virtualmin list-domains --multiline` for routine discovery: it may print database passwords. Prefer the minimal `list-domains --domain dbtng.toca.net.br` output or a specific query that excludes credential fields. Do not query or print current passwords. Keep all administrative output free of credentials before it reaches a terminal, log, report, CI artifact or chat.

## 3. Virtualmin provisioning decision

If `dbtng.toca.net.br` already exists:

- confirm it is the dedicated DBTNG development domain;
- reuse it rather than creating a duplicate;
- never overwrite an unrelated existing site.

If it does not exist:

- if `toca.net.br` is managed by Virtualmin, prefer a dedicated child/sub-server for `dbtng.toca.net.br`;
- otherwise create `dbtng.toca.net.br` as its own Virtualmin virtual server;
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

The `toca.net.br` zone is managed in Cloudflare. Codex may create, update or remove only DNS records for `dbtng.toca.net.br`; keep the A record DNS-only (proxy disabled), as required by the user.

### Verified host baseline (2026-10-06)

The deployed integration environment is:

- Debian GNU/Linux 13, Virtualmin 8.3.0-1 with its Nginx plugin, Nginx 1.26.3, PHP 8.4.26 for CLI and FPM, MariaDB 11.8.6 and SQLite 3.46.1.
- The canonical Virtualmin server and hostname are `dbtng.toca.net.br`. The `toca.net.br` DNS zone is authoritative at Cloudflare; its A record points directly to origin `45.191.204.31` with Cloudflare proxy disabled.
- The site keeps the dedicated Unix account `bdtgn` and home `/home/bdtgn`; the Drupal project is `/home/bdtgn/apps/dbtng-site`, document root `/home/bdtgn/apps/dbtng-site/web`, and module checkout `/home/bdtgn/src/DBTNG-Migrator-2`.
- The dedicated MariaDB database is named `bdtgn`. Its credentials remain in private owner-only files. SQLite and backup storage are under `/home/bdtgn/private/dbtng/`, outside the document root; private directories use mode `0700` and the SQLite file uses `0600`.
- A Let's Encrypt certificate for `dbtng.toca.net.br` is installed with automatic renewal. HTTP and HTTPS were verified against the origin; HTTPS certificate validation succeeded.
- Nginx's `www-data` account has execute-only ACL access to `/home/bdtgn` so it can traverse to the public document root and serve ACME challenge files without listing the home directory.

The site was initially provisioned under a temporary hostname with no public DNS record and a self-signed certificate. On 2026-10-06, the Virtualmin server was renamed to the canonical hostname; its Unix account, home, database and document root were preserved. The authoritative Cloudflare zone contains only the canonical integration hostname for this environment.

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

Create a dedicated MariaDB/MySQL database owned by `dbtng.toca.net.br` using Virtualmin. The installed command can be inspected with:

```bash
sudo virtualmin help create-database
sudo virtualmin list-databases --domain dbtng.toca.net.br
```

Create the SQLite database under `<domain-home>/private/dbtng/`.

The site must be able to test both role assignments:

```text
A. MariaDB/MySQL PRIMARY -> SQLite STANDBY
B. SQLite PRIMARY        -> MariaDB/MySQL STANDBY
```

## 10. Runtime topology settings

Use a protected, uncommitted settings include based on [examples/settings.dbtng.php.example](../examples/settings.dbtng.php.example). Supply both database credential sets through the hosting platform's protected environment and select roles with `DBTNG_DEV_PRIMARY=mysql` or `DBTNG_DEV_PRIMARY=sqlite`.

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
- the `dbtng.toca.net.br` virtual server may be recreated when necessary.

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

### Phase B command run record (2026-10-06)

The module checkout was updated by the dedicated `bdtgn` account to `feat/phase-b-topology-inventory-backup`. The Composer path repository remains a symlink to `/home/bdtgn/src/DBTNG-Migrator-2`; Composer's autoloader was regenerated as `bdtgn`. Drush 13's module command-file finder does not follow that symlink, so the Drupal project's `/home/bdtgn/apps/dbtng-site/drush/drush.yml` declares the three command classes under `drush.commands`. This host-specific registration stays outside the module checkout. Each command is marked to bootstrap Drupal fully before resolving its injected services.

Drupal 11.4.8 and Drush 13.8.0.0 both worked against MariaDB 11.8.6 and SQLite 3.46.1. The default MariaDB -> SQLite topology and temporary SQLite -> MariaDB topology both passed `dbtng:doctor`; `dbtng:preflight` ran read-only inventory in both directions and returned exit 1/BLOCKED because each target contains 56 tables from its own independent Drupal install. Both inventories completed; MariaDB -> SQLite reported 343 review warnings and no portability errors, while SQLite -> MariaDB reported 56 safe tables and no warnings/errors. Neither database is represented as synchronized.

All four role/engine backup combinations succeeded. MariaDB `.sql.gz` outputs passed `gzip -t`, were non-empty and contained SQL schema; SHA-256 and owner-only mode 0600 matched. The DB password was absent from the generated dump; unit tests also verify it is absent from process argv and temporary defaults files are removed. SQLite `.sqlite.gz` snapshots and a live uncompressed WAL snapshot passed `PRAGMA integrity_check` through PHP SQLite3 with Drupal's NOCASE_UTF8 collation registered. The WAL test held an open SQLite writer, made a committed fixture write, confirmed a non-empty WAL file, created a DBTNG backup while the writer remained open, and read the committed fixture from the verified snapshot. The fixture table was removed afterward.

`drush status`, `core:requirements --severity=2`, and `cache:rebuild` passed on the default MariaDB install. HTTPS returned 200 with TLS verification result 0. The runtime selector was restored to `DBTNG_DEV_PRIMARY=mysql` after the inverse-role tests. The private root `/home/bdtgn/private` is mode 0700; SQLite and backup storage are under `/home/bdtgn/private/dbtng/`, outside webroot. The settings include and database credentials remain private; no packages were installed and no services were reloaded or restarted during Phase B validation.

Logical import, restore, clearing, and continuous synchronization remain unimplemented. The SQLite and MariaDB Drupal installs are independent fixtures, not a synchronized pair.

The first environment bootstrap installed Drupal 11.4.8 and Drush 13.8.0.0 and enabled DBTNG Migrator 2. MariaDB and SQLite were each used as the selected Drupal primary for an independent install/boot smoke test. That SQLite install is not an imported or synchronized standby; do not present it as one. Logical import, native restore and continuous synchronization remain separate integration tests when those features are implemented.

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

## Phase C integration result

The real integration site is `dbtng.toca.net.br` only. Phase C exercised both full logical-import directions, the SQLite standby clean projection, native restore for each engine, non-empty policies and primary protection. The application is left with MariaDB primary and SQLite standby. A retained 1,200-row development fixture table was used to cross batch boundaries and verify unknown-table preservation. The first MariaDB -> SQLite physical portability analysis reported 343 warnings; those are retained as diagnostic evidence.

Never run `virtualmin list-domains --multiline` in normal diagnosis or automation; it can print database passwords. Do not reveal credentials in terminal output, logs, reports, CI or chat. The dedicated site's credential was rotated before Phase C and remains only in its private settings file.

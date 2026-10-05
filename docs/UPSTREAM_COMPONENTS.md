# Upstream Components and Provenance

DBTNG Migrator 2 follows an **upstream-first** implementation policy: before writing database backup, restore, download or clear logic, identify a mature implementation and reuse or adapt the smallest suitable component.

Do not vendor whole Drupal modules. Prefer small adapters around stable public APIs or carefully ported GPL-compatible code with attribution and tests.

## License baseline

DBTNG Migrator 2 is GPL-2.0-or-later.

Drupal.org states that contributed project files hosted in its Git repositories are GPL-2.0-or-later. Drush is also GPL-2.0-or-later. Compatible code may therefore be adapted into DBTNG with provenance retained.

When copying non-trivial code:

1. record the upstream project, version/tag/commit and file path here;
2. retain copyright/license notices required by the upstream license;
3. add a short provenance note in the adapted source file;
4. document material behavior changes;
5. add tests before relying on the adapted behavior.

## 1. Backup and Migrate

- Project: https://www.drupal.org/project/backup_migrate
- Current researched stable release: 5.1.5
- Drupal support: ^10.3 || ^11
- Upstream tag commit: `b64503cf` (5.1.5)
- License: GPL-2.0-or-later via Drupal.org
- Role in DBTNG: MySQL/MariaDB backup/restore workflow and browser/file streaming reference.

Useful upstream concepts:

- source -> backup file -> destination pipeline;
- downloadable database backup;
- uploaded/saved backup restore;
- table exclusion and structure-only table lists;
- bounded streaming of backup files rather than loading them into PHP memory;
- backup file metadata and compression/filter pipeline.

The historic `backupmigrate/backup_migrate_core` implementation contains reusable source/destination abstractions such as:

- `DatabaseSource`;
- `MySQLiSource`;
- `BrowserDownloadDestination`;
- readable/writable stream backup files.

### DBTNG decision

Do **not** depend on the Backup and Migrate Drupal module at runtime. Its Drupal 10+ support currently advertises MySQL as the database source, while DBTNG must work with either MySQL-family or SQLite primary.

Port/adapt only the required patterns or small GPL components. Use current 5.1.x source as the authoritative upstream when implementation begins; the older standalone GitHub core repository is design reference only.

## 2. SQLite Backup

- Project: https://www.drupal.org/project/sqlite_backup
- Researched release: 1.0.0-alpha1
- Drupal support: ^10 || ^11
- License: GPL-2.0-or-later via Drupal.org
- Role in DBTNG: whole-SQLite-database backup/restore workflow reference.

SQLite Backup is intentionally specific to sites whose active Drupal database is SQLite. DBTNG cannot depend on that assumption because SQLite may be either primary or standby.

### DBTNG decision

Reuse the **whole database file snapshot/restore model**, but implement it behind a role-aware SQLite adapter.

For a live SQLite source, prefer SQLite's own online backup facilities rather than a raw filesystem copy:

- PHP `SQLite3::backup()` exposes SQLite's online backup API;
- `VACUUM INTO` is an alternative for a compact consistent copy;
- raw copying a WAL-mode database without its WAL file is forbidden.

Before porting source lines from SQLite Backup, inspect the exact tagged source in the development environment and add file-level provenance.

## 3. Drush SQL subsystem

- Project: https://github.com/drush-ops/drush
- Researched branch: 14.x
- License: GPL-2.0-or-later
- Role in DBTNG: engine-specific dump/import/drop behavior and secure native-client invocation reference.

Relevant upstream code:

- `src/Sql/SqlBase.php`;
- `src/Sql/SqlMysql.php`;
- `src/Sql/SqlMariaDB.php`;
- `src/Sql/SqlSqlite.php`;
- `src/Commands/sql/SqlDumpCommand.php`;
- `src/Commands/sql/SqlDropCommand.php`.

Useful behavior:

- MariaDB/MySQL native dump with `--single-transaction`;
- secure temporary defaults file instead of exposing MySQL passwords in process arguments;
- selection of `mariadb` / `mariadb-dump` when appropriate;
- SQLite `.dump` support;
- database-specific table listing;
- drop-all-tables operation;
- importing SQL via the engine command client.

### DBTNG decision

Drush remains the preferred CLI integration, but the Drupal module runtime must not depend on Drush internal classes being stable APIs.

Adapt the proven engine-specific behavior into DBTNG services or invoke supported Drush commands from DBTNG Drush commands where appropriate. Do not call internal Drush command classes from normal web requests.

## 4. Backup Database

- Project: https://www.drupal.org/project/backup_db
- Latest stable release is Drupal 8-era.
- Advertises SQLite, MySQL, PostgreSQL and dblib export via a PHP mysqldump library.

### DBTNG decision

Reference only. Do not copy this module into the modern implementation because its stable Drupal release is obsolete. Its multi-engine export goal remains useful evidence that backup format handling should be adapter-based.

## 5. Database Export UI

- Project: https://www.drupal.org/project/db_export_ui
- Drupal support: ^10.3 || ^11
- MySQL/MariaDB only.
- Exports compressed SQL downloads; no restore.

### DBTNG decision

Reference its lightweight administration UX, but not its engine limitation. DBTNG download UI must support both primary/standby roles and both initial database engines.

## 6. Spatie DB Dumper

- Package: https://packagist.org/packages/spatie/db-dumper
- Researched release: 4.1.1
- PHP: ^8.3
- License: MIT
- Supports MySQL, MariaDB and SQLite by wrapping native database binaries.

### DBTNG decision

Keep as a candidate implementation dependency for native dump generation if it materially reduces maintained code. Do not add it merely to avoid a small adapter: it provides dump creation, not the complete restore/clear/Drupal-role workflow DBTNG requires.

## Upstream-first implementation checklist

Before implementing a backup/restore/clear adapter:

- [ ] identify the closest upstream implementation;
- [ ] record exact upstream version/commit;
- [ ] determine whether to call it, depend on it, or port only a small component;
- [ ] retain license/provenance;
- [ ] remove assumptions incompatible with selectable primary roles;
- [ ] avoid secrets in argv/logs;
- [ ] preserve bounded-memory streaming;
- [ ] add engine integration tests at `bdtgn.toca.net.br`;
- [ ] update this document when provenance changes.

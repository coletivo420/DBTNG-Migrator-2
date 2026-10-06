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

### Phase B implementation record

- Drush custom command registration was checked against the installed Drush 13.8 command generator/source and current Drush 14 SQL subsystem. Commands use Symfony Console `#[AsCommand]` classes in `src/Drush/Commands` with Drush `AutowireTrait`; no Drush internal runtime service is required by the module.
- Drush's MariaDB/MySQL dump behavior was studied as an operational reference: engine-appropriate client selection, consistent transaction and protected client options. DBTNG implements its own small adapter because direct-to-private-file streaming is module-specific.
- PHP `proc_open()` with an argv array avoids shell parsing. Credentials are placed in a temporary mode-0600 defaults file, not in argv.
- SQLite Online Backup API is called through PHP `SQLite3::backup()` for consistent live snapshots, including WAL databases.
- Backup and Migrate 5.1.5 was identified by tag/commit `b64503cf`; its pipeline/streaming architecture was studied as design reference. SQLite Backup 1.0.0-alpha1 was reviewed at project/documentation level as a whole-database workflow reference.
- No upstream source file or non-trivial code block was copied into DBTNG. These are call/adapt/design-reference decisions, not code-port claims.
- Live MariaDB 11.8.6 backups were validated on the protected development host in both configured roles. SQLite 3.46.1 backups passed integrity checks, including a WAL-mode snapshot with a concurrent open writer and a committed fixture row.
- Drush 13.8 command registration was verified on a real Drupal 11.4.8 site. Its module command-file finder does not follow Composer path-repository symlinks, so this disposable site's `drush/drush.yml` explicitly declares the module Symfony commands. The source classes use Drush's current `AutowireTrait`, Symfony `AsCommand`, and Drush full-bootstrap attribute; no legacy Drush service container or internal command classes are used.
- Drupal SQLite tables may use `NOCASE_UTF8`. SQLite backup validation must register Drupal's Unicode collation on SQLite3 handles; otherwise `PRAGMA integrity_check` fails when it reads those indexes.

Before implementing a backup/restore/clear adapter:

- [ ] identify the closest upstream implementation;
- [ ] record exact upstream version/commit;
- [ ] determine whether to call it, depend on it, or port only a small component;
- [ ] retain license/provenance;
- [ ] remove assumptions incompatible with selectable primary roles;
- [ ] avoid secrets in argv/logs;
- [ ] preserve bounded-memory streaming;
- [ ] add engine integration tests at `dbtng.toca.net.br`;
- [ ] update this document when provenance changes.

## Phase C provenance

No upstream source lines or non-trivial code blocks were copied into the Phase C cleaners, restore adapters, schema builders or import pipeline. The implementation uses the platform's native database APIs and applies the Drush SQL drop/import, credential-file and process-handling behavior studied during Phase B as design reference. No upstream dependency was added for Phase C. This is a call/adapt/design-reference outcome, not a code-port claim.


## 7. Engine-native trigger CDC foundation

Phase E1 uses documented MariaDB/MySQL and SQLite trigger semantics directly rather than embedding another Drupal replication module.

The implementation records dirty logical identity, not raw SQL and not full row images. No substantial upstream code was copied for the E1 trigger adapters.

Before extending this layer with binlog CDC or third-party replication libraries, record the exact upstream project/version here and compare its transaction, privilege and recovery guarantees against the trigger baseline.

# Native Database Backup, Download and Restore

## Purpose

DBTNG has two distinct data-movement families:

1. **Logical cross-engine import/synchronization** — MariaDB/MySQL <-> SQLite through DBTNG's normalized schema/data model.
2. **Native same-engine backup/restore** — download or restore the database in the engine's normal backup representation.

Do not parse a MySQL SQL dump as if it were a portable SQLite migration format. Cross-engine movement always uses DBTNG's logical migration engine.

## Implemented in Phase B: native CLI backup

`drush dbtng:backup --role=primary|standby` writes to a private local directory, optionally gzip-compresses, and reports role, engine, format, size and SHA-256. The default destination is `<file_private_path>/dbtng/backups`; an explicit `--output` must be absolute, outside the webroot, writable and owner-private. Credentials are not placed in the native client argument vector. The MariaDB/MySQL adapter uses a mode-0600 temporary defaults file and deletes it after the process. SQLite uses PHP `SQLite3::backup()` and verifies `PRAGMA integrity_check` before finalizing the artifact.

This is an operator CLI artifact, not a browser download endpoint. Native restore, clear and backup-then-clear are not implemented in Phase B.

The SQLite snapshot adapter registers Drupal's NOCASE_UTF8 collation before backup validation. Drupal schemas can contain indexes that use this collation; a plain SQLite3 connection reports an error when checking those indexes unless the collation is registered.

## Native download formats

### MariaDB/MySQL

Default downloadable format:

```text
.sql.gz
```

Uncompressed `.sql` is also supported.

Implementation priority:

1. native `mariadb-dump` when the server/client identifies as MariaDB;
2. native `mysqldump` for MySQL;
3. a PHP fallback adapted from Backup and Migrate only when a native client is unavailable and the fallback has passed compatibility tests.

Native dumps must use a consistent transaction where supported and must not expose credentials in process arguments.

### SQLite

Default downloadable format:

```text
.sqlite
```

Optional:

```text
.sqlite.gz
```

The downloadable file must be generated as a **consistent SQLite snapshot**, not by blindly copying a live WAL-mode database file.

Preferred mechanisms:

1. SQLite Online Backup API through PHP `SQLite3::backup()`;
2. `VACUUM INTO` when explicitly selected/appropriate.

The generated SQLite artifact must pass `PRAGMA integrity_check` before download.

## Download roles

The administration UI and CLI may back up either configured role:

- Primary;
- Standby.

Backup is read-only and must never cause a role change.

Implemented commands:

```bash
drush dbtng:backup --role=primary --output=/private/path
drush dbtng:backup --role=standby --output=/private/path
```

Future administration action (not implemented):

```text
Configuration
  -> Development
    -> DBTNG Migrator
      -> Backup / Download
```

The browser download path uses a private temporary artifact and streams it without loading the complete database into PHP memory.

## Native restore/import from file

Native backup restore is **same-engine**:

| File | Valid target |
| --- | --- |
| `.sql` / `.sql.gz` generated for MySQL-family | MariaDB/MySQL standby |
| `.sqlite` / `.sqlite.gz` | SQLite standby |

Cross-engine restoration from these native files is not attempted.

For cross-engine migration use:

```text
configured primary -> dbtng logical import -> configured standby
```

## Destination contains data

When import/restore detects a non-empty standby, the operator receives three explicit policies:

### `abort`

Default. No mutation.

### `backup_then_clear`

Recommended destructive path:

1. create a native backup of the current standby;
2. verify the backup artifact was completed and checksummed;
3. clear the standby;
4. perform import/restore;
5. validate the result.

If the safety backup fails, clearing does not begin.

### `clear`

Advanced destructive path. Clear without a safety backup.

This requires explicit confirmation in interactive UI/CLI and must never be inferred from `--yes` unless the destructive policy itself was explicitly selected.

## Never clear primary

Database clearing is scoped to the configured **standby destination**.

DBTNG must refuse to clear a connection when:

- it is the currently configured primary;
- its resolved identity matches the primary connection/database/file;
- topology validation cannot prove that it is a separate destination.

## Engine-specific clear behavior

### SQLite standby

For a standby SQLite database, replacement is file-oriented.

Safe implementation:

1. close DBTNG-owned destination connections;
2. if policy is `backup_then_clear`, create/verify a native SQLite safety snapshot;
3. build/import into a new private temporary SQLite file;
4. validate it;
5. atomically replace the standby file.

A direct destructive clear may recreate the standby SQLite file, but only when it is proven not to be the active primary.

This follows the same principle used by Drush's SQLite database creation logic: the database is a filesystem artifact.

### MariaDB/MySQL standby

Use the database's own DDL rather than row-by-row deletes.

Baseline behavior is adapted from Drush `sql:drop`:

1. enumerate destination objects;
2. drop views before dependent tables when required;
3. temporarily handle foreign-key constraints in an engine-safe way;
4. drop tables;
5. remove remaining DBTNG-relevant user objects (triggers/routines/events) according to portability inventory;
6. re-inventory and require the destination to be empty before import begins.

Dropping/recreating the entire database is allowed only for a dedicated DBTNG standby database when the configured credentials/operational policy explicitly permit it. It is not the universal default.

## Upload safety

Uploaded backup files:

- are stored outside webroot;
- receive generated server-side names;
- are never trusted based only on extension or client MIME type;
- have size limits;
- are checksummed;
- are decompressed only into private temporary storage;
- are validated before destructive destination preparation begins.

SQLite uploads must pass integrity validation.

SQL uploads must be restored only through the matching database client/parser; uploaded SQL is never executed against the other engine.

## Provenance

Implementation should adapt existing behavior documented in [UPSTREAM_COMPONENTS.md](UPSTREAM_COMPONENTS.md), especially:

- Backup and Migrate's backup-file/download/restore pipeline;
- SQLite Backup's whole-database workflow;
- SQLite's Online Backup API;
- Drush's native SQL dump/import/drop behavior.

Do not invent a second unrelated backup framework.

## Phase C native restore and safety preparation

`drush dbtng:restore <file>` restores only to the configured standby and only when the artifact format matches that standby engine. MariaDB/MySQL restore streams through the matching native client using a protected temporary credentials file. SQLite restore validates the SQLite header and integrity in private temporary storage, then publishes a candidate file. Gzip input is validated before destructive preparation.

When the destination is non-empty, `abort` is the default. `backup_then_clear` first creates and verifies a native safety artifact (checksum, nonzero size and engine-specific validation), then clears the standby. `clear` requires explicit selection. MySQL-family preparation removes scoped database objects and verifies the destination is empty; SQLite preparation replaces only the standby file. Primary identity checks are mandatory in all cases.

After native restore, DBTNG re-introspects and validates the restored database. MySQL-family validation includes `CHECK TABLE`; SQLite validation runs `integrity_check` and foreign-key checks where applicable. Validated imports and restores write a private versioned standby manifest only after completion. The manifest records validation and topology metadata, never credentials.

The development integration test exercised MySQL and SQLite restore, gzip validation, wrong-engine rejection, both safety-backup flows, clear, abort, and primary-clear refusal. Corrupt input and a simulated safety-backup failure were verified to leave destination data unchanged.

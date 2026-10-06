# DBTNG Migrator 2

DBTNG Migrator 2 is a modern reimplementation of the Drupal 7-era DBTNG Migrator idea: keep Drupal data portable and synchronized across supported database backends through a logical model instead of translating vendor-specific SQL dumps.

## Selectable primary database

The **primary database is a role selected by the deployment**, not a hard-coded engine.

Default topology:

```text
Drupal 11.4+
    |
    v
MariaDB / MySQL PRIMARY
    |
    +--> durable change capture --> policy engine --> SQLite STANDBY
    |
    +--> consistent rebuild snapshot --------------------^
```

Alternative topology:

```text
Drupal 11.4+
    |
    v
SQLite PRIMARY
    |
    +--> durable change capture --> policy engine --> MariaDB / MySQL STANDBY
    |
    +--> consistent rebuild snapshot -----------------------------^
```

MariaDB/MySQL is the default primary, but SQLite can be selected as primary. Core architecture reasons about `primary` and `standby` roles rather than assuming that a specific engine is authoritative.

### Bootstrap rule

The active Drupal database must be known **before Drupal can load its Config API**. For that reason, the actual primary/standby connection selection belongs in `settings.php` / environment-backed deployment settings, where `$databases['default']['default']` and `$databases['dbtng_standby']['default']` are defined.

Drupal configuration stores replication behavior such as the standby profile, validation and retention. It does **not** act as the bootstrap source of truth for which database Drupal itself uses.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and [docs/examples/settings.dbtng.php.example](docs/examples/settings.dbtng.php.example).

## Import and destination preparation

The product requires logical import in both directions:

```text
MariaDB/MySQL -> SQLite
SQLite        -> MariaDB/MySQL
```

Logical import and destination preparation are not implemented yet. The planned behavior is to import into an empty standby, or require an explicit policy when it contains data:

- `abort` — default, no mutation;
- `backup_then_clear` — create and verify a native safety backup, clear the standby, then import;
- `clear` — explicitly clear the standby without a safety backup.

The implementation must never silently merge or overwrite an existing database, and must never clear the active primary. The destination will be considered initialized only after validation succeeds.

See [docs/IMPORT.md](docs/IMPORT.md) and [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md).

## Native backup / download / restore

Phase B provides same-engine native database backups:

- MariaDB/MySQL: `.sql` or `.sql.gz`;
- SQLite: consistent `.sqlite` or `.sqlite.gz` snapshot.

Backups may be created from either configured role through Drush and are written to private storage. Browser downloads and native restore are planned; cross-engine movement will use DBTNG's logical import engine when implemented.

The implementation is upstream-first and adapts proven behavior from Backup and Migrate, SQLite Backup, SQLite's native backup API and Drush SQL tooling instead of creating a second independent backup framework.

See [docs/UPSTREAM_COMPONENTS.md](docs/UPSTREAM_COMPONENTS.md).

## Goals

- Let the deployment select MariaDB/MySQL or SQLite as the primary database.
- Default to MariaDB/MySQL primary with SQLite standby.
- Import the selected primary into the standby in either supported direction, with explicit handling for non-empty destinations.
- Download native backups of MariaDB/MySQL and SQLite and restore them to matching standby engines.
- Keep the selected primary authoritative during normal operation.
- Maintain the initialized standby as a continuously synchronized database.
- Support `full` and, when SQLite is the standby, optional `clean` replication.
- Never silently lose changes committed to the selected primary.
- Make disaster recovery behavior explicit and testable.
- Keep engine-specific behavior behind adapters.

## Replication profiles

### `full`

Copies every supported table and row selected for replication. **This is the safe default** and is valid for either supported standby direction.

### `clean`

An opt-in SQLite-standby profile. It is never applied to the authoritative primary.

It keeps Drupal-required schema while omitting selected volatile data:

| Table/category | Policy |
| --- | --- |
| `cache_*` | schema only |
| `sessions` | schema only |
| `semaphore` | schema only |
| `batch` | schema only |
| `watchdog` | schema only |
| `queue` | copy |
| `flood` | copy |
| `key_value` | copy |
| `key_value_expire` | copy |
| unknown tables | copy |

Revision filtering is deliberately **not** implemented as blind table filtering. A future entity-aware projection layer will support policies such as `default_only` and `published_only` safely across base, data, revision and field tables.

### `clean-public`

Reserved for a future, more aggressive SQLite standby projection that can exclude unpublished-only content. It is not implemented yet and is not accepted as a runtime profile.

## Core operations

1. **Native backup** — creates a private same-engine backup for either configured role with checksum metadata.
2. **Doctor and preflight** — resolve configured roles, inventory physical schemas, report destination state and strict portability findings.
3. **Import/restore and destination preparation** — planned; not implemented yet.
4. **Snapshot/Rebuild** — reconstructs an initialized standby using an isolated candidate.
5. **ChangeCapture** — records durable changes on the selected primary using an engine-specific adapter.
6. **PolicyEngine** — decides how standby data is represented.
7. **SyncEngine** — applies captured changes to the standby and tracks lag.

Import and rebuild share schema introspection, portability analysis, bounded-memory transfer and validation. Native backup/restore remains same-engine.

## Development environment

The canonical integration environment for this project is the dedicated Virtualmin site:

```text
https://dbtng.toca.net.br
```

Codex is authorized to provision and maintain that development installation within the boundaries documented in [AGENTS.md](AGENTS.md) and [docs/DEVELOPMENT_ENVIRONMENT.md](docs/DEVELOPMENT_ENVIRONMENT.md). Administrative operations may use `sudo`; when the operating system requests the root/sudo password, it must be entered interactively by the human and never stored, echoed, committed or passed through chat.

## Project status

This repository is in the early operational foundation phase. Phase B implements configured primary/standby resolution, physical MariaDB/MySQL and SQLite inventories, destination-state inspection, conservative portability preflight, and same-engine native backups through Drush. The Phase B branch has passed local quality checks and live validation; GitHub CI/CodeQL and PR review remain before merge. **It does not yet import, restore, clear, continuously synchronize, or fail over. Do not deploy it as a production standby system.**

## Requirements

- Drupal `^11.4`
- PHP `>=8.3`
- MariaDB 10.6+ / MySQL 8.0+
- SQLite 3.45+
- Drush `^13.7 || ^14`
- MariaDB/MySQL and SQLite as the initial engine pair

## Roadmap

1. Project skeleton, selectable topology contracts, documentation and CI. (complete)
2. Runtime topology resolver, doctor/preflight, MariaDB/MySQL and SQLite physical inventory, destination state inspection, portability analysis, and same-engine native backups. (implemented and live-validated; CI/merge pending)
3. Controlled standby clearing, native restore and logical import/bootstrap in both directions. (implemented and live-validated; CI/merge pending)
4. Destination builders and consistent rebuilds for both directions.
5. Engine-specific durable change capture.
6. Continuous synchronization and reconciliation.
7. Optional SQLite-standby clean projection.
8. Entity-aware revision projection.
9. Validation, lag monitoring and application compatibility tests.
10. Manual failover and controlled recovery in either direction.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/IMPORT.md](docs/IMPORT.md), [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md), [docs/UPSTREAM_COMPONENTS.md](docs/UPSTREAM_COMPONENTS.md), [docs/SYNC_MODEL.md](docs/SYNC_MODEL.md), [docs/DEVELOPMENT_ENVIRONMENT.md](docs/DEVELOPMENT_ENVIRONMENT.md) and [AGENTS.md](AGENTS.md) before changing core behavior.

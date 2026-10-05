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

## Empty-destination import

DBTNG must support a **one-time logical import into an empty destination** in both directions:

```text
MariaDB/MySQL -> empty SQLite
SQLite        -> empty MariaDB/MySQL
```

This is the bootstrap path for creating the first standby and also the basis for controlled migrations between engines.

The import preflight is strict:

- destination must be proven empty before any user schema/data is written;
- a non-empty destination causes a hard failure;
- the initial implementation has no destructive `--force`, merge or overwrite mode;
- source remains authoritative during the import;
- destination is validated before it can be accepted as initialized;
- `clean` is allowed only when the empty destination is SQLite acting as standby;
- an import intended for later promotion to primary must use `full`.

See [docs/IMPORT.md](docs/IMPORT.md).

## Goals

- Let the deployment select MariaDB/MySQL or SQLite as the primary database.
- Default to MariaDB/MySQL primary with SQLite standby.
- Import the selected primary into an empty standby in either supported direction.
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

1. **Import** — initializes a proven-empty destination from the selected primary.
2. **Snapshot/Rebuild** — reconstructs an already initialized standby using an isolated candidate.
3. **ChangeCapture** — records durable changes on the selected primary using an engine-specific adapter.
4. **PolicyEngine** — decides how standby data is represented.
5. **SyncEngine** — applies captured changes to the standby and tracks lag.

Import and rebuild share schema introspection, portability analysis, bounded-memory transfer and validation, but they have different destination preconditions.

## Development environment

The canonical integration environment for this project is the dedicated Virtualmin site:

```text
https://bdtgn.toca.net.br
```

Codex is authorized to provision and maintain that development installation within the boundaries documented in [AGENTS.md](AGENTS.md) and [docs/DEVELOPMENT_ENVIRONMENT.md](docs/DEVELOPMENT_ENVIRONMENT.md). Administrative operations may use `sudo`; when the operating system requests the root/sudo password, it must be entered interactively by the human and never stored, echoed, committed or passed through chat.

## Project status

This repository is in the bootstrap phase. Interfaces, models, policies, documentation and CI are being established before database introspection and synchronization implementation. **Do not deploy this branch as a production standby system yet.**

## Requirements

- Drupal `^11.4`
- PHP `>=8.3`
- MariaDB 10.6+ / MySQL 8.0+
- SQLite 3.45+
- Drush `^13.7 || ^14`
- MariaDB/MySQL and SQLite as the initial engine pair

## Roadmap

1. Project skeleton, selectable topology contracts, documentation and CI.
2. MariaDB/MySQL and SQLite physical schema inventory and portability analysis.
3. Empty-destination import/bootstrap in both directions.
4. Destination builders and consistent rebuilds for both directions.
5. Engine-specific durable change capture.
6. Continuous synchronization and reconciliation.
7. Optional SQLite-standby clean projection.
8. Entity-aware revision projection.
9. Validation, lag monitoring and application compatibility tests.
10. Manual failover and controlled recovery in either direction.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/IMPORT.md](docs/IMPORT.md), [docs/SYNC_MODEL.md](docs/SYNC_MODEL.md), [docs/DEVELOPMENT_ENVIRONMENT.md](docs/DEVELOPMENT_ENVIRONMENT.md) and [AGENTS.md](AGENTS.md) before changing core behavior.

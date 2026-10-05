# DBTNG Migrator 2

DBTNG Migrator 2 is a modern reimplementation of the Drupal 7-era DBTNG Migrator idea: move Drupal data between database backends through a portable logical model instead of translating vendor-specific SQL dumps.

The first supported topology is:

```text
Drupal 11.4+
    |
    v
MariaDB / MySQL PRIMARY
    |
    +--> durable change capture --> policy engine --> SQLite WARM STANDBY
    |
    +--> consistent rebuild snapshot --------------------^
```

The project is intentionally **not a literal port of the Drupal 7 module**. The legacy project relied on enabled-module schema declarations, global active-connection switching and offset-based batch copying. DBTNG Migrator 2 replaces those assumptions with database introspection, explicit connection objects, bounded-memory streaming, consistent snapshots, durable change tracking and validated publication.

## Goals

- Keep MariaDB/MySQL authoritative during normal operation.
- Maintain a SQLite warm standby that can be rebuilt and verified independently.
- Support a `full` profile and a `clean` profile from the start.
- Preserve schema even when the clean profile omits disposable data.
- Never silently lose changes committed to the primary database.
- Make disaster recovery behavior explicit and testable.

## Replication profiles

### `full`

Copies every supported table and row selected for replication.

### `clean`

Keeps Drupal-required schema while omitting volatile data that can safely be regenerated. Initial defaults are:

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

Reserved for a future, more aggressive entity projection that can exclude unpublished-only content. It is not implemented yet.

## Four subsystems

1. **SnapshotEngine** — creates a consistent rebuild of the standby.
2. **ChangeCapture** — records durable primary-side changes.
3. **PolicyEngine** — decides whether data is copied, projected or schema-only.
4. **SyncEngine** — applies captured changes and tracks lag.

The synchronization design uses a durable change log / transactional-outbox style model. It explicitly rejects naive independent dual writes to MariaDB and SQLite.

## Project status

This repository is in the bootstrap phase. Interfaces, models, policies, documentation and CI are being established before the MariaDB introspector and synchronization implementation are added. **Do not deploy this branch as a production standby system yet.**

## Requirements

- Drupal `^11.4`
- PHP `>=8.3`
- MariaDB/MySQL as the first source family
- SQLite as the first standby destination
- Drush `^13.7 || ^14` for the planned CLI

## Roadmap

1. Project skeleton, contracts, documentation and CI.
2. MariaDB/MySQL physical schema inventory and portability analysis.
3. SQLite schema builder and consistent rebuild snapshots.
4. `full` and `clean` replication policies.
5. Entity-aware revision projection.
6. Durable change capture and continuous sync worker.
7. Reconciliation, validation and lag monitoring.
8. Manual failover runbook.
9. SQLite-to-MariaDB recovery path.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/SYNC_MODEL.md](docs/SYNC_MODEL.md) and [AGENTS.md](AGENTS.md) before changing core behavior.

# DBTNG Migrator 2

DBTNG Migrator 2 is a modern reimplementation of the Drupal 7-era DBTNG Migrator idea: keep Drupal data portable and synchronized across supported database backends through a logical model instead of translating vendor-specific SQL dumps.

## Selectable primary database

The **primary database is a role selected by the operator**, not a hard-coded engine.

The default topology is:

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

The alternative supported topology is:

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

MariaDB/MySQL remains the **default** primary because it is the expected production choice for most Drupal sites, but SQLite can be selected as primary. All core architecture must therefore reason about `primary` and `standby` roles rather than assuming that a specific database engine is authoritative.

The project is intentionally **not a literal port of the Drupal 7 module**. The legacy project relied on enabled-module schema declarations, global active-connection switching and offset-based batch copying. DBTNG Migrator 2 replaces those assumptions with database introspection, explicit connection objects, bounded-memory streaming, consistent snapshots, durable change tracking and validated publication.

## Goals

- Let the operator select MariaDB/MySQL or SQLite as the primary database.
- Default to MariaDB/MySQL primary with SQLite standby.
- Keep the selected primary authoritative during normal operation.
- Maintain the other configured database as a continuously synchronized standby.
- Support a `full` profile and, when SQLite is the standby, a `clean` profile.
- Preserve schema even when the clean SQLite standby omits disposable data.
- Never silently lose changes committed to the selected primary.
- Make disaster recovery behavior explicit and testable.

## Primary/standby configuration

The initial configuration model is:

```yaml
primary:
  engine: mysql
  connection_key: default

standby:
  engine: sqlite
  connection_key: dbtng_standby
  profile: clean
```

To make SQLite primary:

```yaml
primary:
  engine: sqlite
  connection_key: default

standby:
  engine: mysql
  connection_key: dbtng_standby
  profile: full
```

The administration UI/Drush configuration layer will expose this selection. The two roles must use different engines in the initial product.

## Replication profiles

### `full`

Copies every supported table and row selected for replication. It is valid for either supported standby engine and is required initially when MariaDB/MySQL is the standby.

### `clean`

The clean profile is initially a **SQLite-standby feature**. It is never applied to the authoritative primary database.

It keeps Drupal-required schema while omitting volatile data that can safely be regenerated:

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

Reserved for a future, more aggressive SQLite standby projection that can exclude unpublished-only content. It is not implemented yet.

## Four subsystems

1. **SnapshotEngine** — creates a consistent rebuild from the selected primary to the configured standby.
2. **ChangeCapture** — records durable changes on the selected primary using an engine-specific adapter.
3. **PolicyEngine** — decides whether standby data is copied, projected or schema-only.
4. **SyncEngine** — applies captured changes to the standby and tracks lag.

The synchronization design explicitly rejects naive independent dual writes. Each supported primary engine needs a durable capture strategy that survives standby failure.

## Project status

This repository is in the bootstrap phase. Interfaces, models, policies, documentation and CI are being established before the database introspectors and synchronization implementation are added. **Do not deploy this branch as a production standby system yet.**

## Requirements

- Drupal `^11.4`
- PHP `>=8.3`
- MariaDB/MySQL and SQLite as the initial database engine pair
- MariaDB/MySQL primary by default
- SQLite primary selectable
- Drush `^13.7 || ^14` for the planned CLI

## Roadmap

1. Project skeleton, selectable topology contracts, documentation and CI.
2. MariaDB/MySQL and SQLite physical schema inventory and portability analysis.
3. Destination builders and consistent rebuilds for both directions.
4. `full` plus SQLite-standby `clean` replication policies.
5. Entity-aware revision projection for clean SQLite standby.
6. Engine-specific durable change capture and continuous sync workers.
7. Reconciliation, validation and lag monitoring.
8. Manual failover runbooks for both configured topologies.
9. Controlled recovery/authority migration in either direction.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/SYNC_MODEL.md](docs/SYNC_MODEL.md) and [AGENTS.md](AGENTS.md) before changing core behavior.

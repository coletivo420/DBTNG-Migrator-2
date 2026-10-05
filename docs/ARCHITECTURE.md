# Architecture

## Purpose

DBTNG Migrator 2 maintains two Drupal-compatible database representations with one explicit **primary** role and one **standby** role.

The database engine does not define authority; configuration does.

### Default topology

```text
Drupal writes
    |
    v
MariaDB/MySQL PRIMARY
    |\
    | +--> durable change capture --> SyncEngine --> PolicyEngine --> SQLite STANDBY
    |
    +----> consistent SnapshotEngine --------------------------------------^
```

### Alternate topology

```text
Drupal writes
    |
    v
SQLite PRIMARY
    |\
    | +--> durable change capture --> SyncEngine --> PolicyEngine --> MariaDB/MySQL STANDBY
    |
    +----> consistent SnapshotEngine ---------------------------------------------^
```

## Authority model

The configured primary is authoritative during normal operation.

- MariaDB/MySQL is the default primary.
- SQLite is a supported primary selection.
- No service may infer authority solely from `databaseType()`.
- Standby failure creates lag/backlog; it does not automatically change authority.

## Topology model

The initial engine pair is:

- `mysql`: Drupal's MySQL-family driver, covering MariaDB/MySQL.
- `sqlite`: Drupal's SQLite driver.

The initial topology requires different primary and standby engines. This avoids meaningless same-engine role duplication while the project is focused on cross-database portability.

## Components

- `DatabaseTopology`: immutable primary/standby engine and connection-key selection.
- `SnapshotManagerInterface`: orchestrates a consistent rebuild from the configured primary.
- `SourceSchemaIntrospectorInterface`: converts physical primary schema to DBTNG's portable model.
- `ChangeCaptureInterface`: owns engine-specific durable primary-side change capture.
- `SyncEngineInterface`: applies pending changes to the standby and reports synchronization state.
- `ReplicationPolicyInterface`: classifies standby data for full or clean replication.
- `SnapshotPublisherInterface`: promotes a validated isolated standby candidate.
- `StandbyCandidate`: engine-neutral description of candidate/published destination identifiers.

## Source of truth

Physical schema is discovered from the configured primary database. Drupal metadata may enrich interpretation, especially for entity projection, but does not replace physical introspection.

## Rebuild publication

A rebuild never mutates the published standby in place.

For SQLite standby, the candidate is naturally a temporary database file and publication can use same-filesystem atomic replacement.

For MariaDB/MySQL standby, the destination adapter must provide an isolated rebuild target and an explicit promotion strategy appropriate to a server database. It must provide the same external guarantee: failed rebuilds do not destroy the last known-good standby.

## Clean profile boundary

`clean` and future `clean-public` are initially SQLite-standby policies. They are never applied to the primary. If SQLite is selected as primary, it remains complete and MariaDB/MySQL standby uses `full` until a separately proven clean policy exists for that destination.

## Future entity projection

Clean revision filtering needs an entity-aware projection using Drupal entity storage/table mappings. It must understand base, data, revision, revision-data and dedicated field tables as a group.

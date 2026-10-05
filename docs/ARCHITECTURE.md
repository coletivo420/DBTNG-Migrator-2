# Architecture

## Purpose

DBTNG Migrator 2 maintains a logical SQLite warm standby for a Drupal installation whose primary database is MariaDB/MySQL.

```text
Drupal writes
    |
    v
MariaDB/MySQL PRIMARY
    |\
    | +--> durable change record --> SyncEngine --> PolicyEngine --> SQLite
    |
    +----> consistent SnapshotEngine -------------------------------> SQLite rebuild
```

## Authority model

During normal operation the primary database is authoritative. SQLite is a derived representation. A synchronization error creates lag; it must not redirect writes or make SQLite authoritative automatically.

## Components

- `SnapshotManagerInterface`: orchestrates a consistent full rebuild.
- `SourceSchemaIntrospectorInterface`: converts physical source schema to DBTNG's portable model.
- `ChangeCaptureInterface`: owns lifecycle of durable primary-side change capture.
- `SyncEngineInterface`: applies pending changes and reports synchronization state.
- `ReplicationPolicyInterface`: classifies tables/data for full or clean replication.
- `SnapshotPublisherInterface`: publishes a validated temporary SQLite artifact atomically.

## Source of truth

Physical schema is discovered from the database. Drupal metadata may enrich interpretation, especially for entity projection, but does not replace physical introspection.

## Rebuild publication

A rebuild writes to a temporary SQLite database, validates it, catches up captured changes, and only then replaces the published standby on the same local filesystem. Failure must leave the previous valid standby untouched.

## Future entity projection

`clean` revision filtering needs an entity-aware projection using Drupal entity storage/table mappings. It must understand base, data, revision, revision-data and dedicated field tables as a group.

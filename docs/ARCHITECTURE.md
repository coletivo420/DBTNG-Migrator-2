# Architecture

## Purpose

DBTNG Migrator 2 maintains two Drupal-compatible database representations with one explicit **primary** role and one **standby** role.

The database engine does not define authority; deployment configuration does.

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

## Bootstrap topology vs module configuration

Drupal must choose `$databases['default']['default']` before it can bootstrap far enough to read Drupal configuration. Therefore the active topology cannot be controlled solely by `dbtng_migrator.settings`.

Runtime topology belongs to deployment settings:

- `$databases['default']['default']`: active primary connection;
- `$databases['dbtng_standby']['default']`: standby connection;
- `$settings['dbtng_migrator']`: non-secret expected engine/connection role metadata.

The Config API stores behavior that can be loaded after bootstrap: replication profile, validation, batching and retention.

Future UI/Drush tooling may help an operator prepare a role change, but it must not pretend that changing a config value alone safely changes Drupal's bootstrap database.

## Authority model

- The configured primary is authoritative during normal operation.
- MariaDB/MySQL is the default primary.
- SQLite is a supported primary selection.
- No service may infer permanent authority from database engine.
- Standby failure creates lag/backlog; it does not automatically change authority.

## Topology model

The initial engine pair is:

- `mysql`: Drupal's MySQL-family driver, covering MariaDB/MySQL.
- `sqlite`: Drupal's SQLite driver.

Primary and standby must use different engines in the initial product.

## Components

- `DatabaseTopology`: immutable primary/standby engine and connection-key selection.
- `SnapshotManagerInterface`: orchestrates a consistent rebuild from the configured primary.
- `SourceSchemaIntrospectorInterface`: converts physical primary schema to DBTNG's portable model.
- `ChangeCaptureInterface`: owns engine-specific durable primary-side change capture.
- `SyncEngineInterface`: applies pending changes to the standby and reports synchronization state.
- `ReplicationPolicyInterface`: classifies standby data.
- `SnapshotPublisherInterface`: promotes a validated isolated standby candidate.
- `StandbyCandidate`: engine-neutral candidate/published destination identifiers.
- `ReplicationProfile`: typed set of currently implemented profiles.

## Source of truth

Physical schema is discovered from the configured primary database. Drupal metadata may enrich interpretation, especially for entity projection, but does not replace physical introspection.

## Rebuild publication

A rebuild never mutates the published standby in place.

For SQLite standby, the candidate is a temporary database file and publication can use same-filesystem atomic replacement.

For MariaDB/MySQL standby, the destination adapter must provide an isolated rebuild target and an explicit server-side promotion strategy with the same external guarantee: failure does not destroy the last known-good standby.

## Replication profile safety

`full` is the default profile and is valid in both initial directions.

`clean` is an opt-in SQLite-standby policy. It is never applied to the primary. Future `clean-public` remains unimplemented until entity-aware projection is proven.

## Development architecture

The canonical integration site is `bdtgn.toca.net.br`. Its deployment settings must exercise both role assignments without changing core module code. See [DEVELOPMENT_ENVIRONMENT.md](DEVELOPMENT_ENVIRONMENT.md).

## Future entity projection

Clean revision filtering needs an entity-aware projection using Drupal entity storage/table mappings. It must understand base, data, revision, revision-data and dedicated field tables as a group.

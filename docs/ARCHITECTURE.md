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
    +----> Import / Snapshot / Rebuild ------------------------------------^
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
    +----> Import / Snapshot / Rebuild -------------------------------------------^
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

## Core operations

### Import

`ImportManagerInterface` initializes a **proven-empty** standby from the configured primary.

It uses:

- `DestinationStateInspectorInterface` to reject populated targets;
- source schema introspection;
- portability analysis;
- destination schema construction;
- bounded-memory row transfer;
- validation.

Import supports both initial engine directions.

### Snapshot/Rebuild

`SnapshotManagerInterface` reconstructs an already initialized standby. It uses an isolated candidate and never destroys the last valid standby before replacement validation succeeds.

### Continuous synchronization

`ChangeCaptureInterface` plus `SyncEngineInterface` maintains an initialized standby after import/rebuild.

## Components

- `DatabaseTopology`: immutable primary/standby engine and connection-key selection.
- `ImportManagerInterface`: orchestrates first initialization into an empty destination.
- `DestinationStateInspectorInterface`: proves whether an import destination is empty.
- `SnapshotManagerInterface`: orchestrates a consistent rebuild.
- `SourceSchemaIntrospectorInterface`: converts physical primary schema to DBTNG's portable model.
- `ChangeCaptureInterface`: owns engine-specific durable primary-side change capture.
- `SyncEngineInterface`: applies pending changes to the standby and reports synchronization state.
- `ReplicationPolicyInterface`: classifies standby data.
- `SnapshotPublisherInterface`: promotes a validated isolated standby candidate.
- `StandbyCandidate`: engine-neutral candidate/published destination identifiers.
- `ReplicationProfile`: typed set of currently implemented profiles.

## Source of truth

Physical schema is discovered from the configured primary database. Drupal metadata may enrich interpretation, especially for entity projection, but does not replace physical introspection.

## Destination initialization safety

A first import and a rebuild are not the same operation.

- Import: destination must be empty and is initialized only after validation.
- Rebuild: destination is already initialized; work happens in an isolated candidate before promotion.

There is no initial merge or force-overwrite path.

For SQLite import/rebuild, temporary database files provide natural isolation.

For MariaDB/MySQL empty import, the destination adapter must track created state and define cleanup/retry semantics. Rebuild of an initialized MariaDB/MySQL standby requires a separate isolated candidate/promotion strategy.

## Replication profile safety

`full` is the default profile and is valid in both initial directions.

`clean` is an opt-in SQLite-standby policy. It is never applied to the primary. A destination intended for promotion to primary must be imported as `full`.

Future `clean-public` remains unimplemented until entity-aware projection is proven.

## Development architecture

The canonical integration site is `bdtgn.toca.net.br`. Its deployment settings must exercise both role assignments and empty-destination imports without changing core module code. See [DEVELOPMENT_ENVIRONMENT.md](DEVELOPMENT_ENVIRONMENT.md).

## Future entity projection

Clean revision filtering needs an entity-aware projection using Drupal entity storage/table mappings. It must understand base, data, revision, revision-data and dedicated field tables as a group.

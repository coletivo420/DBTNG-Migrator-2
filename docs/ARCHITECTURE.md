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

### Runtime resolution (Phase B)

The DatabaseTopologyResolver reads role keys and expected engines from deployment settings, obtains each connection explicitly through its connection factory, and verifies the actual driver/product/version. It rejects absent/mismatched roles and matching physical identities. It never changes Drupal's active connection. MySQL and MariaDB normalize to the MysqlFamily engine while retaining the detected product for native-tool selection.

The resolver's identity check is an operational guard, not a network-wide proof that two different DNS names cannot refer to the same database server. Configure unique database names for each role.

## Core operations

### Native backup

`NativeBackupManagerInterface` creates a same-engine native artifact from either configured role. The current Phase B implementation is CLI-only and writes private artifacts with format, size, role and SHA-256 metadata.

Initial native formats:

- MariaDB/MySQL: SQL dump, optionally gzip-compressed;
- SQLite: consistent SQLite database snapshot, optionally gzip-compressed.

Native restore is not implemented. When added, it must target only the matching standby engine. Native formats are never cross-engine migration formats.

Backup/download/restore behavior is upstream-first. See [UPSTREAM_COMPONENTS.md](UPSTREAM_COMPONENTS.md) and [BACKUP_RESTORE.md](BACKUP_RESTORE.md).

### Destination preparation

A non-empty standby no longer has only one possible outcome. `NonEmptyDestinationPolicy` defines:

- `abort` — no mutation;
- `backup_then_clear` — native safety backup, verify, then clear;
- `clear` — explicitly destructive clear.

`DestinationCleanerInterface` can operate only on the configured standby. Topology/identity checks must reject any attempt to clear the primary.

### Import

`ImportManagerInterface` logically imports the configured primary into a prepared standby. Empty standby destinations proceed directly; non-empty destinations require an explicit preparation policy.

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
- `NativeBackupManagerInterface`: creates native database artifacts for download/safekeeping.
- `NativeRestoreManagerInterface`: restores same-engine native artifacts to the standby.
- `DestinationCleanerInterface`: explicitly clears only the configured standby.
- `ImportManagerInterface`: orchestrates logical cross-engine initialization/replacement.
- `DestinationStateInspectorInterface`: classifies whether an import/restore destination contains state.
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

Import, native restore and rebuild are distinct operations. At the current phase, only topology resolution, inspection/preflight and native backup are operational.

- Import: logical cross-engine movement. Empty destination proceeds; populated standby requires explicit `abort`, `backup_then_clear` or `clear`.
- Native restore: same-engine artifact restore to standby with the same destination-preparation policies.
- Rebuild: destination is already initialized; work happens in an isolated candidate before promotion.

There is no merge path. "Clear" means remove existing standby state first, then create a fresh destination representation.

For SQLite backup/import/restore/rebuild, temporary database files provide natural isolation. Live SQLite backups must use the online backup API or another consistent SQLite snapshot mechanism rather than a raw main-file copy.

SQLite Online Backup API snapshots register Drupal's NOCASE_UTF8 collation on their SQLite handles before running integrity validation. Drupal-created indexes may use this custom collation; a plain SQLite3 handle otherwise cannot validate them.

For MariaDB/MySQL empty import, the destination adapter must track created state and define cleanup/retry semantics. Rebuild of an initialized MariaDB/MySQL standby requires a separate isolated candidate/promotion strategy.

## Replication profile safety

`full` is the default profile and is valid in both initial directions.

`clean` is an opt-in SQLite-standby policy. It is never applied to the primary. A destination intended for promotion to primary must be imported as `full`.

Future `clean-public` remains unimplemented until entity-aware projection is proven.

## Development architecture

The canonical integration site is `dbtng.toca.net.br`. Its deployment settings must exercise both role assignments and empty-destination imports without changing core module code. See [DEVELOPMENT_ENVIRONMENT.md](DEVELOPMENT_ENVIRONMENT.md).

## Future entity projection

Clean revision filtering needs an entity-aware projection using Drupal entity storage/table mappings. It must understand base, data, revision, revision-data and dedicated field tables as a group.

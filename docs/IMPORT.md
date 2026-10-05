# Empty-Destination Import

## Purpose

DBTNG Migrator 2 supports a one-time logical import from the configured primary database into an **empty** destination.

Initial supported directions:

```text
MariaDB/MySQL -> SQLite
SQLite        -> MariaDB/MySQL
```

This operation is the normal way to seed the first standby. It can also be used as the data-transfer stage of a controlled database-engine migration before a manual authority switch.

## Empty means empty

Import is intentionally conservative.

### SQLite destination

The destination is considered empty when:

- the database file does not yet exist; or
- it opens successfully and contains no user-defined schema objects.

SQLite internal objects such as names in the `sqlite_*` namespace are not by themselves application data, but any Drupal/application table, index, trigger or view makes the destination non-empty.

### MariaDB/MySQL destination

The target database/schema may already exist, but it must contain no user-defined application objects. Preflight must check at least:

- base tables;
- views;
- triggers;
- routines/functions/procedures;
- events where applicable.

If user-defined destination state exists, import fails before writing.

## No force/merge mode

The initial product has no destructive import switch.

The following are prohibited:

- drop destination and continue;
- truncate destination and continue;
- merge rows into an existing Drupal database;
- overwrite conflicts;
- reinterpret a populated destination as an empty standby.

A future merge/overwrite facility would require a separate data-loss and conflict-resolution design.

## Operation flow

```text
Resolve topology
      |
      v
Inspect source
      |
      v
Inspect destination state
      |
      +-- non-empty --> FAIL
      |
      v
Capture consistent source position/view
      |
      v
Portability analysis
      |
      v
Create destination schema
      |
      v
Bounded-memory row transfer
      |
      v
Apply standby profile
      |
      v
Validation
      |
      v
Catch up captured changes (when sync capture exists)
      |
      v
Mark destination initialized
```

The destination is not a usable standby until validation succeeds.

## Profiles

### MariaDB/MySQL primary -> SQLite destination

- `full`: supported and recommended for a general-purpose standby/migration target.
- `clean`: supported only when SQLite remains a standby representation.

### SQLite primary -> MariaDB/MySQL destination

- `full`: supported.
- `clean`: not supported initially.

Any destination intended to be promoted into the primary role must be initialized with `full`.

## Import vs rebuild

Import and rebuild reuse the same underlying logical migration components but differ in preconditions.

### Import

- destination must be empty;
- intended for first initialization;
- must refuse non-empty destination;
- no previous valid standby needs to exist.

### Rebuild

- standby may already be initialized;
- build an isolated replacement candidate;
- preserve the last known-good standby until the replacement validates.

## Import vs sync

Import transfers a complete initial database state. Continuous sync applies only after initialization and carries changes after the imported source position.

The module must never start incremental synchronization against an arbitrary empty or partially imported destination and assume it is valid.

## Planned CLI

CLI-first design:

```bash
drush dbtng:import
drush dbtng:import --profile=full
drush dbtng:import --profile=clean
```

The command uses the primary and standby connections resolved from deployment topology. It does not accept a force-overwrite option.

Before implementation is declared complete, `dbtng:import` must provide a machine-readable preflight/result mode and clear exit codes for:

- non-empty destination;
- portability rejection;
- source failure;
- destination failure;
- validation failure.

## Failure behavior

If import fails after writing begins:

- it must never be marked initialized;
- logs must identify the run/snapshot UUID;
- cleanup may remove only objects/artifacts created by that import run;
- cleanup must never touch the source;
- the destination adapter must leave an observable failed state if full cleanup cannot be guaranteed.

SQLite should normally be constructed in a temporary file and only published after validation.

MariaDB/MySQL destination implementation must explicitly track objects created by the run and define cleanup/retry behavior before beta.

## Development acceptance

On `bdtgn.toca.net.br`, integration tests must prove:

1. populated MariaDB/MySQL -> empty SQLite succeeds;
2. populated SQLite -> empty MariaDB/MySQL succeeds;
3. non-empty SQLite destination is rejected without mutation;
4. non-empty MariaDB/MySQL destination is rejected without mutation;
5. failed import is never reported as initialized;
6. `clean` works only for SQLite standby;
7. `full` imported destination can boot Drupal after controlled topology selection.

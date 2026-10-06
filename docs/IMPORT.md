# Logical Import and Destination Preparation

## Purpose

DBTNG Migrator 2 performs **logical cross-engine import** from the configured primary into the configured standby.

Initial supported directions:

```text
MariaDB/MySQL -> SQLite
SQLite        -> MariaDB/MySQL
```

This is the bootstrap path for creating the first standby and the data-transfer engine used for controlled cross-database migration.

Native database backup/restore is a separate same-engine operation. See [BACKUP_RESTORE.md](BACKUP_RESTORE.md).

## Destination state

Import always inspects the standby before writing.

### Empty destination

If the destination contains no user-defined application state, import can proceed directly.

For SQLite, an absent database file is also an empty destination.

For MariaDB/MySQL, the target database/schema may exist while still being empty.

### Populated destination

A populated standby requires an explicit `NonEmptyDestinationPolicy`:

- `abort` — default; do not mutate the destination.
- `backup_then_clear` — create and verify a native safety backup, clear the standby, confirm emptiness, then import.
- `clear` — explicitly clear the standby without a safety backup, confirm emptiness, then import.

There is no merge mode.

## What counts as non-empty

### SQLite destination

Treat the destination as non-empty when it contains user-defined application schema objects such as Drupal/application:

- tables;
- views;
- triggers;
- indexes/objects not solely internal to SQLite.

SQLite-internal `sqlite_*` objects are not by themselves Drupal application state.

### MariaDB/MySQL destination

Inventory at least:

- base tables;
- views;
- triggers;
- routines/functions/procedures;
- events where applicable.

The destination-state inspector must use physical database inventory, not just Drupal module metadata.

## Non-empty policies

### `abort`

Default and non-destructive.

```text
destination non-empty
        |
        v
      ABORT
```

No tables, files or other objects are changed.

### `backup_then_clear`

Recommended replacement workflow:

```text
destination non-empty
        |
        v
native safety backup
        |
        v
checksum + format/integrity verification
        |
        +-- failed --> ABORT, destination untouched
        |
        v
clear STANDBY
        |
        v
re-inventory: must be empty
        |
        v
logical import
        |
        v
validation
```

The safety backup must be completed and verified before the first destructive action.

### `clear`

Advanced destructive workflow:

```text
destination non-empty
        |
        v
explicit destructive confirmation
        |
        v
clear STANDBY
        |
        v
re-inventory: must be empty
        |
        v
logical import
```

There is deliberately no generic `--force` that silently converts `abort` into `clear`.

## Never clear primary

Destination preparation is scoped exclusively to the configured standby.

Before a destructive action DBTNG must prove that:

- the target role is standby;
- the target connection/database/file is distinct from the active primary;
- topology resolution is unambiguous.

If DBTNG cannot prove those properties, the destructive operation fails closed.

## Operation flow

```text
Resolve primary / standby topology
             |
             v
Inspect source schema
             |
             v
Inspect standby state
             |
      +------+-----------------------------+
      |                                    |
    empty                             non-empty
      |                                    |
      |                         +----------+----------+
      |                         |          |          |
      |                       abort   backup_then   clear
      |                         |        _clear       |
      |                         |          |          |
      |                       FAIL      backup        |
      |                                    |          |
      |                                  verify       |
      |                                    |          |
      |                                  clear <------+
      |                                    |
      +---------------------------< re-check empty
             |
             v
Open consistent source snapshot/view
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
Catch up captured changes
(when continuous capture is implemented)
             |
             v
Mark standby initialized
```

A destination is never considered usable merely because rows were copied. Validation is part of initialization.

## Replication profiles

### MariaDB/MySQL primary -> SQLite standby

- `full`: supported and recommended for a general standby or migration target.
- `clean`: supported when SQLite remains a standby representation.

### SQLite primary -> MariaDB/MySQL standby

- `full`: supported.
- `clean`: not supported initially.

Any imported destination that may later be promoted to primary must use `full`.

## Import vs native restore

### Logical import

- source and destination engines are different;
- DBTNG introspects/normalizes schema and streams logical row data;
- this is how MariaDB/MySQL and SQLite migrate between one another.

### Native restore

- backup format and destination engine match;
- MySQL-family SQL backup restores to MySQL-family standby;
- SQLite database snapshot restores to SQLite standby.

Do not translate a MySQL SQL dump into SQLite SQL as a substitute for DBTNG logical migration.

## Import vs rebuild

### Import

- initializes or deliberately replaces a standby;
- empty destination proceeds directly;
- populated destination requires explicit destination preparation;
- no merge semantics.

### Rebuild

- standby is already initialized;
- construct an isolated replacement candidate;
- preserve the previous known-good standby until replacement validation succeeds.

## Import vs continuous sync

Import establishes a complete base state. Incremental sync may begin only after that base state is validated and associated with a known source change position.

Never start incremental synchronization against an arbitrary empty, partially imported or failed destination.

## Planned CLI

```bash
drush dbtng:import
drush dbtng:import --profile=full
drush dbtng:import --profile=clean
drush dbtng:import --on-non-empty=abort
drush dbtng:import --on-non-empty=backup_then_clear
drush dbtng:import --on-non-empty=clear
```

`abort` is the default.

Destructive policies require the policy itself to be explicitly selected. A generic confirmation flag must not change the policy from `abort`.

Machine-readable preflight/results must expose clear exit states for:

- destination non-empty under `abort`;
- safety backup failure;
- destination clear failure;
- portability rejection;
- source failure;
- destination failure;
- validation failure.

## Failure behavior

If import fails after destination preparation begins:

- source remains untouched;
- the destination is not marked initialized;
- logs identify the run UUID;
- cleanup removes only state created by that run;
- a safety backup created by `backup_then_clear` is retained according to backup retention policy;
- incomplete cleanup is reported explicitly.

For SQLite, imports should normally build into a temporary file and publish it only after validation.

For MariaDB/MySQL, the destination adapter must track created state and define cleanup/retry behavior. A dedicated standby database may eventually use drop/recreate where permissions and policy explicitly allow it.

## Development acceptance

On `dbtng.toca.net.br`, integration tests must prove:

1. populated MariaDB/MySQL -> empty SQLite succeeds;
2. populated SQLite -> empty MariaDB/MySQL succeeds;
3. populated SQLite + `abort` fails without mutation;
4. populated MariaDB/MySQL + `abort` fails without mutation;
5. populated SQLite + `backup_then_clear` creates a verified native backup before replacement;
6. populated MariaDB/MySQL + `backup_then_clear` creates a verified native backup before replacement;
7. `clear` works only against the configured standby;
8. every destructive path refuses the active primary;
9. failed imports are never reported as initialized;
10. `clean` works only for SQLite standby;
11. a `full` imported destination can boot Drupal after controlled topology selection.

See [BACKUP_RESTORE.md](BACKUP_RESTORE.md) for native backup/restore semantics and [UPSTREAM_COMPONENTS.md](UPSTREAM_COMPONENTS.md) for implementation provenance.

## Phase C behavior and live evidence

`drush dbtng:import` implements `full` in both directions and `clean` only from MariaDB/MySQL to a SQLite standby. `--on-non-empty` defaults to `abort`; `backup_then_clear` verifies a private native safety artifact before preparation, while `clear` is explicit and does not create a safety artifact. Neither policy can target primary.

Imports use physical schema inventory, engine-specific logical schema builders, bounded row batches and post-transfer validation. The live Drupal 11.4.8 site validated both full directions, clean projection, row counts, SQLite integrity and foreign-key checks, followed by Drupal boot and representative CRUD after controlled promotion. A 1,200-row unknown-table fixture crossed multiple transfer batches and was preserved by both full and clean policies.

MariaDB -> SQLite portability warnings are evidence, not noise. The initial 343 warnings were retained and investigated rather than suppressed. Warning totals can vary after a round trip because physical schema changes, including MySQL index adaptation, affect the next inventory. Warnings describe conditional semantics or adapted non-unique indexes; strict blockers such as unrepresentable unique indexes remain errors. See [PORTABILITY.md](PORTABILITY.md).

MariaDB snapshots use a dedicated repeatable-read consistent transaction. Long-running snapshots can retain InnoDB undo history. SQLite snapshots use a stable read transaction and were exercised in WAL mode with a concurrent writer. Neither path uses `fetchAll()` for table transfer. Tables without a primary key remain importable but cannot support future key-based change capture.

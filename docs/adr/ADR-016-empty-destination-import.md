# Empty-destination bidirectional import

- Status: Accepted
- Date: 2026-10-05

## Context

A continuous standby needs an initial full state before incremental synchronization can begin. The project must also retain the original DBTNG Migrator capability of logically moving a Drupal database between supported engines.

The user requires imports in both initial directions whenever the destination is empty.

## Decision

DBTNG provides a first-class bootstrap import operation:

1. MariaDB/MySQL primary -> empty SQLite destination.
2. SQLite primary -> empty MariaDB/MySQL destination.

Import has a strict destination precondition: no user-defined destination schema/data may exist.

The initial product has no destructive force, merge, truncate or overwrite mode.

Import shares introspection, portability mapping, bounded-memory transfer and validation components with snapshot/rebuild, but remains a distinct orchestration operation because its safety precondition is different.

`full` is valid in both directions. `clean` is valid only for SQLite when it remains a standby. A destination intended for promotion into primary must be imported with `full`.

## Consequences

- Add an explicit destination-state inspector abstraction.
- Add a dedicated import request/orchestration contract.
- Non-empty destinations fail before migration writes begin.
- Sync cannot start until import validation marks the destination initialized.
- MariaDB/MySQL and SQLite destination adapters require integration tests for emptiness detection.
- Failed imports must be observable and must never masquerade as initialized standbys.
- Any future merge/overwrite functionality requires a separate ADR.

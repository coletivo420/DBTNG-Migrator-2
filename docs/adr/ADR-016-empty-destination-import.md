# Empty-destination bidirectional import

- Status: Accepted; destination-precondition scope extended by ADR-018
- Date: 2026-10-05

## Context

A continuous standby needs an initial full state before incremental synchronization can begin. The project must also retain the original DBTNG Migrator capability of logically moving a Drupal database between supported engines.

The original decision required the destination to be empty. That remains the safest and default bootstrap path, but operators also need an explicit way to replace a populated standby.

## Decision

DBTNG provides first-class bidirectional logical import:

1. MariaDB/MySQL primary -> SQLite standby.
2. SQLite primary -> MariaDB/MySQL standby.

For an empty destination, import proceeds directly.

ADR-018 extends destination preparation for a populated **standby** with explicit `abort`, `backup_then_clear` and `clear` policies. It does not introduce merge semantics and does not permit clearing the primary.

Import shares introspection, portability mapping, bounded-memory transfer and validation components with snapshot/rebuild, but remains a distinct orchestration operation.

`full` is valid in both directions. `clean` is valid only for SQLite when it remains a standby. A destination intended for promotion into primary must be imported with `full`.

## Consequences

- Keep explicit destination-state inspection.
- Keep a dedicated import request/orchestration contract.
- Empty destinations remain the direct bootstrap path.
- Populated destinations follow ADR-018 and never merge data.
- Sync cannot start until import validation marks the destination initialized.
- MariaDB/MySQL and SQLite adapters require integration tests for empty/non-empty detection and preparation.
- Failed imports must be observable and never masquerade as initialized standbys.

# Isolated standby rebuild and validated publication

- Status: Superseded in scope by ADR-013
- Date: 2026-10-05

## Original decision

The initial design assumed SQLite was always the standby, so rebuilds used a temporary SQLite database and atomic same-filesystem publication.

## Revised decision

The invariant remains: **never rebuild in place over the last known-good standby**.

- SQLite standby: use a unique temporary database file and atomic same-filesystem publication after validation.
- MariaDB/MySQL standby: use an isolated server-side candidate and an adapter-specific validated promotion strategy.

ADR-013 makes primary/standby roles engine-selectable and therefore generalizes the publication abstraction.

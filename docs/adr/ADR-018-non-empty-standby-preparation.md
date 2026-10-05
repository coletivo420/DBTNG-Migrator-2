# Explicit preparation of a non-empty standby

- Status: Accepted
- Date: 2026-10-05

## Context

The original import contract required an empty destination. Operators also need to reuse a previously populated standby or replace a test/stale destination.

Silently dropping destination state is unacceptable, but requiring manual database cleanup outside DBTNG prevents a complete, auditable workflow.

## Decision

A populated standby supports exactly three initial policies:

1. `abort` — default; no mutation.
2. `backup_then_clear` — create/verify a native safety backup, clear the standby, confirm emptiness, then continue.
3. `clear` — explicitly clear without safety backup, confirm emptiness, then continue.

These policies apply to logical import and same-engine native restore.

They never apply to the active primary.

There is no merge mode and no generic `--force` that changes `abort` into destructive behavior.

## Engine behavior

### SQLite standby

Prefer isolated file replacement. A live SQLite backup must use the SQLite Online Backup API or equivalent consistent mechanism. The active primary file can never be deleted/replaced by destination preparation.

### MariaDB/MySQL standby

Adapt Drush drop semantics for engine-safe table removal, extended by DBTNG physical inventory so views and other relevant user-defined objects are accounted for.

Where a dedicated standby database can safely be dropped/recreated and permissions allow it, an adapter may use that strategy only after proving that the database is the configured standby.

## Confirmation

The web UI presents a destructive warning and explicit choice.

CLI destructive execution requires the policy itself to be selected. Non-interactive confirmation does not imply permission to change the policy from `abort`.

## Consequences

- `ImportRequest` carries `NonEmptyDestinationPolicy`.
- native restore uses the same policy model.
- `DestinationCleanerInterface` operates only on the standby.
- `backup_then_clear` must fail closed if backup generation or verification fails.
- tests must prove the primary is never mutated by clear preparation.

# ADR-024: Failover readiness is read-only and fail-closed

- Status: Accepted
- Date: 2026-10-07

## Context

DBTNG now has durable capture, bounded/idempotent standby application, reconciliation and a continuous worker. A future manual authority transition needs one place to answer whether the standby's **data plane** is suitable for controlled promotion without turning a Drupal command into the authority-switch mechanism.

Drupal cannot prove that external writers are fenced, and the primary connection is selected before Config API is available.

## Decision

Phase G1 adds a read-only `FailoverReadinessChecker` and `dbtng:failover:check`.

The checker composes existing evidence:

- resolved runtime topology;
- current replication profile;
- primary capture installation/health/engine;
- exact pending event count;
- read-only reconciliation status;
- standby integrity;
- current published standby manifest;
- manifest profile, engine direction, schema fingerprint, lifecycle, activatable and full-fidelity flags.

It returns `READY` only when all database-side requirements pass.

A `READY` result explicitly does **not** verify:

- external application/write fencing;
- continuous-worker stop;
- deployment authority switch;
- capture rebinding on the future primary.

Those remain controlled operator/deployment actions.

CLEAN is always `NOT_READY`. Legacy or ambiguous manifests lacking explicit FULL/full-fidelity/published evidence fail closed and require a fresh FULL rebuild.

## Consequences

- no Drupal command can silently switch the active primary;
- the promotion gate is machine-readable and testable;
- fencing remains an external prerequisite and must be repeated immediately before authority switch;
- no automatic failback is introduced;
- after promotion, the old primary is not reused automatically; a fresh standby must be built from the new authority.

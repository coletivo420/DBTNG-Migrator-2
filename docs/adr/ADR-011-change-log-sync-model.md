# Durable change-log synchronization model

- Status: Accepted
- Date: 2026-10-05

## Context

DBTNG Migrator 2 needs explicit correctness guarantees across different database engines and operational failure modes.

## Decision

Continuous sync uses durable primary-side change capture plus an asynchronous, retryable SQLite applier instead of uncoordinated dual writes.

## Consequences

Implementations and tests must preserve this decision. A change that reverses it requires a new ADR and updates to `AGENTS.md` and affected operational documentation.

# Consistent source snapshots for rebuilds

- Status: Accepted
- Date: 2026-10-05

## Context

DBTNG Migrator 2 needs explicit correctness guarantees across different database engines and operational failure modes.

## Decision

Bulk rebuilds must read a single consistent source view using transactional/MVCC semantics supported by the source engine.

## Consequences

Implementations and tests must preserve this decision. A change that reverses it requires a new ADR and updates to `AGENTS.md` and affected operational documentation.

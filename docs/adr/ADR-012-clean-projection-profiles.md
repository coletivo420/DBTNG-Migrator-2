# Clean projection profiles preserve schema

- Status: Accepted
- Date: 2026-10-05

## Context

DBTNG Migrator 2 needs explicit correctness guarantees across different database engines and operational failure modes.

## Decision

Clean profiles may omit disposable data but retain required schema. Draft/revision pruning must use entity-aware projection rather than blind table exclusion.

## Consequences

Implementations and tests must preserve this decision. A change that reverses it requires a new ADR and updates to `AGENTS.md` and affected operational documentation.

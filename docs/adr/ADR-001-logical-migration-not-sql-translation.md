# Logical migration, not SQL translation

- Status: Accepted
- Date: 2026-10-05

## Context

DBTNG Migrator 2 needs explicit correctness guarantees across different database engines and operational failure modes.

## Decision

Use a normalized logical schema and row transfer through database abstractions rather than translating raw vendor SQL dumps.

## Consequences

Implementations and tests must preserve this decision. A change that reverses it requires a new ADR and updates to `AGENTS.md` and affected operational documentation.

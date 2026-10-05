# CLI-first operations

- Status: Accepted
- Date: 2026-10-05

## Context

DBTNG Migrator 2 needs explicit correctness guarantees across different database engines and operational failure modes.

## Decision

Long-running rebuild/sync/verification is designed for Drush/system services first, not HTTP request lifetimes.

## Consequences

Implementations and tests must preserve this decision. A change that reverses it requires a new ADR and updates to `AGENTS.md` and affected operational documentation.

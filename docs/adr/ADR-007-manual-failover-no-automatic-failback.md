# Manual failover and no automatic failback

- Status: Accepted
- Date: 2026-10-05

## Context

DBTNG Migrator 2 needs explicit correctness guarantees across different database engines and operational failure modes.

## Decision

Authority changes require fencing and operator control. Once SQLite receives writes, automatic return to the old primary is prohibited.

## Consequences

Implementations and tests must preserve this decision. A change that reverses it requires a new ADR and updates to `AGENTS.md` and affected operational documentation.

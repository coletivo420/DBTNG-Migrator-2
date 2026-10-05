# Temporary SQLite build and atomic publication

- Status: Accepted
- Date: 2026-10-05

## Context

DBTNG Migrator 2 needs explicit correctness guarantees across different database engines and operational failure modes.

## Decision

Build and validate a unique temporary database, then publish on the same local filesystem. The previous valid standby survives any pre-publication failure.

## Consequences

Implementations and tests must preserve this decision. A change that reverses it requires a new ADR and updates to `AGENTS.md` and affected operational documentation.

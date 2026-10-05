# Clean projection profiles preserve schema

- Status: Accepted
- Date: 2026-10-05

## Context

A clean standby intentionally omits selected volatile data. That is useful for a compact SQLite contingency database, but it is a lossy representation and therefore must not be confused with the safe default.

## Decision

- `full` is the default replication profile.
- `clean` is explicit opt-in and initially supported only for SQLite standby.
- Clean profiles omit selected standby **data** but retain schema required by Drupal.
- Unknown tables are copied unless explicitly classified otherwise.
- Draft/revision pruning must use entity-aware projection rather than blind table exclusion.
- Future `clean-public` is not a runtime profile until its entity semantics and tests are implemented.

## Consequences

New installations preserve all supported data unless the operator deliberately selects `clean`. Switching SQLite into the primary role never subjects authoritative data to a clean policy.

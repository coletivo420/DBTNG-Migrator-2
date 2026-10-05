# Durable change-log synchronization model

- Status: Accepted
- Date: 2026-10-05

## Context

Continuous synchronization spans two different database engines and cannot rely on independent dual writes.

## Decision

Continuous sync uses durable **primary-side** change capture plus an asynchronous, retryable standby applier.

The correctness contract is engine-neutral while capture mechanics are engine-specific:

- MariaDB/MySQL primary: transactional change log/outbox or equivalent.
- SQLite primary: transaction-coupled same-database change log or equivalent.

Raw SQL strings are not replayed across engines.

## Consequences

Every primary adapter must prove that a committed source change cannot vanish silently from the synchronization pipeline. The selected primary role, not a fixed engine, determines which capture adapter is active.

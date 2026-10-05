# Consistency Model

## Primary writes

The configured primary database is authoritative in normal mode. Continuous standby synchronization uses durable change capture associated with the primary transaction or equivalent atomic primary-side mechanism.

This avoids naive dual write:

```text
primary COMMIT succeeds
standby COMMIT fails
```

without a durable recovery record.

## Engine-specific capture

The correctness requirement is engine-neutral:

> A committed primary change must either be durably discoverable by the synchronization pipeline or surface an explicit failure.

The implementation is adapter-specific.

- MariaDB/MySQL primary: transactional change-log/outbox or equivalent mechanism coupled to the primary transaction.
- SQLite primary: same-database durable change capture or equivalent transaction-coupled mechanism.

The exact mechanism for each adapter must be proven by integration tests before production claims.

## Rebuilds

A rebuild must open a consistent source view before bulk copying.

- MariaDB/MySQL: use InnoDB/MVCC consistent read semantics on a dedicated source connection.
- SQLite: use a read transaction/snapshot strategy that provides a stable view while application writes continue where the selected journal mode permits it.

## Catch-up

A rebuild records a durable source sequence/position, copies from the stable read view, then applies changes after that position until it reaches current head before promotion.

## Lag

Lag is an explicit operational state. Standby synchronization failure does not invalidate the primary; it marks the standby stale until backlog application or rebuild restores parity.

## Primary selection

No consistency algorithm may assume MariaDB/MySQL is primary. Driver-specific guarantees are selected from `DatabaseTopology.primaryEngine`.

# Consistency Model

## Primary writes

The configured primary database is authoritative in normal mode. Continuous standby synchronization uses durable change capture associated with the primary transaction or an equivalent atomic primary-side mechanism.

This avoids naive dual write:

```text
primary COMMIT succeeds
standby COMMIT fails
```

without a durable recovery record.

## Engine-specific capture

Correctness requirement:

> A committed primary change must either be durably discoverable by the synchronization pipeline or surface an explicit failure.

Implementation is adapter-specific.

- MariaDB/MySQL primary: transactional change-log/outbox or equivalent mechanism coupled to the primary transaction.
- SQLite primary: same-database durable change capture or equivalent transaction-coupled mechanism.

The exact mechanism for each adapter must be proven by integration tests.

## Imports and rebuilds

Both first import and later rebuild must open a consistent source view before bulk copying.

Import adds one extra invariant: destination emptiness is checked before writes begin, and the destination is not considered initialized until validation succeeds.


- MariaDB/MySQL: InnoDB/MVCC consistent read semantics on a dedicated connection.
- SQLite: a read transaction/snapshot strategy that provides a stable view while writes continue when the selected journal mode permits it.

## Catch-up

A rebuild records a durable source sequence/position, copies from the stable read view, then applies changes after that position until it reaches current head before promotion.

## Lag

Lag is explicit operational state. Standby failure does not invalidate the primary; it marks the standby stale until catch-up or rebuild restores parity.

## Bootstrap selection

The current primary engine is selected by deployment database configuration before Drupal Config API loads. Sync/rebuild services consume the resolved role model; they must not attempt to switch the running request's global connection.

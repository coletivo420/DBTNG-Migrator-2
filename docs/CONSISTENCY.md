# Consistency Model

## Primary writes

MariaDB/MySQL remains authoritative in normal mode. Continuous standby synchronization will use durable change capture associated with the primary transaction. SQLite writes are performed asynchronously by the sync worker.

This avoids a naive dual-write sequence where the MariaDB commit succeeds and the SQLite commit fails with no durable recovery record.

## Rebuilds

A rebuild must open a consistent source read snapshot before bulk copying. In the initial MariaDB/MySQL implementation this is expected to use InnoDB/MVCC with `REPEATABLE READ` and a consistent snapshot on a dedicated source connection.

## Catch-up

A rebuild records a source change-log position, copies from the consistent read view, then applies changes after that position until it reaches the current head before publication.

## Lag

Lag is an explicit operational state. Synchronization failure does not invalidate MariaDB; it marks the standby stale until backlog application or rebuild restores parity.

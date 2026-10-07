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

Phase E1 establishes durable primary-side dirty events. Phase E2 implements bounded one-shot catch-up/application.

The one-shot sync engine does not treat the auto-increment capture ID as commit order. It consumes currently visible events, applies their authoritative current-state effect idempotently, and acknowledges exact IDs only after the standby transaction is durable. If ACK fails, committed standby effects are replayed safely. Snapshot/capture handoff semantics and final fencing remain necessary; E2 does not establish continuous zero-lag behavior.

## Lag

Lag is explicit operational state. Standby failure does not invalidate the primary; it marks the standby stale until catch-up or rebuild restores parity.

## Bootstrap selection

The current primary engine is selected by deployment database configuration before Drupal Config API loads. Sync/rebuild services consume the resolved role model; they must not attempt to switch the running request's global connection.

Phase C import pins a dedicated source connection for the duration of a stable read view. MariaDB/MySQL uses repeatable-read consistent snapshot semantics and SQLite a read transaction, including WAL mode. Destination rows are written in bounded batches. The result is not considered initialized until the physical schema, row counts and engine integrity checks pass and the private manifest is atomically written.
# Rebuild consistency before CDC

Rebuild uses an engine-native consistent read snapshot and bounded row transfer. Before publication, DBTNG takes a short source write fence and compares the candidate schema and streamed row-content digests against the source. Equal row counts are not sufficient. The fence is held only for final comparison and atomic publication, not for the full build. It proves parity at publication time; it does not prevent later drift after the fence is released. See ADR-021.


## Phase E1 transaction coupling

MariaDB/MySQL capture triggers write to an InnoDB log table in the originating transaction. SQLite capture triggers write to a table in the same SQLite transaction. A source rollback therefore rolls back its capture record as well.

This is stronger than request-end hooks and avoids a window where Drupal commits application data but crashes before recording the change.

TRUNCATE and DDL remain explicit exceptions to row-trigger coverage and must surface through operational discipline, schema fingerprinting and reconciliation until a dedicated mechanism is implemented.

## Continuous worker consistency

Phase F does not change E2 ordering guarantees. Each worker iteration delegates to `syncOnce()`, which commits standby effects before acknowledging exact primary event IDs. A process crash after standby commit but before acknowledgement remains replayable and idempotent.

Retry/backoff changes scheduling only; it does not change acknowledgement or transaction semantics. systemd restart after an abrupt process death is therefore expected to resume from the durable primary backlog.

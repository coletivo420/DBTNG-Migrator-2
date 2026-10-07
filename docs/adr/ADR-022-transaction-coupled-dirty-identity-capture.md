# ADR-022: Transaction-coupled dirty-identity change capture

- Status: Accepted
- Date: 2026-10-06

## Context

After a validated standby baseline exists, DBTNG needs a durable record of primary mutations so a later worker can retry when the standby is unavailable. Naive dual writes cannot provide cross-database atomicity, and request-end hooks can lose changes after the primary commits but before an out-of-transaction record is written.

Raw SQL replay is also unsuitable because the standby can use a different database engine and replication profile.

## Decision

Phase E1 installs engine-native row triggers on the selected primary.

Reserved objects:

- `dbtng_migrator_change_log`
- `dbtng_migrator_cdc_<table-hash>_i`
- `dbtng_migrator_cdc_<table-hash>_u`
- `dbtng_migrator_cdc_<table-hash>_d`

MariaDB/MySQL uses an InnoDB log table; SQLite uses a normal table in the same database. Trigger inserts participate in the same transaction as the application mutation.

Events record:

- durable event ID;
- table name;
- INSERT/UPDATE/DELETE operation;
- identity kind;
- current PK JSON when applicable;
- old PK JSON for UPDATE/DELETE when applicable;
- capture timestamp.

No raw SQL or full row image is stored.

A table is row-addressable only when its physical primary key exists and every key column has a conservatively supported scalar logical type. Otherwise the event marks the whole table dirty.

## Event IDs are not commit order

MySQL-family auto-increment values can be allocated before transaction commit. Concurrent transactions can therefore make event ID order differ from commit visibility order.

The event ID is only a durable identifier.

A future sync worker must acknowledge exact successfully applied event IDs. It must not advance a destructive scalar watermark such as “delete every event <= max seen ID”.

## Coverage boundary

Row triggers cover INSERT, UPDATE and DELETE.

They do not provide complete coverage for:

- MySQL/MariaDB TRUNCATE;
- DDL/schema changes;
- changes performed while capture is not installed.

Schema changes remain visible to physical inventory/fingerprint/reconciliation. The future operational layer must coordinate schema deployment and capture maintenance.

## Baseline

Installing capture is not itself a synchronized-state guarantee. Initial activation must be followed by a validated import/rebuild baseline before DBTNG can claim continuous standby semantics.

## Role transitions

Capture infrastructure belongs to a physical database while it is primary. Automatic capture rebind during failover is not implemented in E1. A future authority-transition protocol must fence writes, handle pending events and explicitly install/verify capture on the new primary.

## Consequences

- Normal physical inventory excludes the reserved capture table/triggers.
- Tables without safe row identity may cause coarser table reconciliation later.
- The future worker can be idempotent by re-reading authoritative current primary state instead of replaying source SQL.
- E1 is the durable primary-side capture layer. Phase E2 applies a bounded batch by re-reading authoritative current state and acknowledging exact event IDs only after a durable standby transaction. It does not replay SQL or use a scalar watermark.
- A failed ACK may cause idempotent replay; this is expected and safe. Sync remains one-shot, and no continuous lag SLA is claimed.
- A table-dirty event is reconciled by streaming the current table only when replacement is safe; otherwise sync blocks and requires rebuild.
- Profile, topology, or schema baseline drift blocks sync and requires a rebuild rather than incremental DDL.

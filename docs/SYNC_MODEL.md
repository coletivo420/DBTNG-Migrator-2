# Continuous Synchronization Model

## Role-based flow

```text
PRIMARY transaction
  +-- application changes
  +-- durable change record
             |
             v
         Sync Worker
             |
             v
        Policy Engine
             |
             v
          STANDBY
```

The primary engine may be MariaDB/MySQL or SQLite.

## Why not naive dual write

Two independent commits cannot provide atomicity across MariaDB/MySQL and SQLite. If the primary commits and the standby fails, the system needs a durable primary-side record from which to retry. Therefore synchronization is asynchronous but recoverable.

## Change capture adapters

`ChangeCaptureInterface` is role-oriented. Concrete adapters are engine-specific:

```text
ChangeCaptureInterface
  |-- MysqlFamilyChangeCapture
  +-- SqliteChangeCapture
```

The project must not implement a generic "replay the SQL string on the other engine" strategy. SQL dialects, transaction semantics and generated values differ.

## Policy application

The replication profile belongs to the **standby representation**.

- MariaDB/MySQL primary -> SQLite standby: `full` or `clean`.
- SQLite primary -> MariaDB/MySQL standby: initially `full`.

A clean policy must never remove data from the authoritative primary.

## Reconciliation

Continuous application is complemented by periodic full rebuild/reconciliation. Rebuild repairs drift, adapts to schema changes and validates projection rules.

## Authority transition

Failover changes roles operationally; it is not equivalent to changing a config value while both databases keep accepting writes. Fencing and recovery procedures remain mandatory.

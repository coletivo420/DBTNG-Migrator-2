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

Two independent commits cannot provide atomicity across MariaDB/MySQL and SQLite. If the primary commits and the standby fails, the system needs a durable primary-side record from which to retry.

## Change capture adapters

`ChangeCaptureInterface` is role-oriented. Concrete adapters are engine-specific:

```text
ChangeCaptureInterface
  |-- MysqlFamilyChangeCapture
  +-- SqliteChangeCapture
```

Do not replay raw SQL strings on the other engine.

## Policy application

The replication profile belongs to the **standby representation**.

- MariaDB/MySQL primary -> SQLite standby: `full` or opt-in `clean`.
- SQLite primary -> MariaDB/MySQL standby: `full`.

A clean policy must never remove data from the authoritative primary.

## Runtime topology

The sync engine receives a resolved `DatabaseTopology`. The actual default/standby connections are defined in deployment settings before Drupal bootstrap. Config API does not perform live role switching.

## Reconciliation

Continuous application is complemented by periodic full rebuild/reconciliation to repair drift, adapt to schema changes and validate projection rules.

## Authority transition

Failover changes roles operationally; it is not equivalent to changing a config value while both databases keep accepting writes. Fencing and controlled deployment changes are mandatory.

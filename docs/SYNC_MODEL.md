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
# Pre-CDC rebuild boundary

Snapshot/rebuild and read-only reconciliation exist before durable change capture. A source snapshot may become stale during its build; the final brief write fence and streamed content comparison either prove parity at publication or reject the candidate. After releasing that fence, new primary writes can create drift. No zero-lag or continuous synchronization claim is made until CDC and catch-up are implemented.


## Phase E1 durable capture

Phase E1 implements the primary-side half of the flow only.

Reserved primary objects:

```text
dbtng_migrator_change_log
dbtng_migrator_cdc_<table-hash>_{i,u,d}
```

Each application INSERT/UPDATE/DELETE writes a small durable dirty record in the same database transaction.

For safely addressable tables, the event stores primary-key JSON:

```text
insert -> key = NEW PK
update -> key = NEW PK, old_key = OLD PK
delete -> old_key = OLD PK
```

Tables without a supported primary key emit `identity_kind=table`; the future worker must reconcile the whole table rather than guess a row identity.

The log deliberately does **not** contain SQL statements or full row images. Cross-engine application will re-read authoritative current state from the primary.

### Event ordering

The numeric event ID is a durable identifier, not a transaction commit timestamp. On MySQL-family databases an auto-increment ID can be allocated before another transaction commits, so a later commit can expose a lower ID after a worker has already observed a higher one.

Therefore the future worker must:

- read currently visible pending events;
- apply idempotently;
- acknowledge exact event IDs after destination commit;
- never delete everything `<= max_seen_id` merely because a higher event was processed.

### E1 boundaries

- INSERT/UPDATE/DELETE: captured.
- PK updates: both old and new keys recorded.
- no-PK/unsupported PK: table-dirty fallback.
- TRUNCATE: not captured by MySQL row triggers.
- DDL/schema changes: not captured.
- standby application: not implemented.
- continuous lag SLA: not claimed.
- first capture installation still requires a subsequent validated rebuild/import baseline before synchronization can be claimed.

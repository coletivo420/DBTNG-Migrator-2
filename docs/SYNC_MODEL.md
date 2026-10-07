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

Snapshot/rebuild and read-only reconciliation exist before durable change capture. A source snapshot may become stale during its build; the final brief write fence and streamed content comparison either prove parity at publication or reject the candidate. After releasing that fence, new primary writes can create drift. E2 provides bounded one-shot catch-up after a baseline, but no zero-lag or continuous synchronization claim is made until the worker phase.


## Phase E1 durable capture

Phase E1 implements the primary-side durable capture half of the flow.

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

Tables without a supported primary key emit `identity_kind=table`; E2 reconciles that table in a bounded-memory transaction rather than guessing row identity. Tables with foreign-key relationships that make isolated replacement unsafe are blocked and require rebuild.

The log deliberately does **not** contain SQL statements or full row images. Cross-engine application will re-read authoritative current state from the primary.

### Event ordering

The numeric event ID is a durable identifier, not a transaction commit timestamp. On MySQL-family databases an auto-increment ID can be allocated before another transaction commits, so a later commit can expose a lower ID after a worker has already observed a higher one.

Therefore the worker must:

- read a bounded set of currently visible pending events;
- reduce duplicate identities and read current primary rows;
- apply idempotently;
- acknowledge exact event IDs only after destination commit;
- never delete everything `<= max_seen_id` merely because a higher event was processed.

## Phase E2 bounded sync once

`drush dbtng:sync --once --limit=500` performs exactly one batch (default 500; permitted range 1–5000). It does not poll or loop. Events are dirty markers, not row images: an insert/update looks up the current row on the primary and upserts it; a delete removes the key only when the current row is absent. Composite keys use physical primary-key order. Multiple events for the same identity collapse while retaining the exact event ID list.

Each batch applies in a standby transaction. After commit, DBTNG updates the private generation manifest, then acknowledges those exact IDs on the primary. If the process fails between commit and ACK, the same identities remain pending and replay idempotently. No scalar watermark is stored. Since incremental writes mutate a published SQLite generation, sync invalidates its original whole-file artifact checksum while retaining the generation/profile/schema identity; SQLite integrity and logical reconciliation remain the relevant checks afterward. Table-dirty identities stream and replace one table; currently, FK-linked tables are blocked when isolated replacement cannot be proven safe.

The engine blocks when capture is unhealthy, the baseline manifest/profile/topology differs, the primary schema fingerprint changed, or the standby schema no longer matches. Such changes require a rebuild. CLEAN schema-only events clear the corresponding standby table and can then be acknowledged; copied tables follow normal current-state application. CLEAN remains MariaDB/MySQL primary to SQLite standby only.

The primary can continue accepting writes while a one-shot batch runs. Events committed after the batch read remain pending for a later invocation. This is bounded catch-up, not a zero-lag guarantee. TRUNCATE and DDL still require reconciliation/rebuild.

### E1 boundaries

- INSERT/UPDATE/DELETE: captured.
- PK updates: both old and new keys recorded.
- no-PK/unsupported PK: table-dirty fallback.
- TRUNCATE: not captured by MySQL row triggers.
- DDL/schema changes: not captured.
- standby application: implemented as bounded one-shot sync.
- continuous worker/watch and lag SLA: not implemented or claimed.
- first capture installation still requires a subsequent validated rebuild/import baseline before synchronization can be claimed.

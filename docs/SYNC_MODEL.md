# Continuous Synchronization Model

## Desired flow

```text
MariaDB transaction
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
           SQLite
```

## Why not naive dual write

Two independent commits cannot provide atomicity across MariaDB and SQLite. If the primary commits and SQLite fails, the system needs a durable record from which to retry. Therefore synchronization is asynchronous but recoverable.

## Change capture

The exact MariaDB/MySQL capture mechanism will be finalized after the schema/introspection phase. The architecture permits a transactional outbox/change-log design and must satisfy one invariant: a committed source change cannot disappear from the synchronization pipeline without an observable error.

## Reconciliation

Continuous application is complemented by periodic full rebuild/reconciliation. Rebuild is the mechanism for repairing drift, adapting to schema changes and validating projection rules.

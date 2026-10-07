# ADR-023: Continuous sync worker and external supervision

- Status: Accepted
- Date: 2026-10-07

## Context

Phase E2 proved a bounded, idempotent `SyncEngine::syncOnce()` primitive. Continuous standby maintenance now requires repetition, retry/backoff, heartbeat and process supervision without duplicating synchronization semantics or turning Drupal requests into synchronous dual writes.

## Decision

`syncOnce()` remains the only database-changing incremental synchronization primitive.

Phase F adds `ContinuousSyncWorker`, invoked explicitly through:

```bash
drush dbtng:sync --watch
```

The worker:

- keeps a separate lifetime flock so only one watch process runs;
- leaves the existing shared `OperationLock` scoped to each `syncOnce()` batch;
- observes lightweight capture backlog while idle;
- periodically runs the more expensive full capture-health check;
- immediately drains additional bounded batches while backlog remains;
- uses typed transient exceptions for capped exponential backoff;
- uses typed blocked/rebuild-required conditions for slower operator-actionable retries;
- stores secret-free heartbeat/state atomically under private storage;
- supports graceful SIGINT/SIGTERM through Symfony Console signal handling.

systemd is the recommended supervisor on the canonical Debian/Virtualmin host. It is external to correctness: the worker status file and systemd state are not authority records.

## Operational age metric

`oldest_pending_age_seconds` is the wall-clock age of the oldest currently pending durable capture event. It is an operational backlog-age signal, not a precise replication-lag watermark or consistency SLA.

## Maintenance

Planned rebuild/import/restore/schema deployment/role-transition work should stop the external worker first. Per-batch operation locking remains a second safety boundary if an operation races with the worker.

## Consequences

- one-shot behavior remains the default CLI behavior;
- watch mode cannot create divergent application logic;
- duplicate watch workers fail immediately on the lifetime lock;
- abrupt death remains recoverable through E2 commit-before-exact-ACK and idempotent replay;
- failover and automatic role rebinding remain separate future work.

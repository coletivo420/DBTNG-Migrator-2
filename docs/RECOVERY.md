# Recovery

Recovery is defined by **current authority**, not by a permanently privileged database engine.

## Standby never became writable authority

Rebuild or catch up the standby from the configured primary.

## Standby became writable authority

After failover, treat the promoted standby as the current source of truth. Recovery creates a new validated secondary representation from that authority before any later authority switch.

Possible directions include:

```text
SQLite authority
    |
    v
new MariaDB/MySQL
    |
    v
logical migration + validation
    |
    v
controlled authority switch
```

and:

```text
MariaDB/MySQL authority
    |
    v
new SQLite
    |
    v
logical migration + validation
    |
    v
controlled authority switch
```

Automatic merge with an old divergent primary timeline is explicitly out of scope for the initial releases.

## Native recovery in Phase C

Native restore is a same-engine standby operation. `dbtng:restore` validates input before destructive preparation, applies the selected non-empty policy, restores through the matching native path, re-introspects and validates before recording initialized state. Cross-engine recovery uses `dbtng:import` and a logical schema mapping; SQL dumps are never translated across engines. Do not promote a `clean` SQLite projection because transient tables are intentionally empty.

## Bounded sync replay

`dbtng:sync --once` commits current-state effects on the standby before acknowledging exact primary event IDs. If the process ends after standby commit and before ACK, leave the events pending and run another bounded batch; upsert/delete/table reconciliation is designed to be replay-safe. Do not manually acknowledge events or delete a range by maximum ID. Profile, topology, or schema mismatch requires rebuild.
# Candidate rebuild recovery

`dbtng:rebuild` never clears the current standby first. An interrupted SQLite build leaves a `.partial` generation that can be removed after confirming no DBTNG lock is held. A MySQL-family build may leave tables in the reserved candidate namespace; the next locked rebuild removes stale candidates. The active canonical tables and `dbtng_migrator_snapshot_state` change in one atomic rename statement. Reconcile the standby after a crash before using it.

## Continuous worker recovery

The watch worker does not own authoritative state. If the process exits or is killed, durable capture events remain on the primary unless they were acknowledged after standby commit.

On restart, the worker resumes from the pending event set. A crash after standby commit but before acknowledgement intentionally replays already-applied effects; E2 idempotence makes that safe.

A stale/corrupt/missing worker status file affects observability only and must never cause event deletion or standby promotion.

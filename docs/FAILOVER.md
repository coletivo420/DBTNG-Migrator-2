# Failover

Failover is initially manual and role-based.

## G1 read-only readiness gate

Phase G1 adds:

```bash
drush dbtng:failover:check
drush dbtng:failover:check --format=json
```

This command **does not perform failover**. It combines existing topology, capture, reconciliation and manifest evidence and returns `READY` only when the current standby is a published FULL, activatable, full-fidelity generation with healthy capture, zero pending events, integrity PASS and reconciliation `MATCH`.

`READY` means **data-plane ready for the fenced promotion procedure**. It does not prove that application writes are fenced, that the continuous worker is stopped, or that deployment settings have switched. Those are external control-plane actions and remain mandatory.

Legacy/ambiguous manifests that do not explicitly prove `full_fidelity`, `activatable`, lifecycle `published`, engine direction and schema fingerprint are fail-closed and require a fresh FULL rebuild before promotion.

## Required sequence

1. Confirm the configured primary is genuinely unavailable or intentionally fenced.
2. Stop the continuous sync worker as a planned maintenance action.
3. Drain pending events and require `dbtng:failover:check` to become data-plane `READY`.
4. Fence the old primary from accepting application writes.
5. Re-run `dbtng:failover:check` under the fence and require `READY` again.
6. Promote/switch deployment configuration to the standby.
7. Restart/reload the PHP/application runtime as needed.
8. Explicitly verify/rebind durable capture on the new primary.
9. Build a fresh standby from the new authority.
10. Run Drupal smoke checks and only then resume the continuous worker.

The exact operational steps differ by topology.

### MariaDB/MySQL primary -> SQLite standby

The validated SQLite standby may be selected as the new Drupal primary after fencing MariaDB/MySQL.

### SQLite primary -> MariaDB/MySQL standby

The validated MariaDB/MySQL standby may be selected as the new Drupal primary after fencing SQLite/application access to the old authority.

## No automatic failback

Once the standby accepts production writes, it becomes the authoritative timeline. The recovered old database must not be placed back into service automatically.

Recovery requires a controlled logical migration from the current authority to a fresh/validated destination, regardless of which engine currently holds the primary role.

Split-brain prevention is more important than automatic availability.

## Before a future promotion while E2 is active

E2 does not perform authority transitions. Fence writes to the old primary, run bounded sync batches until the backlog is drained, run read-only reconciliation, stop any sync invoker, validate the candidate/profile, then change deployment settings and explicitly install/verify capture on the new primary. If capture health or reconciliation is not clean, do not promote. There is no automatic catch-up loop or failover in E2.
# Rebuild activation boundary

Only a validated `full` generation may be considered for a controlled authority switch. A `clean` SQLite generation is explicitly standby-only. Reconciliation reports current drift read-only and does not make a standby safe to promote by itself. There is no automatic failover or failback.

## Worker handling during authority transitions

Phase F does not automate failover. Before any future controlled promotion:

1. stop the continuous sync supervisor;
2. fence primary writes;
3. drain pending events with the existing sync primitive;
4. require reconciliation `MATCH`;
5. require a FULL promotable standby;
6. switch deployment authority;
7. explicitly verify/rebind capture on the new primary;
8. rebuild the new standby;
9. restart the continuous worker.

A CLEAN standby remains not promotable.


## Documented transition model

The current control plane defines the legal manual progression:

```text
NORMAL
  -> FENCED
  -> DRAINED
  -> RECONCILED
  -> READY_TO_PROMOTE
  -> AUTHORITY_SWITCHED
  -> CAPTURE_REBOUND
  -> NEW_STANDBY_REQUIRED
  -> HEALTHY
```

The model is intentionally not persisted or executed automatically in G1. It exists to reject unsafe conceptual shortcuts in code/tests and to define future G2 runbook/tooling boundaries.

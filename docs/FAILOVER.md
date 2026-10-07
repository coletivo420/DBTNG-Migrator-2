# Failover

Failover is initially manual and role-based.

## Required sequence

1. Confirm the configured primary is genuinely unavailable or intentionally fenced.
2. Inspect standby status, age, integrity and replication lag.
3. Fence the old primary from accepting application writes.
4. Promote/switch deployment configuration to the standby.
5. Restart/reload the PHP/application runtime as needed.
6. Run Drupal smoke checks.

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

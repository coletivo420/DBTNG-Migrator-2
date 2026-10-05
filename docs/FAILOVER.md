# Failover

Failover is initially manual.

## Required sequence

1. Confirm the primary is genuinely unavailable or intentionally fenced.
2. Inspect standby status, age, integrity and replication lag.
3. Fence MariaDB/MySQL from accepting application writes.
4. Switch deployment configuration to the SQLite standby.
5. Restart/reload the PHP/application runtime as needed.
6. Run Drupal smoke checks.

## No automatic failback

Once SQLite accepts production writes, it can become the authoritative timeline. A recovered old MariaDB instance must not be placed back into service automatically. Recovery requires a controlled SQLite-to-new-MariaDB migration/validation path.

Split-brain prevention is more important than automatic availability.

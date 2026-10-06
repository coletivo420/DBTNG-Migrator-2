# ADR-020: Same-schema staged table publication for MySQL-family standbys

- Status: Accepted for the initial rebuild implementation
- Date: 2026-10-06

## Context

The Virtualmin database credential has privileges scoped to the dedicated schema and does not have global `CREATE DATABASE`. A replacement standby must be built without dropping or truncating the published tables, and the Drupal standby connection name/schema must remain stable.

## Decision

Build source tables under the reserved `dbtngc<8-hex>_` prefix in the configured standby schema. After schema, data, integrity and reconciliation checks pass, use one multi-table `RENAME TABLE` statement to move existing published tables under `dbtngp<8-hex>_` and staged tables to their canonical physical names. Include `dbtng_migrator_snapshot_state` in the same statement so the active generation can be recovered after a process crash. The standby's configured database name does not change.

The implementation requires MariaDB 10.6.1+ or MySQL 8.0.13+ with transactional metadata behavior adequate for atomic multi-table rename. If this guarantee cannot be established, publication is refused. Only InnoDB source tables are accepted for a consistent logical rebuild. Reserved names are excluded from application inventories and must not be used by project tables.

## Consequences

- Candidate construction uses only the existing schema-scoped application credential; it does not require root, Virtualmin or global database privileges at runtime.
- The final name switch is one server statement. A failure before that statement leaves canonical tables unchanged; a server crash during it relies on the supported server's atomic DDL guarantees.
- Prior published tables are retained under the archive prefix. Retention and manual cleanup must only target complete DBTNG archive generations.
- Archived tables consume database space and may require an operator to provision additional quota.
- Foreign-key definitions must remain internally consistent through the multi-table rename; unsupported user-defined objects block rebuild.

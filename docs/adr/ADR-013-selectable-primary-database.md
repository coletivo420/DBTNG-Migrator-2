# Selectable primary database role

- Status: Accepted
- Date: 2026-10-05

## Context

The first bootstrap implicitly treated MariaDB/MySQL as permanently authoritative and SQLite as permanently secondary. The product requirement is broader: the operator must be able to select the primary database, with MariaDB/MySQL as the default but SQLite also supported.

Hard-coding authority to an engine would create regressions in change capture, rebuild, failover and recovery code.

## Decision

DBTNG models `primary` and `standby` as explicit roles independent of database engine.

Initial supported topologies:

1. MariaDB/MySQL primary -> SQLite standby (default).
2. SQLite primary -> MariaDB/MySQL standby (selectable).

Engine-specific behavior lives behind adapters.

The standby replication profile belongs to the destination representation. Initial `clean`/future `clean-public` projection is valid only when SQLite is standby. A clean policy is never applied to the authoritative primary.

## Consequences

- Configuration stores primary and standby engine plus Drupal connection key.
- Core code must not infer authority from `Connection::databaseType()`.
- MariaDB/MySQL and SQLite each need source introspection and durable change-capture implementations.
- Destination/rebuild publication cannot assume a filesystem SQLite artifact.
- Failover and recovery documentation must describe role transitions, not a one-way MariaDB-to-SQLite lifecycle.
- Tests must cover both topology directions and reject same-engine initial topology.

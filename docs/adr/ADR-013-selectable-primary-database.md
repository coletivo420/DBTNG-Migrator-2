# Selectable primary database role

- Status: Accepted
- Date: 2026-10-05

## Context

The first bootstrap implicitly treated MariaDB/MySQL as permanently authoritative and SQLite as permanently secondary. The product requirement is broader: the operator can select the primary database, with MariaDB/MySQL as default and SQLite also supported.

Hard-coding authority to an engine would create regressions in change capture, rebuild, failover and recovery.

## Decision

DBTNG models `primary` and `standby` as roles independent of database engine.

Initial supported topologies:

1. MariaDB/MySQL primary -> SQLite standby.
2. SQLite primary -> MariaDB/MySQL standby.

Engine-specific behavior lives behind adapters.

The standby replication profile belongs to the destination representation. `clean` is valid only when SQLite is standby and never applies to the authoritative primary.

Actual Drupal bootstrap connection selection is external deployment state; ADR-014 defines where that state lives.

## Consequences

- Core code must not infer permanent authority from `Connection::databaseType()`.
- MariaDB/MySQL and SQLite each need source introspection and durable change-capture implementations.
- Destination/rebuild publication cannot assume a filesystem SQLite artifact.
- Failover and recovery are role transitions, not a one-way MariaDB-to-SQLite lifecycle.
- Tests cover both topology directions and reject same-engine initial topology.

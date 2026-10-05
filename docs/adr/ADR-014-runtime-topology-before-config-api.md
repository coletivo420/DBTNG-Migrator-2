# Runtime topology is pre-bootstrap deployment configuration

- Status: Accepted
- Date: 2026-10-05

## Context

Drupal must connect to its default database before it can load configuration stored in that database. Therefore a Config API value cannot be the sole source of truth that decides which database Drupal should use as its primary.

Storing primary/standby engine selection only in `dbtng_migrator.settings` creates a bootstrap paradox and can leave configuration disagreeing with the connection actually serving the request.

## Decision

The deployment defines actual role connections before Drupal bootstrap:

- `$databases['default']['default']` is the current primary.
- `$databases['dbtng_standby']['default']` is the current standby.
- `$settings['dbtng_migrator']` may carry non-secret expected engine/connection metadata for validation.

Environment variables or generated deployment includes may select which engine is assigned to each role.

Drupal Config API stores post-bootstrap behavioral settings such as replication profile, validation, batching and retention.

An administration UI may help prepare/validate a role selection, but changing a Config API field alone must never be presented as a safe live primary switch.

## Consequences

- DBTNG preflight must compare expected topology with the actual connection drivers.
- Failover documentation includes deployment/fencing steps.
- Secrets remain in protected deployment configuration, not exported Drupal config.
- Development can exercise both topologies by changing deployment environment settings without modifying module code.

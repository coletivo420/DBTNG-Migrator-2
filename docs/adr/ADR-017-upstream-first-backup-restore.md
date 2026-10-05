# Upstream-first native backup and restore

- Status: Accepted
- Date: 2026-10-05

## Context

DBTNG needs database backup, browser download, upload/restore and database clearing in addition to cross-engine logical migration.

Drupal already has mature or established implementations covering parts of this problem:

- Backup and Migrate for MySQL backup/download/restore workflows;
- SQLite Backup for whole-SQLite-database backup/restore workflow;
- Drush for engine-specific SQL dump/import/drop operations;
- SQLite itself for online consistent backups.

Reimplementing all of these primitives independently would increase maintenance and security risk.

## Decision

DBTNG follows an upstream-first policy.

### MariaDB/MySQL native backup

Prefer the proven native-client behavior used by Drush:

- select MariaDB vs MySQL client appropriately;
- use `mariadb-dump` / `mysqldump`;
- use a consistent transaction where supported;
- protect credentials from process listings;
- stream output to a private file.

A PHP fallback may be adapted from current Backup and Migrate 5.1.x only after compatibility tests justify it.

### SQLite native backup

Use SQLite's Online Backup API through PHP `SQLite3::backup()` as the preferred consistent snapshot mechanism. `VACUUM INTO` is an acceptable alternate backend.

The SQLite Backup Drupal module is the workflow/provenance reference for whole-database backup and restore.

### Download/upload workflow

Adapt Backup and Migrate's proven backup-file/destination/streaming concepts, modernized for Drupal 11/Symfony responses. Large artifacts remain file/stream based.

### Database clearing

Adapt the driver-specific semantics proven by Drush `sql:drop` / `SqlBase`, with DBTNG inventory validation added so no user-defined destination objects are silently left behind.

### Runtime dependency policy

Do not require Backup and Migrate or SQLite Backup as enabled Drupal modules. Their engine assumptions differ from DBTNG's selectable-role architecture.

Drush is the preferred CLI integration but its internal command classes are not treated as stable web-runtime APIs.

Copied/adapted source must record exact provenance in `docs/UPSTREAM_COMPONENTS.md` and source comments.

## Consequences

- DBTNG owns a small role-aware adapter layer, not another full database dump framework.
- Existing upstream behavior and tests inform every adapter.
- Security fixes in upstream projects must be periodically reviewed.
- Same-engine native restore remains separate from DBTNG cross-engine logical import.

# Changelog

All notable changes to DBTNG Migrator 2 will be documented here.

## Unreleased

### Added
- Phase F.1 host-gate tooling: safe systemd unit renderer/validator, read-only canonical-host preflight/service checker, tooling self-tests, and CI validation of service wiring, config schema and Drush command metadata.
- Phase F continuous sync worker around the existing `syncOnce()` primitive, including `dbtng:sync --watch`, `dbtng:sync:status`, singleton worker locking, private atomic heartbeat/state, capped exponential backoff and oldest-pending-event age metrics.
- Versioned systemd service example for supervising the non-root watch worker without embedding credentials.
- Durable primary-side change-capture foundation for MariaDB/MySQL and SQLite using transaction-coupled row triggers and a reserved internal event log.
- `dbtng:capture:install` and `dbtng:capture:status` Drush commands; capture events use primary-key JSON when safely addressable and table-dirty fallback otherwise.
- `dbtng:sync --once` applies one bounded batch from current primary state to the standby, uses idempotent upsert/delete or table-dirty reconciliation, commits the standby before acknowledging exact event IDs, and leaves failures replayable.
- Candidate-based standby rebuild with SQLite generation publication, same-schema MySQL-family staging, pre-publication write fencing, read-only reconciliation and streamed row-content drift detection.
- Shared operation locking and injected rebuild failure points; versioned, secret-free generation manifests.
- Runtime topology resolver and `dbtng:doctor`, `dbtng:preflight` and `dbtng:backup` Drush commands.
- Physical MySQL-family and SQLite inventory, typed index/foreign-key/schema-object metadata, standby emptiness inspection and strict initial portability analysis.
- Native MariaDB/MySQL dump and online SQLite backup adapters with private artifacts, optional gzip and SHA-256 metadata.
- Unit coverage for both engine inventories, topology, portability, destination state, SQLite WAL snapshots and backup security/cleanup.
- Initial Drupal 11.4 project skeleton.
- Core contracts for snapshotting, change capture, replication policy and synchronization.
- Initial `full` and `clean` replication policies.
- Architecture and anti-regression documentation.
- First-class selectable primary database topology: MariaDB/MySQL by default, SQLite as an alternative primary.
- Engine/topology models and validation preventing clean projection from being applied to the authoritative primary.
- Dedicated Codex/Virtualmin development environment contract for `dbtng.toca.net.br`.
- PHP 8.5 CI coverage.
- First-class empty-destination bootstrap import requirement for MariaDB/MySQL -> SQLite and SQLite -> MariaDB/MySQL.
- Import contracts, destination-state model and non-empty destination exception.
- Native backup/download/restore contracts for MariaDB/MySQL and SQLite.
- Explicit non-empty destination policies: `abort`, `backup_then_clear`, and `clear`.
- Upstream component/provenance policy covering Backup and Migrate, SQLite Backup and Drush SQL tooling.

### Changed
- Continuous worker failures are classified by exception type rather than message parsing; baseline/profile/schema blocks remain operator-actionable while database/operation contention uses bounded retry.
- `dbtng:sync` remains one-shot by default; continuous behavior requires explicit `--watch`.
- Capture event IDs are durable identifiers rather than commit-order watermarks; sync acknowledges exact applied event IDs only after standby commit.
- Sync is bounded and one-shot only. Continuous worker/watch, automatic failover, and row-level coverage for TRUNCATE/DDL remain unimplemented.
- The 2026-10-07 canonical-host E2 validation passed both FULL directions, CLEAN policy, exact-ACK replay, standby outage recovery and a 1,200-event bounded backlog. Final SQLite standby is CLEAN, MATCH and explicitly not full-equivalent/promotable.
- CLEAN explicitly treats Drupal's `cachetags` invalidation table as schema-only, matching the `cache_*` cache projection.
- Renamed the canonical Virtualmin integration host to `dbtng.toca.net.br`, preserving the dedicated site's Unix account, home, database and document root.
- SQLite online snapshots register Drupal's `NOCASE_UTF8` collation before integrity checks so Drupal indexes validate correctly.
- Replication profile defaults to conservative `full`; `clean` is opt-in.
- Runtime topology selection is documented as pre-bootstrap deployment configuration rather than Drupal Config API state.
- Replication profiles are represented by a typed enum.
- Database inventory uses the typed database-engine model.
- Non-empty standby destinations may now be deliberately prepared for import/restore instead of being unconditionally rejected; `abort` remains the default.

# Changelog

All notable changes to DBTNG Migrator 2 will be documented here.

## Unreleased

### Added
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
- Phase status now reflects implemented topology/preflight/inventory/backup foundations; native restore, destination clearing, logical import and continuous synchronization remain unimplemented.
- Renamed the canonical Virtualmin integration host to `dbtng.toca.net.br`, preserving the dedicated site's Unix account, home, database and document root.
- SQLite online snapshots register Drupal's `NOCASE_UTF8` collation before integrity checks so Drupal indexes validate correctly.
- Replication profile defaults to conservative `full`; `clean` is opt-in.
- Runtime topology selection is documented as pre-bootstrap deployment configuration rather than Drupal Config API state.
- Replication profiles are represented by a typed enum.
- Database inventory uses the typed database-engine model.
- Non-empty standby destinations may now be deliberately prepared for import/restore instead of being unconditionally rejected; `abort` remains the default.

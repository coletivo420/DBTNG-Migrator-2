# Changelog

All notable changes to DBTNG Migrator 2 will be documented here.

## Unreleased

### Added
- Initial Drupal 11.4 project skeleton.
- Core contracts for snapshotting, change capture, replication policy and synchronization.
- Initial `full` and `clean` replication policies.
- Architecture and anti-regression documentation.
- First-class selectable primary database topology: MariaDB/MySQL by default, SQLite as an alternative primary.
- Engine/topology models and validation preventing clean projection from being applied to the authoritative primary.
- Dedicated Codex/Virtualmin development environment contract for `bdtgn.toca.net.br`.
- PHP 8.5 CI coverage.
- First-class empty-destination bootstrap import requirement for MariaDB/MySQL -> SQLite and SQLite -> MariaDB/MySQL.
- Import contracts, destination-state model and non-empty destination exception.

### Changed
- Replication profile defaults to conservative `full`; `clean` is opt-in.
- Runtime topology selection is documented as pre-bootstrap deployment configuration rather than Drupal Config API state.
- Replication profiles are represented by a typed enum.
- Database inventory uses the typed database-engine model.

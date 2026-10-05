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

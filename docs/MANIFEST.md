# Snapshot Manifest

The sidecar/metadata manifest is a versioned, non-secret contract describing a published standby state.

Minimum planned fields include:

- manifest format version;
- snapshot UUID and creation timestamp;
- DBTNG, Drupal and PHP versions;
- **primary database engine and connection role**;
- **standby database engine and connection role**;
- replication profile;
- captured and applied change-log positions;
- table and row totals;
- portability/activatability flags;
- validation results;
- standby artifact/state identifier;
- checksum where an artifact checksum is meaningful (for example SQLite file snapshots).

Credentials, passwords, full DSNs and application secrets are forbidden. Manifest changes require format-version compatibility tests.

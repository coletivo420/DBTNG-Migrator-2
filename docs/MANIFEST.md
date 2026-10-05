# Snapshot Manifest

The sidecar `standby.json` is a versioned, non-secret contract describing a published standby.

Minimum planned fields include:

- manifest format version;
- snapshot UUID and creation timestamp;
- DBTNG, Drupal and PHP versions;
- source and destination database families;
- replication profile;
- captured and applied change-log positions;
- table and row totals;
- portability/activatability flags;
- validation results;
- SQLite file size and SHA-256 checksum.

Credentials, passwords, full DSNs and application secrets are forbidden. Manifest changes require format-version compatibility tests.

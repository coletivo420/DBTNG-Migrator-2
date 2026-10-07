# Snapshot Manifest

The sidecar/metadata manifest is a versioned, non-secret contract describing a published standby state.

Generation manifests record:

- manifest format version;
- snapshot UUID and creation timestamp;
- DBTNG, Drupal and PHP versions;
- **primary database engine and connection role**;
- **standby database engine and connection role**;
- replication profile;
- rebuild UUID and source schema fingerprint;
- table and row totals;
- portability/activatability flags;
- validation results;
- candidate, published and previous generation identifiers;
- profile-aware validation and reconciliation status;
- intentional clean-profile exclusions and full-fidelity/activatable flags;
- batch, table, row, warning and memory metrics;
- checksum where an artifact checksum is meaningful (for example SQLite file snapshots).

Credentials, passwords, full DSNs and application secrets are forbidden. Manifest changes require format-version compatibility tests.

E2 sync requires the standby's published manifest to match the configured primary engine, standby engine, profile and source schema fingerprint. A mismatch is `REBUILD_REQUIRED`; sync does not rewrite the manifest as a substitute for rebuild. After durable sync application, the manifest records the last successful batch metadata. For SQLite, incremental writes invalidate the original whole-file `artifact_sha256` (stored as null); the generation ID and logical schema baseline remain unchanged. One-shot batches do not store a scalar applied event watermark.

## Worker state is separate from the standby manifest

Phase F's private `sync-status.json` is ephemeral operational telemetry. It is not a snapshot manifest, generation pointer, capture watermark or authority record. Deleting/corrupting it may make monitoring report `STALE`, but it must not change standby contents or acknowledge events.

Published generation manifests remain the baseline contract used by `SyncEngine` to reject profile/topology/schema drift.

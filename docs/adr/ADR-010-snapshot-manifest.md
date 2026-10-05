# Versioned standby manifest

- Status: Accepted
- Date: 2026-10-05

## Context

A published standby needs machine-readable provenance and validation metadata. SQLite has a file artifact; MariaDB/MySQL standby is server-side state, so not every destination has one artifact checksum with identical semantics.

## Decision

Every published standby has non-secret versioned metadata describing:

- snapshot/rebuild identity and generation time;
- primary and standby engines/roles;
- replication profile;
- source capture/application positions;
- portability and activatability;
- validation results;
- destination identifier;
- checksum/size when meaningful for the destination artifact, such as SQLite.

## Consequences

Credentials and full DSNs are forbidden in manifests. Validation code must not pretend that a MariaDB/MySQL standby has a SQLite-style file checksum.

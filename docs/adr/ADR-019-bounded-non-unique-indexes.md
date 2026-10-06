# Bounded non-unique indexes for InnoDB destinations

- Status: Accepted
- Date: 2026-10-06

## Context

SQLite can represent index definitions whose aggregate key size exceeds InnoDB's destination key-byte limit. MariaDB may reject those indexes during logical schema construction even when the indexed columns and rows are portable. Silently dropping the index would hide a potentially significant performance change, while applying a prefix to a unique index would change its uniqueness semantics.

## Decision

When building a MariaDB/MySQL destination schema, DBTNG may bound an oversized non-unique index to the supported InnoDB key-byte limit. It must retain the ordered index columns and report the adaptation as a portability warning. It must never shorten a unique index to make it fit. A unique index that cannot be represented faithfully is a strict blocker.

## Consequences

- Portability output preserves evidence of each adapted non-unique index.
- Import validation checks that the destination index exists, while the manifest and command result retain portability status and warnings.
- Warning totals can change after an engine round trip because the physical destination schema is different.
- Tests and documentation must distinguish non-unique index adaptation from unsupported unique-index semantics.

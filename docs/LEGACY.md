# Legacy DBTNG Migrator

The Drupal 7 DBTNG Migrator established the core idea this project keeps: reconstruct destination schema through Drupal's abstraction layer and copy logical rows instead of translating a vendor-specific SQL dump.

DBTNG Migrator 2 does **not** port the old procedural implementation verbatim.

## Legacy limitations addressed here

- **Enabled-module schema as primary truth:** modern entity tables and historical update changes mean runtime physical schema must be introspected.
- **Global active connection switching:** legacy code changed the active Drupal connection during destination work, allowing unrelated Drupal services/hooks to query an incomplete destination. DBTNG 2 uses explicit connections only.
- **OFFSET pagination:** concurrent data changes can shift offsets and create skips/duplicates. Rebuilds use a consistent source snapshot and bounded streaming.
- **Live-copy inconsistency:** tables copied at different moments could represent different database states. Rebuilds use a consistent InnoDB/MVCC read view.
- **Direct destination construction:** a failed job could leave an unusable target. Rebuilds are temporary and atomically published only after validation.
- **Weak success accounting:** individual failures must make the snapshot invalid; errors cannot be reported as migrated successes.

The legacy project is a design ancestor, not a compatibility API.

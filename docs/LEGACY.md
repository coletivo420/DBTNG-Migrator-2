# Legacy DBTNG Migrator

The Drupal 7 DBTNG Migrator established the core idea this project keeps: reconstruct destination schema through Drupal's abstraction layer and copy logical rows instead of translating a vendor-specific SQL dump.

DBTNG Migrator 2 does **not** port the old procedural implementation verbatim.

## Legacy limitations addressed here

- **Enabled-module schema as primary truth:** runtime physical schema must be introspected.
- **Global active connection switching:** legacy code changed the active Drupal connection during destination work, allowing unrelated Drupal services/hooks to query an incomplete destination. DBTNG 2 uses explicit connections.
- **OFFSET pagination:** concurrent data changes can shift offsets and create skips/duplicates. DBTNG 2 uses bounded streaming from a stable source view.
- **Live-copy inconsistency:** tables copied at different moments could represent different states. Rebuilds use an engine-appropriate consistent source snapshot.
- **Direct destination construction:** a failed job could leave an unusable target. DBTNG 2 builds an isolated candidate and promotes it only after validation.
- **Weak success accounting:** individual failures must invalidate the run; errors cannot be reported as migration success.
- **One-way assumptions:** DBTNG 2 treats primary/standby as roles so MariaDB/MySQL and SQLite can exchange authority through controlled procedures.

The legacy project is a design ancestor, not a compatibility API.

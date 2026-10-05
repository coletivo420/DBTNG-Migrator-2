# Snapshot Format

A published standby consists of:

- `standby.sqlite` — validated SQLite database.
- `standby.json` — sidecar manifest.

The manifest format is versioned independently from the module and must not contain database passwords, full DSNs or secrets.

Planned manifest fields include snapshot id, timestamps, DBTNG/Drupal/PHP versions, source/destination database families, policy profile, captured/applied sequence positions, table/row statistics, validation results, portability flags, file size and checksum.

Temporary rebuild artifacts use a unique `.partial` name and are never treated as valid standby databases.

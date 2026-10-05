# Snapshot / Standby State Format

A validated standby is engine-specific.

## SQLite standby

A published SQLite standby consists of:

- `standby.sqlite` — validated SQLite database.
- `standby.json` — sidecar manifest.

Temporary rebuild artifacts use unique `.partial` names and are never treated as valid standby databases.

## MariaDB/MySQL standby

A MariaDB/MySQL standby is server-side state rather than a single portable file. Its destination adapter must create an isolated candidate database/schema (or equivalent safe staging target), validate it, and promote it without destroying the last known-good standby before success.

## Common manifest

The manifest format is versioned independently from the module and records the configured primary/standby engines and roles. It must not contain database passwords, full DSNs or secrets.

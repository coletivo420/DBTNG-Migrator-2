# Snapshot / Standby State Format

A validated standby is engine-specific.

## SQLite standby

A published SQLite standby consists of:

- the configured stable SQLite path — a symlink to the active immutable generation;
- `generations/<uuid>/dbtng.sqlite` — validated database with mode `0600`;
- a private versioned manifest outside the webroot.

Temporary rebuild artifacts live in `<uuid>.partial` directories with mode `0700` and are never treated as published standby databases. The pointer switches with a same-filesystem atomic rename. The previous generation remains available.

## MariaDB/MySQL standby

A MariaDB/MySQL standby is server-side state rather than a single portable file. DBTNG builds isolated staging tables in the configured schema and promotes them with one multi-table atomic rename. `dbtng_migrator_snapshot_state` records the active generation. See ADR-020.

## Common manifest

The manifest format is versioned independently from the module and records the configured primary/standby engines and roles. It must not contain database passwords, full DSNs or secrets.

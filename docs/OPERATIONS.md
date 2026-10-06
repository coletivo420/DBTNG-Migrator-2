# Operations

Long-running work is CLI/service oriented. The planned production model is a site-local Drush command supervised by systemd (or cron for one-shot operations), not a PHP-FPM/HTTP request.

Operators must be able to observe:

- configured and actually resolved primary/standby engines;
- destination initialization state;
- last native backup/download/restore result;
- most recent safety backup created before a destructive clear;
- current published standby and age;
- captured/applied change-log sequence;
- backlog/lag;
- last successful import/rebuild validation;
- current replication profile;
- last import/rebuild result.

## Initial bootstrap import

A configured standby starts uninitialized.

The import operation:

```bash
drush dbtng:import
```

performs a full logical import only after proving the standby destination is empty.

Import supports:

- MariaDB/MySQL primary -> empty SQLite standby;
- SQLite primary -> empty MariaDB/MySQL standby.

A non-empty destination defaults to `abort`. Operators may explicitly choose `backup_then_clear` or `clear`; neither mode merges data, and both are restricted to the standby.

After successful validation, the destination can be marked initialized and continuous sync may begin from the corresponding source change position.

## Native backup and rebuild

Available backup commands:

```bash
drush dbtng:backup --role=primary
drush dbtng:backup --role=standby
```

`dbtng:doctor` reports resolved roles, engines, versions and tool availability without printing credentials. `dbtng:preflight` performs read-only connection checks, physical inventories, destination-state inspection and strict portability analysis for an initial import; a populated standby is expected to be BLOCKED there. `dbtng:backup` is read-only and may target either role. It stores output below Drupal's configured private path by default.

The 2026-10-06 Phase B live test on `dbtng.toca.net.br` created MariaDB and SQLite backups in both role assignments. Preflight returned BLOCKED because the target was a separate populated Drupal install, not an empty initial-import destination. SQLite snapshots passed an integrity check with Drupal's custom collation registered, including a live WAL write test. Phase D later rebuilt and reconciled isolated candidates in both directions.

Restore targets the configured standby and accepts only its engine's native backup format. Use logical `dbtng:import` for cross-engine transfer.

`dbtng:rebuild --profile=full|clean` constructs an isolated candidate and leaves the currently published standby untouched until validation, reconciliation and the final write fence pass. `dbtng:reconcile` is read-only. A `clean` result is standby-only and must not be promoted as a full copy. The pre-CDC write fence is brief but can grow with the final streamed content comparison; publication proves parity at the fence, not ongoing zero lag.

## Topology source

Actual Drupal bootstrap connection selection is deployment configuration in `settings.php` / environment-backed settings. DBTNG must detect and report a mismatch between expected topology metadata and the actual connection drivers.

Changing the primary is an operational authority transition, not a normal Config API edit.

## Schema deployments

Schema-changing deployments (`composer` updates, module install/uninstall and `drush updb`) must be coordinated with standby reconciliation. DBTNG serializes its own import, restore, rebuild and reconciliation operations with a private `flock`; deployment tooling must still avoid concurrent Drupal schema changes during the final rebuild fence.

## Development vs production

`dbtng.toca.net.br` is explicitly disposable integration infrastructure. Permission to reset it does not imply permission to reset or reconfigure any production Virtualmin site.

Phase C commands are available through Drush: `dbtng:doctor`, `dbtng:preflight`, `dbtng:backup`, `dbtng:restore` and `dbtng:import`. The import/restore target is always the configured standby. Use `abort` unless a verified safety backup followed by standby clearing is intended. The live environment is left with MariaDB primary and SQLite standby; the latest SQLite standby is the documented `clean` projection and must not be promoted as a full-equivalent copy.

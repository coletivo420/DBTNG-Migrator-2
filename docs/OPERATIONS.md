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

The planned operation:

```bash
drush dbtng:import
```

performs a full logical import only after proving the standby destination is empty.

Import supports:

- MariaDB/MySQL primary -> empty SQLite standby;
- SQLite primary -> empty MariaDB/MySQL standby.

A non-empty destination defaults to `abort`. Operators may explicitly choose `backup_then_clear` or `clear`; neither mode merges data, and both are restricted to the standby.

After successful validation, the destination can be marked initialized and continuous sync may begin from the corresponding source change position.

## Native backup (Phase B)

Available backup commands:

```bash
drush dbtng:backup --role=primary
drush dbtng:backup --role=standby
```

`dbtng:doctor` reports resolved roles, engines, versions and tool availability without printing credentials. `dbtng:preflight` performs read-only connection checks, physical inventories, destination-state inspection and strict portability analysis. `dbtng:backup` is read-only and may target either role. It stores output below Drupal's configured private path by default.

Restore targets the configured standby and accepts only its engine's native backup format. This command is planned but not implemented. Use logical `dbtng:import` for cross-engine transfer once available.

Destination preparation and `abort | backup_then_clear | clear` policies are not operational yet; no clearing or restore command is available.

## Topology source

Actual Drupal bootstrap connection selection is deployment configuration in `settings.php` / environment-backed settings. DBTNG must detect and report a mismatch between expected topology metadata and the actual connection drivers.

Changing the primary is an operational authority transition, not a normal Config API edit.

## Schema deployments

Schema-changing deployments (`composer` updates, module install/uninstall and `drush updb`) must be coordinated with standby reconciliation. The exact locking/rebuild policy will be implemented before beta.

## Development vs production

`dbtng.toca.net.br` is explicitly disposable integration infrastructure. Permission to reset it does not imply permission to reset or reconfigure any production Virtualmin site.

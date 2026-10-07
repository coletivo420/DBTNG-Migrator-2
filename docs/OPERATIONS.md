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

After successful validation, the destination has a durable baseline. With E1/E2 capture enabled, a bounded `dbtng:sync --once` batch can catch up changes; continuous sync is not yet provided.

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


## Durable capture and bounded sync (Phases E1/E2)

Code-level commands:

```bash
drush dbtng:capture:install
drush dbtng:capture:status
drush dbtng:sync --once
drush dbtng:sync --once --limit=500
```

`capture:install` installs the reserved change log and row triggers on the **currently selected primary**. It does not modify the standby and does not establish a synchronization baseline by itself.

`capture:status` reports installed/expected trigger counts, tracked tables, table-dirty fallbacks and pending event count without acknowledging anything.

`dbtng:sync --once` applies at most one bounded event batch. Repeat invocations to drain a larger backlog. Events are reduced to dirty identities, current rows are read again from the primary, and the standby transaction commits before exact event IDs are acknowledged. The command never replays captured SQL and never acknowledges by a maximum-ID watermark. If it fails after standby commit but before ACK, a later invocation safely reapplies the same current state.

Sync blocks on unhealthy capture, profile/topology/schema mismatch, or incompatible standby schema and requires a rebuild. No polling/watch mode, daemon, continuous lag SLA, or automatic role transition exists. MySQL-family TRUNCATE and DDL remain outside row-trigger capture.

One-shot sync can return `MORE_PENDING`; invoke it again to process the next bounded batch. Its final `pending` value is observed on the primary before later writes and is not a lag SLA. A successful batch updates the private manifest after standby commit and before ACK. SQLite's original full-file generation checksum is invalidated by incremental writes; integrity and logical reconciliation remain the post-sync checks.

## Continuous sync worker

Manual modes:

```bash
drush dbtng:sync
drush dbtng:sync --once
drush dbtng:sync --watch
drush dbtng:sync:status
drush dbtng:sync:status --format=json
```

No option means one bounded batch, preserving E2 behavior. `--watch` is explicit and mutually exclusive with `--once`.

Default worker settings:

```yaml
sync:
  batch_events: 500
  poll_seconds: 1
  health_check_seconds: 30
  backoff_initial_seconds: 1
  backoff_max_seconds: 30
  blocked_retry_seconds: 60
  heartbeat_stale_seconds: 120
  lag_warning_seconds: 60
```

`lag_warning_seconds` compares against the **oldest pending event age**, not an exact replication-lag SLA.

For planned rebuild/import/restore/schema deployment or role transition, stop the external worker first, perform/validate the operation, then start the worker again. The per-batch DBTNG operation lock remains a second safety layer.

A systemd reference unit is provided at `docs/examples/dbtng-migrator-sync.service.example`. Render its placeholders for the actual Virtualmin user/group, Drupal root and Drush binary; never embed database credentials.

### Signal prerequisite

Graceful `systemctl stop` behavior requires the CLI PHP running Drush to expose PCNTL/SIGTERM/SIGINT. Check `drush dbtng:doctor` before enabling the continuous worker. If signal support is unavailable, keep the service disabled until the CLI PHP environment is corrected.

### Phase F.1 unit rendering and validation

Do not hand-edit the reference unit into place. Render it as the domain user into a non-system temporary path, validate it, then use privileged `install` only for the already validated artifact:

```bash
scripts/render-systemd-unit.sh \
  --user bdtgn \
  --group <discovered-domain-group> \
  --project-root /home/bdtgn/apps/dbtng-site \
  --drush /home/bdtgn/apps/dbtng-site/vendor/bin/drush \
  --output /tmp/dbtng-migrator-sync.service

scripts/validate-systemd-unit.sh \
  --require-systemd-analyze \
  /tmp/dbtng-migrator-sync.service
```

The renderer is scoped to the canonical `https://dbtng.toca.net.br/` URI and refuses `User=root`. The validator rejects unresolved placeholders, root execution, shell/sudo wrappers, `--once`, obvious credential-bearing environment directives and credential-bearing DSN/URI forms.

`scripts/validate-phase-f-host.sh` is deliberately read-only and should be used before and after service installation. It never starts/stops/kills a service, changes SQLite permissions, mutates Drupal content or acknowledges capture events.

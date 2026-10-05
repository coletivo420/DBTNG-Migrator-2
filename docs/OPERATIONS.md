# Operations

Long-running work is CLI/service oriented. The planned production model is a site-local Drush command supervised by systemd (or cron for one-shot operations), not a PHP-FPM/HTTP request.

Operators must be able to observe:

- current published standby and age;
- captured/applied change-log sequence;
- backlog/lag;
- last successful validation;
- current replication profile;
- last rebuild result.

Schema-changing deployments (`composer` updates, module install/uninstall and `drush updb`) must be coordinated with standby reconciliation. The exact locking/rebuild policy will be implemented before beta.

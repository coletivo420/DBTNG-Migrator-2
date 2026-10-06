# Development

## Baseline

- PHP 8.3+; CI covers 8.3, 8.4 and 8.5.
- Drupal ^11.4.
- Drush ^13.7 or ^14.
- MariaDB/MySQL and SQLite available for integration tests.

Run:

```bash
composer install
composer check
```

Phase B adds `dbtng:doctor`, `dbtng:preflight` and `dbtng:backup`, plus physical inventory, destination state and portability services. The unit tests include SQLite WAL and mocked MySQL catalog coverage; only a successful run as the domain owner on the canonical server counts as live-site integration evidence.

For a Composer path repository installed as a symlink, the development site's root `drush.yml` explicitly lists the module's three Symfony command classes under `drush.commands`. Drush's module command-file finder does not follow the symlinked path in this deployment. Keep that host-specific registration outside the module Git checkout; normal Composer installs with a materialized package use the standard `src/Drush/Commands` discovery.

## Canonical integration environment

The live integration environment is `dbtng.toca.net.br`, managed through Virtualmin.

See [DEVELOPMENT_ENVIRONMENT.md](DEVELOPMENT_ENVIRONMENT.md) before provisioning or modifying it. The repository's [AGENTS.md](../AGENTS.md) explicitly defines Codex's administrative scope.

## Architecture discipline

Core logic must use dependency injection and explicit database connections. Avoid global connection switching and service-locator calls inside the synchronization engine.

Runtime primary selection cannot depend solely on Drupal Config API because Drupal needs its default database connection before configuration can be read. Connection topology belongs in deployment settings; DBTNG behavior/policy belongs in module config.

For the Phase B runtime resolver, deployment settings provide `primary_connection_key`, `standby_connection_key`, `primary_engine` and `standby_engine` under `$settings['dbtng_migrator']`. A dual-role example is maintained in `examples/settings.dbtng.php.example`; keep its populated copy outside version control.

## Composer development layout

Use a separate Drupal recommended-project and this repository as a Composer path repository. Do not copy edited module files manually into a second unmanaged tree.

A typical layout under the Virtualmin domain home is:

```text
<home>/
├── apps/
│   └── dbtng-site/
│       ├── composer.json
│       └── web/
├── src/
│   └── DBTNG-Migrator-2/
└── private/
    └── dbtng/
```

The path repository should symlink the module into the Drupal installation during development.

## Documentation rule

If a code change alters behavior, guarantees, compatibility, topology, clean-profile policy, schema mapping, failover, recovery or development provisioning, update the relevant documentation/ADR in the same commit.

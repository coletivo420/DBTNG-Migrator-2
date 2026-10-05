# Development

## Baseline

- PHP >= 8.3
- Drupal ^11.4
- Drush ^13.7 or ^14 for planned CLI integration

Run:

```bash
composer install
composer check
```

## Architecture discipline

Read `AGENTS.md` before implementation. Core logic must use dependency injection and explicit database connections. Avoid global connection switching and service-locator calls inside the synchronization engine.

## Documentation rule

If a code change alters behavior, guarantees, compatibility, clean-profile policy, schema mapping, failover or recovery semantics, update the relevant documentation/ADR in the same commit.

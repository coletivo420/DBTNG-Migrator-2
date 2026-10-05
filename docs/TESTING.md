# Testing Strategy

## Unit tests

Cover type/policy mapping, schema normalization, manifest behavior, batch sizing, path validation, publication state and exception mapping.

## Integration matrix

Initial target matrix:

- MariaDB 10.6+ -> SQLite
- MySQL 8.0+ -> SQLite
- PHP 8.3, 8.4 and 8.5 as compatible with the target Drupal release

## Fixtures

Include empty tables, no-PK tables, simple/composite keys, unique/index combinations, NULL/empty/zero distinctions, signed/unsigned numbers, numeric precision, text/blob, zero bytes, multilingual UTF-8/emoji and large datasets. Add explicit incompatible fixtures for enum/set/json-native semantics, generated columns, fulltext, spatial objects, views and triggers.

## Failure injection

Before beta, prove that the previous published standby survives interruption during schema creation, mid-table transfer, validation and immediately before publication. Cover disk-full, permission failure, source disconnect, stale `.partial` files and concurrent run locking.

## Application smoke test

Database parity is not enough. CI must eventually boot Drupal against the generated SQLite standby and exercise `drush status`, cache rebuild and representative HTTP/functional requests.

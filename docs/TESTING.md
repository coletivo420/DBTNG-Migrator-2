# Testing Strategy

## Test layers

### Unit

Cover:

- database topology validation;
- replication-profile typing and topology compatibility;
- import request validation;
- destination empty/non-empty classification;
- type/schema mapping;
- policy classification;
- manifest behavior;
- bounded batch sizing;
- path/candidate validation;
- publication state;
- exception mapping.

### CI compatibility

GitHub Actions covers PHP 8.3, 8.4 and 8.5 against Drupal ^11.4 dependencies.

### Integration engine matrix

Required directions:

- MariaDB 10.6+ -> SQLite 3.45+
- MySQL 8.0+ -> SQLite 3.45+
- SQLite 3.45+ -> MariaDB 10.6+
- SQLite 3.45+ -> MySQL 8.0+

The canonical live integration host is `bdtgn.toca.net.br`.

## Empty-destination import matrix

For every supported direction:

1. create/populate a valid Drupal source;
2. create a truly empty destination;
3. run import;
4. validate normalized schema;
5. validate row counts according to profile;
6. boot Drupal from a `full` imported destination after controlled role selection.

Rejection tests:

- one existing user table -> import fails without mutation;
- one existing view -> import fails;
- destination with prior Drupal schema -> import fails;
- interrupted partial import -> destination is not marked initialized;
- retry behavior is deterministic after adapter cleanup/reset.

## Fixtures

Include empty tables, no-PK tables, simple/composite keys, unique/index combinations, NULL/empty/zero distinctions, signed/unsigned numbers, numeric precision, text/blob, zero bytes, multilingual UTF-8/emoji and large datasets.

Add explicit incompatible fixtures for enum/set, native JSON semantics where equivalence is not established, generated columns, fulltext, spatial objects, views, triggers and expression/functional indexes.

## Clean profile tests

For MariaDB/MySQL primary -> SQLite standby:

- `cache_*`, sessions, semaphore, batch and watchdog have schema but no copied data under `clean`;
- queue, flood, key/value state and unknown tables remain copied;
- `full` preserves all supported data.

SQLite primary -> MariaDB/MySQL standby must reject `clean`.

An import that will later be promoted to primary must use `full`.

## Consistency/concurrency

Run writers during import/rebuild and prove the destination represents one stable source snapshot plus deterministic catch-up once change capture is implemented.

Test both primary engines.

## Failure injection

Before beta, prove safe behavior for interruption during:

- destination schema creation;
- mid-table import;
- validation;
- catch-up;
- immediately before publication/promotion.

Cover disk full, permission failure, source disconnect, stale candidates, concurrent run locking and service interruption.

## Application smoke tests

Database parity is not enough.

For **both primary engines**:

- boot Drupal;
- run `drush status`;
- run `drush core:requirements --severity=2`;
- run `drush cache:rebuild`;
- execute representative HTTP requests;
- execute representative entity create/update/delete operations.

The development environment must be able to switch between the two role assignments through deployment settings without editing module code.

## Production-readiness gate

Do not call the project production-ready until both directions have:

- empty-destination bootstrap import;
- schema compatibility coverage;
- consistent rebuilds;
- durable change capture;
- bounded-memory data transfer;
- crash-safe destination initialization/publication;
- reconciliation;
- application-level Drupal smoke tests.

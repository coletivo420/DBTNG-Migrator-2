# Testing Strategy

## Test layers

### Unit

Cover:

- database topology validation;
- replication-profile typing and topology compatibility;
- import request validation;
- destination empty/non-empty classification;
- non-empty destination policy behavior;
- native backup format/engine mapping;
- backup artifact checksum/metadata validation;
- type/schema mapping;
- policy classification;
- manifest behavior;
- bounded batch sizing;
- path/candidate validation;
- publication state;
- exception mapping.
- both physical schema introspectors and MySQL catalog prefix isolation;
- topology resolution in both primary directions;
- destination empty/non-empty reasons;
- strict portability blockers and review warnings;
- native backup checksum, gzip, secret-free argv, temporary credential cleanup and process failure;
- live SQLite WAL snapshot and integrity validation.

The unit suite uses a local SQLite database and mocked MySQL information-schema results. These tests validate adapters and model mapping; they are not substitutes for testing the protected Virtualmin installation or a live MySQL-family server.

### CI compatibility

GitHub Actions covers PHP 8.3, 8.4 and 8.5 against Drupal ^11.4 dependencies.

### Integration engine matrix

Required directions:

- MariaDB 10.6+ -> SQLite 3.45+
- MySQL 8.0+ -> SQLite 3.45+
- SQLite 3.45+ -> MariaDB 10.6+
- SQLite 3.45+ -> MySQL 8.0+

The canonical live integration host is `dbtng.toca.net.br`.

The Phase B live baseline is Drupal 11.4.8, Drush 13.8.0, MariaDB 11.8.6 and SQLite 3.46.1 on PHP 8.4.26. Integration runs must use the domain owner account and private artifacts. The current alternate SQLite database is an independent Drupal install and should correctly produce NON_EMPTY as a destination; it is not a synchronized standby.

## Native backup / restore matrix

For MariaDB/MySQL:

- generate `.sql` and `.sql.gz` backup from a populated primary;
- generate backup from a populated standby;
- prove credentials are not exposed in logs/process arguments;
- restore into an empty MySQL-family standby;
- restore into a populated standby using `backup_then_clear`;
- verify safety backup before clearing;
- reject MySQL native backup when standby engine is SQLite.

For SQLite:

- create a consistent `.sqlite` snapshot while the source is live;
- run under WAL mode and prove committed data is present in the snapshot;
- pass `PRAGMA integrity_check`;
- validate Drupal's NOCASE_UTF8 indexes by registering Drupal's collation on SQLite3 handles used for the snapshot and integrity check;
- optionally gzip and restore the snapshot;
- restore into an empty SQLite standby;
- restore into a populated SQLite standby using `backup_then_clear`;
- reject SQLite native backup when standby engine is MySQL-family.

Browser-download tests must prove large artifacts are streamed and are not loaded fully into PHP memory.

## Destination clearing matrix

For both standby engines:

- `abort`: no mutation;
- `backup_then_clear`: valid safety backup exists before first destructive action;
- `clear`: explicit destructive path succeeds;
- primary/standby identity collision: destructive action is rejected;
- clear failure: import/restore does not begin;
- post-clear inventory: destination is empty before import/restore starts.

## Empty-destination import matrix

For every supported direction:

1. create/populate a valid Drupal source;
2. create a truly empty destination;
3. run import;
4. validate normalized schema;
5. validate row counts according to profile;
6. boot Drupal from a `full` imported destination after controlled role selection.

Rejection tests:

- one existing user table + `abort` -> import fails without mutation;
- one existing view + `abort` -> import fails;
- destination with prior Drupal schema + `abort` -> import fails;
- the same populated fixtures + `backup_then_clear` -> safety backup, clear, import, validate;
- the same populated fixtures + `clear` -> clear, import, validate;
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

## Phase C live acceptance

The canonical host `dbtng.toca.net.br` was exercised on Drupal 11.4.8, PHP 8.4.26, Drush 13.8.0, MariaDB 11.8.6 and SQLite 3.46.1. Both full import directions and the MariaDB -> SQLite clean profile passed with schema/row validation; SQLite integrity and foreign-key checks passed. Drupal status, requirements, cache rebuild, HTTP 200 and representative entity CRUD passed after promoting each full-import destination in turn. Native restore, safety-backup preparation, explicit clear, abort, wrong-engine/corrupt-gzip rejection and primary-clear refusal were also exercised.

A 1,200-row unknown-table fixture crossed multiple batches. The observed peak PHP memory was 30 MiB for the tested live imports; this is a test observation, not a general memory ceiling. The initial MariaDB -> SQLite run reported 343 portability warnings, which remain documented rather than suppressed. PHPUnit currently has 61 tests and 190 assertions; its existing deprecation notices are recorded during quality runs.

## Production-readiness gate

Do not call the project production-ready until both directions have:

- native backup/download/restore for both engines;
- explicit safe standby clearing;
- logical bootstrap/replacement import;
- schema compatibility coverage;
- consistent rebuilds;
- durable change capture;
- bounded-memory data transfer;
- crash-safe destination initialization/publication;
- reconciliation;
- application-level Drupal smoke tests.
# Phase D rebuild and reconciliation tests

`dbtng:reconcile` must be tested for schema drift, missing/extra tables, row-count drift, equal-count content drift, manifest mismatch, engine integrity and clean-profile exclusions. It is strictly read-only.

`dbtng:rebuild` tests must inject failures after candidate creation, during transfer, before/after validation and immediately before each engine's atomic publication operation. For every pre-publication failure, verify the old standby remains usable and its manifest remains current. Test both role directions and clean MariaDB/MySQL -> SQLite; never promote a clean candidate as full.

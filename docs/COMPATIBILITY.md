# Compatibility

## Initial support target

- Drupal 11.4+
- PHP 8.3+
- MariaDB/MySQL and SQLite as the initial engine pair
- MariaDB/MySQL primary -> SQLite standby (default)
- SQLite primary -> MariaDB/MySQL standby (selectable)

## Profile compatibility

- `full`: valid for either standby direction.
- `clean`: initially valid only when SQLite is standby.
- `clean-public`: future SQLite-standby projection.

DBTNG distinguishes **database portability** from **application portability**. A structurally valid secondary database does not prove that every contrib/custom module works on both engines. Runtime boot/smoke tests are required for the database engine that may become primary during failover.

This is especially important when choosing SQLite as the normal primary: the Drupal installation and all enabled modules must already be compatible with SQLite in ordinary production use.

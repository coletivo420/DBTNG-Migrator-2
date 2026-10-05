# Compatibility

## Initial support target

- Drupal 11.4+
- PHP 8.3, 8.4 and 8.5
- MariaDB 10.6+ or MySQL 8.0+
- SQLite 3.45+
- Drush 13.7+ or 14
- MariaDB/MySQL primary -> SQLite standby (default)
- SQLite primary -> MariaDB/MySQL standby (selectable)

## Profile compatibility

- `full`: default, valid for either standby direction.
- `clean`: opt-in, initially valid only when SQLite is standby.
- `clean-public`: future SQLite-standby projection and not yet a runtime profile.

DBTNG distinguishes **database portability** from **application portability**. A structurally valid secondary database does not prove that every contrib/custom module works on both engines. Runtime boot/smoke tests are required for any engine that may become primary.

When choosing SQLite as the normal primary, the Drupal installation and all enabled modules must already be compatible with SQLite in ordinary application use.

# Compatibility

## Initial support target

- Drupal 11.4+
- PHP 8.3+
- MariaDB/MySQL primary
- SQLite standby

DBTNG distinguishes **database portability** from **application portability**. A structurally valid SQLite database does not prove that every contrib/custom module avoids MySQL-specific SQL. Runtime boot/smoke tests are required before a site can claim SQLite standby compatibility.

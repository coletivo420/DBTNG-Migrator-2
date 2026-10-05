# Portability

DBTNG Migrator 2 transports a normalized logical schema and rows; it does not rewrite raw MySQL dumps into SQLite SQL.

## Strict by default

Unsupported source semantics fail portability analysis instead of silently changing meaning. Examples requiring explicit support include native enum/set semantics, spatial data/indexes, generated columns, expression indexes, full-text indexes, triggers/views/procedures and backend-specific defaults.

## Physical schema first

The first MariaDB/MySQL introspector will inspect real tables, columns, indexes, constraints, engines, collations and relevant metadata. Drupal entity metadata is used later for semantic projection, not to replace physical discovery.

## Unknown objects

Unknown tables default to preservation (`copy`) unless an operator explicitly defines a policy. This prevents new contrib/custom tables from being silently omitted after module upgrades.

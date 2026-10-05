# Portability

DBTNG Migrator 2 transports a normalized logical schema and rows; it does not rewrite raw vendor SQL dumps into another dialect.

## Initial engine pair

The initial bidirectional role pair is:

- MariaDB/MySQL <-> SQLite

MariaDB/MySQL is the default primary, but either engine can be selected as the primary role.

## Strict by default

Unsupported source semantics fail portability analysis instead of silently changing meaning. Examples requiring explicit support include native enum/set semantics, spatial data/indexes, generated columns, expression indexes, full-text indexes, triggers/views/procedures and backend-specific defaults.

## Physical schema first

Each supported primary engine needs its own physical schema introspector.

```text
SourceSchemaIntrospectorInterface
  |-- MysqlFamilySchemaIntrospector
  +-- SqliteSchemaIntrospector
```

The first implementation phase must inventory both directions before the selectable-primary feature can be considered complete.

Drupal entity metadata is used later for semantic projection, not to replace physical discovery.

## Destination adapters

Portable schema is consumed by a destination adapter for the configured standby engine. The system must not assume that the destination is always a SQLite file.

Each destination adapter must also support strict empty-state detection for bootstrap import. A destination that already contains user-defined application objects is not a valid import target.

Empty-destination import is required in both directions before continuous synchronization is considered complete.

## Unknown objects

Unknown tables default to preservation (`copy`) unless an operator explicitly defines a policy. This prevents new contrib/custom tables from being silently omitted after module upgrades.

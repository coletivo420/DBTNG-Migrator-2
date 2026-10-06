# Portability

DBTNG Migrator 2 transports a normalized logical schema and rows; it does not rewrite raw vendor SQL dumps into another dialect.

## Initial engine pair

The initial bidirectional role pair is:

- MariaDB/MySQL <-> SQLite

MariaDB/MySQL is the default primary, but either engine can be selected as the primary role.

## Strict by default

Unsupported source semantics fail portability analysis instead of silently changing meaning. Examples requiring explicit support include native enum/set semantics, spatial data/indexes, generated columns, expression indexes, full-text indexes, triggers/views/procedures and backend-specific defaults.

The initial analyzer operates on the physical inventory. It treats mapped integer/text/blob/float types and explicit numeric precision/scale as supported, reports unsigned/collation/prefix/partial-index semantics for review, and blocks generated columns, unknown types, spatial/full-text/functional indexes and user-defined schema objects. This is a conservative preflight, not a destination schema builder or proof that every value fits a future mapping.

Preflight is read-only. An existing Drupal database on the standby is reported as NON_EMPTY, including its application indexes, views and triggers; it is not interpreted as synchronized merely because it has a Drupal schema. Review warnings do not indicate that rows have been tested against a destination builder, which is not implemented yet.

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

## Phase C findings

The live MariaDB -> SQLite inventory produced 343 initial warnings. They were kept as evidence and not bulk-suppressed. These warnings include conditional physical semantics such as unsigned types/collations and index-prefix differences. During SQLite -> MariaDB schema construction, non-unique indexes exceeding the InnoDB key-byte limit are bounded with a prefix and reported as warnings; strict unique-index semantics are never silently weakened and can block import. Warning totals are tied to the source's physical schema and can change after a round trip.

The live test also confirmed that all tables, including unknown application tables, are copied by `full`; `clean` omits only its documented transient table patterns and retains queues, key-value tables and unknown tables. Generated expressions and unsupported semantics remain subject to strict analysis. An import with a blocker must fail before it reports initialization.

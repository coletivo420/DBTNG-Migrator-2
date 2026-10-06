# Recovery

Recovery is defined by **current authority**, not by a permanently privileged database engine.

## Standby never became writable authority

Rebuild or catch up the standby from the configured primary.

## Standby became writable authority

After failover, treat the promoted standby as the current source of truth. Recovery creates a new validated secondary representation from that authority before any later authority switch.

Possible directions include:

```text
SQLite authority
    |
    v
new MariaDB/MySQL
    |
    v
logical migration + validation
    |
    v
controlled authority switch
```

and:

```text
MariaDB/MySQL authority
    |
    v
new SQLite
    |
    v
logical migration + validation
    |
    v
controlled authority switch
```

Automatic merge with an old divergent primary timeline is explicitly out of scope for the initial releases.

## Native recovery in Phase C

Native restore is a same-engine standby operation. `dbtng:restore` validates input before destructive preparation, applies the selected non-empty policy, restores through the matching native path, re-introspects and validates before recording initialized state. Cross-engine recovery uses `dbtng:import` and a logical schema mapping; SQL dumps are never translated across engines. Do not promote a `clean` SQLite projection because transient tables are intentionally empty.

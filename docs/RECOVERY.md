# Recovery

There are two distinct recovery cases.

## Standby never became writable authority

Rebuild or catch up SQLite from the MariaDB/MySQL primary.

## Standby became writable authority

Treat SQLite as the current source of truth. The planned recovery workflow is:

```text
SQLite authority
    |
    v
new empty MariaDB/MySQL
    |
    v
logical migration
    |
    v
validation
    |
    v
controlled authority switch
```

Automatic merge with an old primary timeline is explicitly out of scope for the initial releases.

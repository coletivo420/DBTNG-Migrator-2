# Clean Replication Profiles

A clean standby is not a partial schema. Tables Drupal expects still exist; only data classified as disposable is omitted.

## Default behavior

`full` is the default replication profile.

`clean` is an explicit opt-in profile because it intentionally omits some standby data. This keeps a new installation conservative and makes the default valid regardless of which supported engine is primary.

## Role restriction

Clean projection applies **only to a standby representation**. It must never mutate or filter the authoritative primary.

Initial product:

- MariaDB/MySQL primary -> SQLite standby: `full` or opt-in `clean`.
- SQLite primary -> MariaDB/MySQL standby: `full` only.

If SQLite is selected as primary, its cache/session/revision data is normal authoritative data and DBTNG does not delete it under a standby policy.

## Initial `clean` defaults

| Pattern/table | Data policy | Reason |
| --- | --- | --- |
| `cache_*` | `schema_only` | Drupal can regenerate cache entries. |
| `sessions` | `schema_only` | Failover may log users out; session data is not required to preserve content. |
| `semaphore` | `schema_only` | Runtime locks must not survive as stale locks on a new authority. |
| `batch` | `schema_only` | In-flight Batch API state should not be assumed recoverable across authority switch. |
| `watchdog` | `schema_only` | Database logging is operational history, not required to boot the site. |
| `queue` | `copy` | May contain email, webhooks, indexing or custom jobs that must not be silently lost. |
| `flood` | `copy` | Contains abuse/rate-limit security state. |
| `key_value` | `copy` | May hold application state. |
| `key_value_expire` | `copy` | May hold tempstore/application state; not disposable by default. |
| unknown table | `copy` | Conservative anti-data-loss default. |

## Drafts and revisions

Draft removal cannot be implemented by declaring revision tables disposable. Drupal entities may have base, data, revision, revision-data and field tables tied together.

Future entity-aware modes:

- `all`: preserve all revisions.
- `default_only`: preserve current/default representation and required relational rows.
- `published_only`: intentionally exclude unpublished-only entities.

These modes are not yet accepted runtime profiles. They require an entity-aware projection implementation and dedicated tests first.

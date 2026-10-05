# Clean Replication Profiles

A clean standby is not a partial schema. Tables Drupal expects still exist; only data classified as disposable is omitted.

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

Draft removal cannot be implemented by declaring revision tables disposable. Drupal entities may have base, data, revision, revision-data and field tables tied together. Content Moderation can keep a published default revision while newer draft revisions exist.

Future `EntityProjectionPolicy` modes:

- `all`: preserve all revisions.
- `default_only`: preserve the current/default representation and required relational rows; remove non-default historical/draft revisions safely.
- `published_only`: intentionally exclude unpublished-only entities; this is a sanitized standby and has a different recovery guarantee.

`default_only` is the planned default revision projection for the clean standby once entity-aware projection is implemented.

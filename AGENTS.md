# AGENTS.md — DBTNG Migrator 2

This file is normative guidance for humans and AI coding agents. Architectural changes must update the relevant documentation and ADRs in the same change.

## Non-negotiable invariants

1. **Never switch Drupal's global active database connection during migration or synchronization.** Pass explicit `Connection` objects/services.
2. **Never write directly to the currently published standby during rebuild.** Build a temporary SQLite file and publish only after validation.
3. **Never publish a snapshot before validation succeeds.**
4. **Never silently convert unsupported SQL types in strict mode.** Unsupported semantics are errors, not warnings disguised as success.
5. **Never load an entire large table into PHP memory.** Transfer must be bounded-memory and stream/chunk based.
6. **MariaDB/MySQL is authoritative during normal operation.**
7. **SQLite synchronization failure must not take down the primary site.** Lag/backlog must remain observable and recoverable.
8. **A change committed in MariaDB/MySQL must not be silently lost by the standby pipeline.** Durable capture is mandatory before a change is considered observable by the synchronization design.
9. **Never implement automatic failback.** Failover creates an authority transition that requires an explicit recovery procedure.
10. **Never log database credentials, full DSNs or secrets.**
11. **Physical database introspection is the source of truth for physical schema.** Enabled modules and `hook_schema()` alone are insufficient.
12. **Clean profiles remove data, not schema required by Drupal.** A schema-only table still exists in SQLite.
13. **Unknown tables default to COPY.** Conservative preservation beats silent data deletion.
14. **`queue` and `flood` are not disposable by default.** They may contain pending work and security state.
15. **Entity revision filtering must be entity-aware.** Do not implement draft removal by blindly skipping revision tables or deleting rows without a storage mapping/projection model.
16. **Documentation changes are part of the implementation.** Update README/docs/ADRs whenever behavior, guarantees, compatibility or architecture changes.

## Current product boundary

The first supported direction is MariaDB/MySQL primary to SQLite standby. The design must remain adapter-friendly, but premature PostgreSQL or reverse-sync abstractions must not compromise correctness of the first path.

## Change discipline

Every implementation commit should answer:

- Which invariant does this change depend on?
- Which failure mode is covered by tests?
- Does this affect portability, consistency, failover or clean-profile semantics?
- Which document/ADR needs updating?

Do not claim production readiness until the beta/stable criteria in `docs/TESTING.md` are satisfied.

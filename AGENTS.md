# AGENTS.md — DBTNG Migrator 2

This file is normative guidance for humans and AI coding agents. Architectural changes must update the relevant documentation and ADRs in the same change.

## Non-negotiable invariants

1. **Never switch Drupal's global active database connection during migration or synchronization.** Pass explicit `Connection` objects/services.
2. **Never rebuild directly over the currently published standby state.** Build an isolated candidate and publish/promote only after validation.
3. **Never publish/promote a snapshot before validation succeeds.**
4. **Never silently convert unsupported SQL types in strict mode.** Unsupported semantics are errors, not warnings disguised as success.
5. **Never load an entire large table into PHP memory.** Transfer must be bounded-memory and stream/chunk based.
6. **The configured primary role is authoritative during normal operation.** Do not hard-code MariaDB/MySQL or SQLite as the authority.
7. **Standby synchronization failure must not take down the primary site.** Lag/backlog must remain observable and recoverable.
8. **A change committed in the configured primary must not be silently lost by the standby pipeline.** Durable engine-specific capture is mandatory.
9. **Never implement automatic failback.** Failover creates an authority transition that requires an explicit recovery procedure.
10. **Never log database credentials, full DSNs or secrets.**
11. **Physical database introspection is the source of truth for physical schema.** Enabled modules and `hook_schema()` alone are insufficient.
12. **Clean profiles remove standby data, not schema required by Drupal.**
13. **Unknown tables default to COPY.** Conservative preservation beats silent data deletion.
14. **`queue` and `flood` are not disposable by default.** They may contain pending work and security state.
15. **Entity revision filtering must be entity-aware.** Do not implement draft removal by blindly skipping revision tables or deleting rows without a storage mapping/projection model.
16. **Documentation changes are part of the implementation.** Update README/docs/ADRs whenever behavior, guarantees, compatibility or architecture changes.
17. **Primary database selection is a first-class requirement.** MariaDB/MySQL is the default primary, but SQLite must be supported as primary; engine-specific behavior belongs behind adapters.
18. **Never apply a lossy/clean projection to the authoritative primary.** Initial `clean` and `clean-public` semantics are only valid when SQLite is the standby.

## Current product boundary

The initial engine pair is MariaDB/MySQL and SQLite, with two supported role topologies:

- MariaDB/MySQL primary -> SQLite standby (default; `full` or `clean`).
- SQLite primary -> MariaDB/MySQL standby (selectable; initially `full`).

The design must remain adapter-oriented. PostgreSQL and other future engines must not weaken correctness of either initial direction.

## Change discipline

Every implementation commit should answer:

- Which invariant does this change depend on?
- Which failure mode is covered by tests?
- Does this affect portability, consistency, primary selection, failover or clean-profile semantics?
- Which document/ADR needs updating?

Do not claim production readiness until the beta/stable criteria in `docs/TESTING.md` are satisfied.

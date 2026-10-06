# AGENTS.md — DBTNG Migrator 2

This file is normative guidance for humans and AI coding agents. Architectural changes must update the relevant documentation and ADRs in the same change.

## Non-negotiable product invariants

1. **Never switch Drupal's global active database connection inside migration/synchronization logic.** Pass explicit connection objects/services.
2. **Never rebuild directly over the currently published standby state.** Build an isolated candidate and publish/promote only after validation.
3. **Never publish/promote a snapshot before validation succeeds.**
4. **Never silently convert unsupported SQL types in strict mode.**
5. **Never load an entire large table into PHP memory.** Transfer must be bounded-memory.
6. **The configured primary role is authoritative during normal operation.** Do not hard-code MariaDB/MySQL or SQLite as authority.
7. **Standby failure must not take down the primary site.** Lag/backlog must remain observable and recoverable.
8. **A change committed in the configured primary must not be silently lost by the standby pipeline.**
9. **Never implement automatic failback.**
10. **Never log database credentials, full DSNs, root/sudo passwords or application secrets.**
11. **Physical database introspection is the source of truth for physical schema.**
12. **Clean profiles remove standby data, not schema required by Drupal.**
13. **Unknown tables default to COPY.**
14. **`queue` and `flood` are not disposable by default.**
15. **Entity revision filtering must be entity-aware.**
16. **Documentation changes are part of the implementation.**
17. **Primary selection is a first-class requirement.** MariaDB/MySQL is the default, SQLite is supported as primary.
18. **Never apply a lossy/clean projection to the authoritative primary.**
19. **Runtime topology is deployment configuration.** The active Drupal database must be selected in `settings.php` / environment-backed settings before Config API is available. Do not move this responsibility into Drupal Config API alone.
20. **Default replication profile is `full`.** `clean` is explicit opt-in and currently valid only for SQLite standby.
21. **Non-empty standby handling is explicit.** Default is `abort`; destructive preparation requires an explicit `backup_then_clear` or `clear` policy.
22. **Never clear the active primary.** Destructive preparation applies only to a separately resolved standby destination.
23. **`backup_then_clear` is transactional in intent.** The safety backup must complete, be checksummed and be readable before clearing starts.
24. **No silent merge/overwrite.** A populated destination is never merged into; clear means removing existing destination state first.
25. **Logical import is bidirectional for the initial engine pair.** MariaDB/MySQL -> SQLite and SQLite -> MariaDB/MySQL are both requirements.
26. **A destination is not initialized until import/restore validation succeeds.**
27. **Clean import is only for SQLite standby.** Any database intended to become primary must be initialized from a `full` representation.
28. **Native backup/restore is same-engine.** MySQL SQL dumps restore only to MySQL-family; SQLite database snapshots restore only to SQLite. Cross-engine movement uses DBTNG logical import.
29. **Use upstream implementations before inventing new backup code.** Follow `docs/UPSTREAM_COMPONENTS.md`, retain provenance/license notices for copied code and prefer adapters around proven tools.
30. **Live SQLite is never backed up by blindly copying only the main file.** Use SQLite's online backup facilities or another proven consistent snapshot mechanism, especially under WAL.
31. **Database downloads and uploads are private sensitive artifacts.** Never stage them under public webroot or trust client-provided filenames/MIME types.
32. **SQLite snapshot validation must register Drupal's `NOCASE_UTF8` collation.** Drupal indexes can depend on it; plain SQLite3 integrity checks otherwise fail on valid site databases.
33. **Drush commands that inject Drupal services must declare the appropriate Drush bootstrap level.** Use the current Symfony `AsCommand` and `AutowireTrait` form supported by Drush 13.7+ and validate command discovery in the actual Composer installation layout.

## Current product boundary

Initial topologies:

- MariaDB/MySQL primary -> SQLite standby (`full` or opt-in `clean`).
- SQLite primary -> MariaDB/MySQL standby (`full`).

Both directions are implemented as logical import requirements. Phase C provides native restore and destination preparation, and the Phase D branch adds candidate-based rebuild/reconciliation. Browser download remains future work.

PostgreSQL and other engines are future adapters.

## Authorized development environment

The canonical live development target is **`dbtng.toca.net.br`** on the user's Virtualmin server.

Codex is explicitly authorized to create, configure, repair, reset and reinstall the dedicated development site for this project, subject to these boundaries.

### Allowed without additional confirmation

Codex may:

- inspect the OS, Virtualmin, web server, PHP, Composer, MariaDB/MySQL and SQLite versions/configuration;
- run read-only Virtualmin discovery commands;
- use `sudo` for project-required administrative operations;
- run `sudo -v` and allow the terminal to prompt the human for the sudo/root password when required;
- create or manage the Virtualmin virtual server/sub-server for exactly `dbtng.toca.net.br`;
- create dedicated MariaDB/MySQL databases/users owned by that development virtual server;
- create private SQLite files/directories for this development site;
- configure the site's PHP version, PHP-FPM/web settings, document root, TLS certificate and project-local scheduled services;
- manage only DNS record(s) for `dbtng.toca.net.br` in the authoritative Cloudflare zone; keep the DNS record unproxied as explicitly required by the user;
- install a **specific missing package** required for this development site after inspecting current packages;
- reload/restart a service when required by a validated configuration change;
- destroy/recreate **development data belonging only to `dbtng.toca.net.br`** when tests require a clean environment.

### Privilege and secret rules

- Never ask the user to paste the root/sudo password into chat, a file, an environment variable or a command argument.
- Let `sudo` request the password interactively in the user's terminal.
- Never run commands that reveal cached credentials or Virtualmin password fields.
- Do not use `virtualmin list-domains --multiline` in normal automation or diagnosis: its output may contain database credentials. Administrative commands must request minimal output and avoid password-bearing fields.
- No credential may appear in command output, logs, reports, CI artifacts or chat. Never inspect current credentials to recover or print them.
- Generate development account/database passwords securely and keep them out of Git and normal logs.
- Prefer `--passfile`/protected temporary files where supported; delete temporary credential files immediately.
- Run Composer, Git, Drush and application commands as the Virtualmin domain owner, **not as root**.

### Hard scope limits

Without separate explicit permission, Codex must not:

- modify, delete or reconfigure any Virtualmin domain other than `dbtng.toca.net.br`;
- delete or repurpose the parent `toca.net.br` virtual server;
- alter unrelated DNS records;
- reset global MariaDB/MySQL root credentials;
- perform broad OS upgrades, distribution upgrades, mass package removals or reboots;
- copy production/private data into the test site;
- weaken firewall, TLS or filesystem security globally;
- use `--skip-warnings` merely to bypass a Virtualmin safety check.

If the requested development action would require a global change outside these boundaries, stop at that boundary and report the exact requirement.

## Development-server workflow

Before mutating the server, follow [docs/DEVELOPMENT_ENVIRONMENT.md](docs/DEVELOPMENT_ENVIRONMENT.md):

1. discover existing state;
2. identify the exact Virtualmin domain owner/home;
3. provision/reuse only the dedicated test domain;
4. install Drupal as the domain user through Composer;
5. link this module through a Composer path repository;
6. configure both database engines;
7. exercise implemented native CLI backup and restore for both engines;
8. exercise logical import and candidate rebuild in both directions;
9. exercise read-only reconciliation and all non-empty destination policies;
10. exercise both primary topologies;
11. record test evidence in the PR/commit notes.

## Change discipline

Every implementation commit should answer:

- Which invariant does this change depend on?
- Which failure mode is covered by tests?
- Does this affect backup, restore, destructive destination preparation, import, portability, consistency, topology, failover or clean-profile semantics?
- Which upstream implementation was reused or studied, and is its provenance documented?
- Which document/ADR needs updating?
- Was the Virtualmin integration environment used, and if not, why was it unnecessary?

Do not claim production readiness until the beta/stable criteria in `docs/TESTING.md` are satisfied.

## Phase C regression checks

- Keep `dbtng.toca.net.br` as the only canonical development hostname.
- Never run `virtualmin list-domains --multiline` in normal automation or diagnostics; it may expose database passwords. Administrative commands and reports must keep credentials out of terminal output, logs, CI and chat.
- Before destructive work, prove the target is the configured standby and physically distinct from primary. Never clear the primary.
- Logical imports must stream bounded batches, validate schema/data/integrity, and publish initialized state only after all checks pass.
- Preserve the distinction between physical portability warnings and strict blockers; do not suppress warnings just to lower counts.

## Phase D rebuild checks

- Rebuild must use an isolated engine-specific candidate and leave the last published standby available until atomic publication.
- Serialize import, restore, and rebuild with the same private `flock` lock; never remove a lock based on file age.
- Before publication without CDC, fence writes briefly and compare source schema plus streamed row-content fingerprints; equal row counts alone are insufficient evidence of parity.
- `dbtng:reconcile` is read-only and compares content as well as schema, indexes, profile projection, integrity and the published manifest.
- MySQL-family staging names `dbtngc<8-hex>_`, retained names `dbtngp<8-hex>_`, and `dbtng_migrator_snapshot_state` are reserved for DBTNG; reject collisions and do not treat them as Drupal application tables.
- A clean SQLite standby is standby-only and must never be reported as full-equivalent or promoted as such.
- Failure injection is supplied through an injected test service; production uses `NullFailureInjector`. Do not add ad-hoc environment-variable failpoints.

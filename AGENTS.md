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
21. **Bootstrap import is allowed only into a proven-empty destination.** Non-empty means fail; do not merge, overwrite or silently drop existing destination data.
22. **Empty-destination import is bidirectional for the initial engine pair.** MariaDB/MySQL -> SQLite and SQLite -> MariaDB/MySQL are both product requirements.
23. **There is no destructive force-import in the initial product.** Adding overwrite/merge semantics requires a separate ADR and explicit data-loss design.
24. **A destination is not initialized until import validation succeeds.** A failed import must never be reported as a usable standby.
25. **Clean import is only for SQLite standby.** Any database intended to become primary must be initialized from a `full` representation.

## Current product boundary

Initial topologies:

- MariaDB/MySQL primary -> SQLite standby (`full` or opt-in `clean`).
- SQLite primary -> MariaDB/MySQL standby (`full`).

Both directions support bootstrap import when the destination is empty.

PostgreSQL and other engines are future adapters.

## Authorized development environment

The canonical live development target is **`bdtgn.toca.net.br`** on the user's Virtualmin server.

Codex is explicitly authorized to create, configure, repair, reset and reinstall the dedicated development site for this project, subject to these boundaries.

### Allowed without additional confirmation

Codex may:

- inspect the OS, Virtualmin, web server, PHP, Composer, MariaDB/MySQL and SQLite versions/configuration;
- run read-only Virtualmin discovery commands;
- use `sudo` for project-required administrative operations;
- run `sudo -v` and allow the terminal to prompt the human for the sudo/root password when required;
- create or manage the Virtualmin virtual server/sub-server for exactly `bdtgn.toca.net.br`;
- create dedicated MariaDB/MySQL databases/users owned by that development virtual server;
- create private SQLite files/directories for this development site;
- configure the site's PHP version, PHP-FPM/web settings, document root, TLS certificate and project-local scheduled services;
- add only the DNS record(s) required for `bdtgn.toca.net.br` when the corresponding zone is managed locally;
- install a **specific missing package** required for this development site after inspecting current packages;
- reload/restart a service when required by a validated configuration change;
- destroy/recreate **development data belonging only to `bdtgn.toca.net.br`** when tests require a clean environment.

### Privilege and secret rules

- Never ask the user to paste the root/sudo password into chat, a file, an environment variable or a command argument.
- Let `sudo` request the password interactively in the user's terminal.
- Never run commands that reveal cached credentials or Virtualmin password fields.
- Generate development account/database passwords securely and keep them out of Git and normal logs.
- Prefer `--passfile`/protected temporary files where supported; delete temporary credential files immediately.
- Run Composer, Git, Drush and application commands as the Virtualmin domain owner, **not as root**.

### Hard scope limits

Without separate explicit permission, Codex must not:

- modify, delete or reconfigure any Virtualmin domain other than `bdtgn.toca.net.br`;
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
7. exercise empty-destination import in both directions;
8. exercise both primary topologies;
9. record test evidence in the PR/commit notes.

## Change discipline

Every implementation commit should answer:

- Which invariant does this change depend on?
- Which failure mode is covered by tests?
- Does this affect import, portability, consistency, topology, failover or clean-profile semantics?
- Which document/ADR needs updating?
- Was the Virtualmin integration environment used, and if not, why was it unnecessary?

Do not claim production readiness until the beta/stable criteria in `docs/TESTING.md` are satisfied.

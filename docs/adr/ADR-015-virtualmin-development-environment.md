# Dedicated Virtualmin integration environment

- Status: Accepted
- Date: 2026-10-05

## Context

DBTNG requires real Drupal boot tests against MariaDB/MySQL and SQLite in both primary roles. Unit tests and generic CI cannot fully exercise web-server, PHP, permissions, filesystem, Virtualmin and application compatibility behavior.

The user has authorized Codex to maintain a dedicated development installation at `bdtgn.toca.net.br`, including sudo/root-backed Virtualmin operations when required.

## Decision

`bdtgn.toca.net.br` is the canonical disposable live integration site.

Codex may provision and maintain that site under the explicit scope in `AGENTS.md` and the runbook in `docs/DEVELOPMENT_ENVIRONMENT.md`.

Privileges are least-scope:

- Virtualmin/system administration only as required for this site;
- application commands as the domain owner;
- root/sudo password entered interactively by the human and never exposed to the agent;
- no changes to unrelated domains or global services unless separately authorized.

The Drupal host project and module checkout remain separate, connected through a Composer path repository.

## Consequences

Every integration-heavy feature can be verified in a reproducible real hosting environment. Development-site destructiveness is acceptable only within the dedicated domain/database/files it owns; it does not imply production authorization.

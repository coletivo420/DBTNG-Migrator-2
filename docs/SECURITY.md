# Security

Both database representations contain sensitive Drupal data and must be treated like production database backups.

## Required controls

- Store SQLite databases/manifests outside the public webroot.
- Prefer a private SQLite directory with mode `0700` and database files with mode `0600`.
- Keep MariaDB/MySQL standby databases under dedicated least-privilege accounts.
- Reject unsafe path traversal and symlink surprises in filesystem publisher code.
- Do not log database credentials, root/sudo passwords, application secrets or full DSNs.
- Keep manifests free of secrets.
- Store uploaded/restored database files outside webroot with generated server-side names.
- Never trust an upload filename, extension or client MIME type as proof of database format.
- Checksum backup artifacts before download/safekeeping and before using a safety backup as justification for destructive clearing.
- Never expose database passwords on process command lines when the native client supports protected credential files/options.
- Do not use `virtualmin list-domains --multiline` for routine automation or diagnosis; Virtualmin may include database passwords in that output. Use narrowly scoped commands and request only non-sensitive fields.
- Administrative command output and derived logs, reports, CI artifacts and chat must never contain credentials. Do not attempt to retrieve or print a current password.
- Fail closed when portability or validation cannot establish correctness in strict mode.
- Do not expose database ports remotely merely for DBTNG synchronization when source and standby are on the same host.

## Development privilege

Codex may use `sudo` for the dedicated `dbtng.toca.net.br` environment as defined in `AGENTS.md`, but the sudo/root password must be entered only into the operating system's interactive prompt.

Administrative access is not permission to read or modify unrelated domains/data.

## Destructive standby preparation

`backup_then_clear` and `clear` are destructive operations.

DBTNG must prove that the resolved destination is the configured standby and is distinct from the active primary before any destructive query or filesystem replacement.

The web UI requires an explicit destructive confirmation. CLI non-interactive execution requires both the explicit policy and normal command confirmation semantics; a generic `--yes` must not silently turn `abort` into `clear`.

A safety backup produced by `backup_then_clear` is sensitive data and receives the same private-storage rules as any other database backup.

## Disaster recovery scope

A database standby is not a complete Drupal disaster-recovery backup. Uploaded/private files, source code, Composer dependencies, deployment settings and secrets require separate backup/deployment procedures.

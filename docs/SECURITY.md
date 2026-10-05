# Security

Both database representations contain sensitive Drupal data and must be treated like production database backups.

## Required controls

- Store SQLite databases/manifests outside the public webroot.
- Prefer a private SQLite directory with mode `0700` and database files with mode `0600`.
- Keep MariaDB/MySQL standby databases under dedicated least-privilege accounts.
- Reject unsafe path traversal and symlink surprises in filesystem publisher code.
- Do not log database credentials, root/sudo passwords, application secrets or full DSNs.
- Keep manifests free of secrets.
- Fail closed when portability or validation cannot establish correctness in strict mode.
- Do not expose database ports remotely merely for DBTNG synchronization when source and standby are on the same host.

## Development privilege

Codex may use `sudo` for the dedicated `bdtgn.toca.net.br` environment as defined in `AGENTS.md`, but the sudo/root password must be entered only into the operating system's interactive prompt.

Administrative access is not permission to read or modify unrelated domains/data.

## Disaster recovery scope

A database standby is not a complete Drupal disaster-recovery backup. Uploaded/private files, source code, Composer dependencies, deployment settings and secrets require separate backup/deployment procedures.

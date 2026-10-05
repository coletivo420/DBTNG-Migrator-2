# Security

SQLite standby files contain sensitive Drupal data and must be treated like database backups.

## Required controls

- Store standby files outside the public webroot.
- Prefer a private directory with mode `0700` and database files with mode `0600` where supported.
- Reject unsafe path traversal and symlink surprises in publisher code.
- Do not log database credentials, secrets or full DSNs.
- Keep generated manifests free of secrets.
- Fail closed when portability or validation cannot establish correctness in strict mode.

A database snapshot is not a complete Drupal disaster-recovery backup: uploaded/private files, source code, Composer dependencies, settings and secrets need separate backup/deployment procedures.

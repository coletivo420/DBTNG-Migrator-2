#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  validate-systemd-unit.sh [--require-systemd-analyze] /absolute/path/to/unit

Performs DBTNG-specific safety checks and, when available, runs
systemd-analyze verify. It never starts or installs a service.
EOF
}

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

require_systemd=0
if [[ "${1-}" == "--require-systemd-analyze" ]]; then
  require_systemd=1
  shift
fi

[[ $# -eq 1 ]] || { usage >&2; exit 2; }
unit="$1"

[[ "$unit" == /* && -f "$unit" && ! -L "$unit" ]] || die "Unit must be an absolute regular non-symlink file."

if grep -Eq '__DBTNG_|__DRUPAL_|__DRUSH_' "$unit"; then
  die "Unit contains unresolved DBTNG placeholders."
fi

require_exact() {
  local expected="$1"
  grep -Fqx -- "$expected" "$unit" || die "Required unit directive missing: $expected"
}

require_exact 'Type=simple'
require_exact 'Restart=on-failure'
require_exact 'UMask=0077'
require_exact 'NoNewPrivileges=true'
require_exact 'PrivateTmp=true'
require_exact 'ProtectSystem=full'
require_exact 'KillSignal=SIGTERM'

user="$(awk -F= '$1=="User" {print substr($0, index($0,"=")+1); exit}' "$unit")"
group="$(awk -F= '$1=="Group" {print substr($0, index($0,"=")+1); exit}' "$unit")"
working_directory="$(awk -F= '$1=="WorkingDirectory" {print substr($0, index($0,"=")+1); exit}' "$unit")"
exec_start="$(awk -F= '$1=="ExecStart" {print substr($0, index($0,"=")+1); exit}' "$unit")"

[[ -n "$user" && "$user" != "root" && "$user" != "0" ]] || die "Unit must run under a non-root User=."
[[ -n "$group" ]] || die "Unit must declare Group=."
[[ "$working_directory" == /* ]] || die "WorkingDirectory must be absolute."
[[ "$exec_start" == /* ]] || die "ExecStart must begin with an absolute executable path."
[[ "$exec_start" == *" dbtng:sync --watch"* ]] || die "ExecStart must run dbtng:sync --watch."
[[ "$exec_start" != *"--once"* ]] || die "Continuous unit must not pass --once."
[[ "$exec_start" != *"sudo"* ]] || die "Continuous unit must not invoke sudo."
[[ "$exec_start" != *"/bin/sh -c"* && "$exec_start" != *"/bin/bash -c"* ]] || die "Continuous unit must not use a shell wrapper."

if grep -Eiq '^[[:space:]]*(Environment|EnvironmentFile)=.*(pass(word)?|token|secret|dsn|database_url)' "$unit"; then
  die "Unit contains a sensitive Environment=/EnvironmentFile= directive."
fi
if grep -Eiq '(mysql|mariadb|postgres(ql)?|https?)://[^[:space:]@]+:[^[:space:]@]+@' "$unit"; then
  die "Unit appears to embed credentials in a URI/DSN."
fi

if command -v systemd-analyze >/dev/null 2>&1; then
  systemd-analyze verify "$unit"
elif ((require_systemd)); then
  die "systemd-analyze is required but unavailable."
else
  printf 'WARNING: systemd-analyze unavailable; DBTNG static checks passed only.\n' >&2
fi

printf 'PASS: systemd unit safety/structure validation\n'

#!/usr/bin/env bash
set -euo pipefail

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

script_dir="$(cd -- "$(dirname -- "\${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "\${script_dir}/.." && pwd -P)"

for script in \
  "\${script_dir}/render-systemd-unit.sh" \
  "\${script_dir}/validate-systemd-unit.sh" \
  "\${script_dir}/validate-phase-f-host.sh"; do
  bash -n "$script"
done

command -v systemd-analyze >/dev/null 2>&1 || die "ubuntu-latest is expected to provide systemd-analyze."
command -v getent >/dev/null 2>&1 || die "getent is required by the renderer."

tmpdir="$(mktemp -d)"
trap 'rm -rf -- "$tmpdir"' EXIT
unit="\${tmpdir}/dbtng-migrator-sync.service"

"\${script_dir}/render-systemd-unit.sh" \
  --user "$(id -un)" \
  --group "$(id -gn)" \
  --project-root "$repo_root" \
  --drush /usr/bin/true \
  --output "$unit"

"\${script_dir}/validate-systemd-unit.sh" --require-systemd-analyze "$unit"

if "\${script_dir}/render-systemd-unit.sh" \
  --user root \
  --group "$(id -gn)" \
  --project-root "$repo_root" \
  --drush /usr/bin/true \
  --output "\${tmpdir}/root.service" >/dev/null 2>&1; then
  die "Renderer accepted root as the service user."
fi

cp "$unit" "\${tmpdir}/secret.service"
printf '\nEnvironment=DB_PASSWORD=not-a-real-secret\n' >>"\${tmpdir}/secret.service"
if "\${script_dir}/validate-systemd-unit.sh" "\${tmpdir}/secret.service" >/dev/null 2>&1; then
  die "Validator accepted a credential-bearing Environment directive."
fi

cp "$unit" "\${tmpdir}/once.service"
sed -i 's/dbtng:sync --watch/dbtng:sync --watch --once/' "\${tmpdir}/once.service"
if "\${script_dir}/validate-systemd-unit.sh" "\${tmpdir}/once.service" >/dev/null 2>&1; then
  die "Validator accepted --once in the continuous service."
fi

printf 'PASS: Phase F tooling self-tests\n'

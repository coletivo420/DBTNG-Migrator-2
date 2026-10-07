#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  render-systemd-unit.sh \
    --user USER \
    --group GROUP \
    --project-root /absolute/path \
    --drush /absolute/path/to/drush \
    --output /absolute/path/to/unit \
    [--uri https://dbtng.toca.net.br/] \
    [--template /absolute/path/to/template]

Renders the versioned DBTNG systemd reference unit without credentials.
The selected service user must not be root.
EOF
}

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

script_dir="$(cd -- "$(dirname -- "\${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "\${script_dir}/.." && pwd -P)"
template="\${repo_root}/docs/examples/dbtng-migrator-sync.service.example"
uri="https://dbtng.toca.net.br/"
user=""
group=""
project_root=""
drush=""
output=""

while (($# > 0)); do
  case "$1" in
    --user) user="\${2-}"; shift 2 ;;
    --group) group="\${2-}"; shift 2 ;;
    --project-root) project_root="\${2-}"; shift 2 ;;
    --drush) drush="\${2-}"; shift 2 ;;
    --output) output="\${2-}"; shift 2 ;;
    --uri) uri="\${2-}"; shift 2 ;;
    --template) template="\${2-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown option: $1" ;;
  esac
done

[[ -n "$user" && -n "$group" && -n "$project_root" && -n "$drush" && -n "$output" ]] || {
  usage >&2
  die "Missing required arguments."
}

[[ "$user" != "root" && "$user" != "0" ]] || die "The continuous sync service must never run as root."
[[ "$user" =~ ^[A-Za-z_][A-Za-z0-9_.@-]*[$]?$ ]] || die "Invalid service user."
[[ "$group" =~ ^[A-Za-z_][A-Za-z0-9_.@-]*[$]?$ ]] || die "Invalid service group."
[[ "$uri" == "https://dbtng.toca.net.br/" ]] || die "Only the canonical development URI is accepted by this project renderer."
[[ "$project_root" == /* && -d "$project_root" && ! -L "$project_root" ]] || die "Project root must be an existing absolute real directory."
[[ "$drush" == /* && -f "$drush" && -x "$drush" && ! -L "$drush" ]] || die "Drush path must be an existing absolute executable file."
[[ "$output" == /* ]] || die "Output path must be absolute."
[[ -f "$template" && ! -L "$template" ]] || die "Template must be a regular non-symlink file."
[[ ! -L "$output" ]] || die "Refusing to replace a symlink output path."

getent passwd "$user" >/dev/null || die "Service user does not exist on this host: $user"
getent group "$group" >/dev/null || die "Service group does not exist on this host: $group"

output_dir="$(dirname -- "$output")"
[[ -d "$output_dir" && ! -L "$output_dir" ]] || die "Output directory must already exist and must not be a symlink."

content="$(<"$template")"
content="\${content//__DBTNG_USER__/$user}"
content="\${content//__DBTNG_GROUP__/$group}"
content="\${content//__DRUPAL_PROJECT_ROOT__/$project_root}"
content="\${content//__DRUSH_BINARY__/$drush}"

if grep -Eq '__DBTNG_|__DRUPAL_|__DRUSH_' <<<"$content"; then
  die "Rendered unit still contains unresolved placeholders."
fi

if grep -Eiq '(password|passwd|token|secret|dsn)[[:space:]_]*=' <<<"$content"; then
  die "Rendered unit appears to contain a credential-bearing assignment."
fi

temporary="$(mktemp "\${output_dir}/.dbtng-unit.XXXXXX")"
trap 'rm -f -- "$temporary"' EXIT
printf '%s\n' "$content" >"$temporary"
chmod 0644 "$temporary"
mv -f -- "$temporary" "$output"
trap - EXIT

printf 'Rendered secret-free unit: %s\n' "$output"

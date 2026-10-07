#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  validate-phase-f-host.sh \
    --site-root /home/.../drupal-project \
    [--drush /absolute/path/to/drush] \
    [--mode preflight|service] \
    [--service dbtng-migrator-sync.service]

This script is intentionally read-only. It does not install/start/stop/kill
services, change database permissions, mutate Drupal content, or acknowledge
capture events.
EOF
}

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

pass() {
  printf 'PASS: %s\n' "$*"
}

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
module_root="$(cd -- "${script_dir}/.." && pwd -P)"
site_root=""
drush=""
mode="preflight"
service="dbtng-migrator-sync.service"
uri="https://dbtng.toca.net.br/"

while (($# > 0)); do
  case "$1" in
    --site-root) site_root="${2-}"; shift 2 ;;
    --drush) drush="${2-}"; shift 2 ;;
    --mode) mode="${2-}"; shift 2 ;;
    --service) service="${2-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown option: $1" ;;
  esac
done

[[ "$mode" == "preflight" || "$mode" == "service" ]] || die "--mode must be preflight or service."
[[ -n "$site_root" && "$site_root" == /* && -d "$site_root" && ! -L "$site_root" ]] || die "--site-root must be an existing absolute real directory."
[[ "$(id -u)" -ne 0 ]] || die "Run host validation as the Virtualmin domain owner, never root."
[[ -z "$(git -C "$module_root" status --porcelain)" ]] || die "Module checkout is not clean."

if [[ -z "$drush" ]]; then
  drush="${site_root}/vendor/bin/drush"
fi
[[ "$drush" == /* && -f "$drush" && -x "$drush" && ! -L "$drush" ]] || die "Drush executable is unavailable or unsafe: $drush"

php_bin="$(command -v php || true)"
curl_bin="$(command -v curl || true)"
[[ -n "$php_bin" ]] || die "PHP CLI is required."
[[ -n "$curl_bin" ]] || die "curl is required."

if "$php_bin" -r 'exit(extension_loaded("pcntl") && defined("SIGTERM") && defined("SIGINT") ? 0 : 1);'; then
  pass "PHP CLI PCNTL/SIGTERM/SIGINT available"
else
  die "PHP CLI lacks PCNTL signal support required for graceful Phase F shutdown."
fi

tmpdir="$(mktemp -d)"
trap 'rm -rf -- "$tmpdir"' EXIT

(
  cd "$site_root"
  "$drush" --uri="$uri" status >/dev/null
  "$drush" --uri="$uri" help dbtng:sync >/dev/null
  "$drush" --uri="$uri" help dbtng:sync:status >/dev/null
  "$drush" --uri="$uri" dbtng:doctor >"$tmpdir/doctor.txt"
  "$drush" --uri="$uri" dbtng:capture:status >"$tmpdir/capture.txt"
  set +e
  "$drush" --uri="$uri" dbtng:sync:status --format=json >"$tmpdir/sync-status.json"
  sync_status_exit=$?
  set -e
  "$drush" --uri="$uri" dbtng:reconcile >"$tmpdir/reconcile.txt"
  printf '%s\n' "$sync_status_exit" >"$tmpdir/sync-status.exit"
)

grep -Fq 'PCNTL signals: AVAILABLE' "$tmpdir/doctor.txt" || die "dbtng:doctor does not report PCNTL signals as AVAILABLE."
pass "Drush command discovery and DBTNG doctor"

"$php_bin" -r '
$data = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
foreach (["health","capture_healthy","pending_events","primary_engine","standby_engine","profile"] as $key) {
  if (!array_key_exists($key, $data)) {
    fwrite(STDERR, "Missing sync status key: {$key}\n");
    exit(1);
  }
}
if (!is_bool($data["capture_healthy"]) || !is_int($data["pending_events"]) || $data["pending_events"] < 0) {
  fwrite(STDERR, "Invalid sync status value types.\n");
  exit(1);
}
' "$tmpdir/sync-status.json" || die "dbtng:sync:status JSON is invalid."
pass "sync:status JSON contract"

http_code="$("$curl_bin" -fsS -o /dev/null -w '%{http_code}' "$uri")"
[[ "$http_code" == "200" ]] || die "Canonical HTTPS endpoint did not return HTTP 200."
pass "canonical HTTPS HTTP 200"

if [[ "$mode" == "preflight" ]]; then
  pass "Phase F read-only preflight completed"
  exit 0
fi

command -v systemctl >/dev/null 2>&1 || die "systemctl is required for --mode service."
systemctl is-enabled --quiet "$service" || die "Continuous sync service is not enabled."
systemctl is-active --quiet "$service" || die "Continuous sync service is not active."

service_user="$(systemctl show "$service" -p User --value)"
[[ -n "$service_user" && "$service_user" != "root" && "$service_user" != "0" ]] || die "Continuous sync service is configured as root."
[[ "$service_user" == "$(id -un)" ]] || die "Service user does not match the domain owner running this validation."

systemctl cat "$service" >"$tmpdir/unit.service"
"${module_root}/scripts/validate-systemd-unit.sh" "$tmpdir/unit.service" >/dev/null
pass "installed systemd unit is non-root and passes DBTNG validation"

if journalctl -u "$service" -n 200 --no-pager >"$tmpdir/journal.txt" 2>/dev/null; then
  if grep -Eiq '(password|passwd|token|secret|dsn)[[:space:]_]*=|(mysql|mariadb|https?)://[^[:space:]@]+:[^[:space:]@]+@' "$tmpdir/journal.txt"; then
    die "Recent worker journal appears to contain a credential-bearing value."
  fi
  pass "recent readable journal contains no obvious credential assignment/DSN"
else
  printf 'WARNING: journal is not readable by %s; inspect a sanitized privileged excerpt separately.\n' "$(id -un)" >&2
fi

"$php_bin" -r '
$data = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
if ($data["health"] !== "HEALTHY") {
  fwrite(STDERR, "Worker health is not HEALTHY: ".$data["health"]."\n");
  exit(1);
}
if ($data["pending_events"] !== 0) {
  fwrite(STDERR, "Pending events are not zero.\n");
  exit(1);
}
if ($data["capture_healthy"] !== true) {
  fwrite(STDERR, "Capture is not healthy.\n");
  exit(1);
}
' "$tmpdir/sync-status.json" || die "Final continuous worker health gate failed."

[[ "$(cat "$tmpdir/sync-status.exit")" == "0" ]] || die "dbtng:sync:status returned non-zero in service mode."
pass "worker HEALTHY, capture healthy and pending=0"
pass "Phase F read-only service gate completed"

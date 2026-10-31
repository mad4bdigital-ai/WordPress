#!/usr/bin/env bash
# Exact-checksum rollback; never runs WordPress, dispatch, or a new executor.
set -euo pipefail
[[ "${EUID}" -eq 0 ]] || { echo 'ROOT_REQUIRED' >&2; exit 2; }
[[ "$#" -eq 1 && ( "$1" == '--to-previous' || "$1" == '--pre-integration' ) ]] || {
 echo 'USAGE: rollback.sh --to-previous | --pre-integration' >&2; exit 2;
}
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
LIVE=/opt/mad4b-company-transport/channel-governance-v1/integration.py
UNIT=/etc/systemd/system/mad4b-dispatch-v1.service.d/90-channel-governance-integration.conf
NEW_SHA=943fdb87dd095ebbd2bd3c8bc3eaca60c685d8ff3b005f24f50afd231947ceb1
OLD_SHA=ba61105b27175055e2b811cb8341ae8f41b7c72e723483978902b2e8859440f8
UNIT_SHA=c8b93b0857a1b6b2093a89ecd820fd670d7b43bf38680b43ee92f00588600e20
sha() { sha256sum -- "$1" | cut -d ' ' -f1; }
[[ -f "$LIVE" && ! -L "$LIVE" && -f "$UNIT" && ! -L "$UNIT" ]] || { echo 'EXPECTED_ORIGINAL_FILES_MISSING' >&2; exit 3; }
[[ "$(sha "$UNIT")" == "$UNIT_SHA" ]] || { echo 'ORIGINAL_UNIT_CHANGED_ABORT' >&2; exit 3; }
[[ "$(sha "$HERE/integration.previous.py")" == "$OLD_SHA" ]] || { echo 'GIT_BACKUP_CHANGED_ABORT' >&2; exit 3; }
CURRENT="$(sha "$LIVE")"
[[ "$CURRENT" == "$NEW_SHA" || "$CURRENT" == "$OLD_SHA" ]] || { echo 'ORIGINAL_RUNTIME_CHANGED_ABORT' >&2; exit 3; }
if systemctl is-active --quiet mad4b-dispatch-v1.service; then
 echo 'DISPATCH_ACTIVE_RETRY_WHEN_IDLE' >&2; exit 4
fi
if [[ "$1" == '--to-previous' ]]; then
  install -o root -g root -m 0700 "$HERE/integration.previous.py" "$LIVE"
  [[ "$(sha "$LIVE")" == "$OLD_SHA" ]] || { echo 'ROLLBACK_READBACK_FAILED' >&2; exit 5; }
  echo 'RESTORED_PREVIOUS_7_SOURCE_INTEGRATION'
else
  # Uninstalls only the diagnostics added by channel-governance integration.
  # The original Dispatcher, WP-CLI, Broker, Grants and timer are untouched.
  rm -- "$UNIT" "$LIVE"
  echo 'REMOVED_CHANNEL_GOVERNANCE_DISPATCH_INTEGRATION_ONLY'
fi
systemctl daemon-reload
systemd-analyze verify mad4b-dispatch-v1.service
echo 'ROLLBACK_SYSTEMD_VERIFIED'

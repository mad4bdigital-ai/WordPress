#!/usr/bin/env bash
# Reproducible LOCAL-GIT installation into existing MAD4B Dispatcher.
# Does not start the service, grant authority, or execute queued jobs.
set -euo pipefail
[[ "${EUID}" -eq 0 ]] || { echo 'ROOT_REQUIRED' >&2; exit 2; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
LIVE=/opt/mad4b-company-transport/channel-governance-v1/integration.py
UNIT=/etc/systemd/system/mad4b-dispatch-v1.service.d/90-channel-governance-integration.conf
NEW_SHA=943fdb87dd095ebbd2bd3c8bc3eaca60c685d8ff3b005f24f50afd231947ceb1
OLD_SHA=ba61105b27175055e2b811cb8341ae8f41b7c72e723483978902b2e8859440f8
UNIT_SHA=c8b93b0857a1b6b2093a89ecd820fd670d7b43bf38680b43ee92f00588600e20
sha() { sha256sum -- "$1" | cut -d ' ' -f1; }
[[ "$(sha "$HERE/integration.py")" == "$NEW_SHA" ]] || { echo 'GIT_SOURCE_SHA_MISMATCH' >&2; exit 3; }
[[ "$(sha "$HERE/integration.previous.py")" == "$OLD_SHA" ]] || { echo 'GIT_ROLLBACK_SOURCE_SHA_MISMATCH' >&2; exit 3; }
[[ "$(sha "$HERE/90-channel-governance-integration.conf")" == "$UNIT_SHA" ]] || { echo 'GIT_UNIT_SHA_MISMATCH' >&2; exit 3; }
[[ -f "$LIVE" && ! -L "$LIVE" && -f "$UNIT" && ! -L "$UNIT" ]] || { echo 'EXACT_EXISTING_STAGING_DISPATCH_INSTALL_REQUIRED' >&2; exit 4; }
[[ "$(sha "$UNIT")" == "$UNIT_SHA" ]] || { echo 'ORIGINAL_UNIT_DRIFT_ABORT' >&2; exit 4; }
CURRENT="$(sha "$LIVE")"
[[ "$CURRENT" == "$NEW_SHA" || "$CURRENT" == "$OLD_SHA" ]] || { echo 'ORIGINAL_RUNTIME_DRIFT_ABORT' >&2; exit 4; }
if [[ "$CURRENT" != "$NEW_SHA" ]]; then
  systemctl is-active --quiet mad4b-dispatch-v1.service && { echo 'DISPATCH_ACTIVE_ABORT' >&2; exit 5; }
  install -o root -g root -m 0700 "$HERE/integration.py" "$LIVE"
fi
[[ "$(sha "$LIVE")" == "$NEW_SHA" ]] || { echo 'DEPLOY_READBACK_FAILED' >&2; exit 6; }
systemctl daemon-reload
systemd-analyze verify mad4b-dispatch-v1.service
echo 'LOCAL_GIT_DISPATCH_DIAGNOSTIC_INTEGRATION_VERIFIED'

#!/bin/sh
# Resource-gated SECOND WINDOW into the ORIGINAL site-owned WP-Cron worker.
# No CSS certificate or ticket can be issued by this guard; the original
# worker still re-checks all company, Chromium and CPU admission rules.
set -eu
if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [ "$1" != '--inspect' ]; };then
  exit 64
fi
BASE='/opt/wayfa-rebuild-20261102/operations/css-cron-v1'
if [ ! -f "$BASE/worker.py" ] || [ ! -f "$BASE/renderer/public-route-cpu-guard.py" ];then
  echo 'CSS_QUIET_WINDOW_SOURCE_MISSING wayfa '
  exit 65
fi
budget=$(/usr/bin/python3 "$BASE/renderer/public-route-cpu-guard.py" 2>&1) || {
  echo 'CSS_QUIET_WINDOW_DEFER wayfa ' "$budget"
  exit 0
}
case "$budget" in
  ADMIT_PUBLIC_CCSS_HOST_CPU_BUDGET*) : ;;
  *) echo 'CSS_QUIET_WINDOW_NOT_ADMITTED wayfa ' "$budget"; exit 0 ;;
esac
if [ "${1-}" = '--inspect' ];then
  echo 'CSS_QUIET_WINDOW_INSPECT_ADMITTED wayfa ' "$budget"
  exit 0
fi
echo 'CSS_QUIET_WINDOW_NATIVE_WORKER_BEGIN wayfa '
exec /usr/bin/timeout -k 10s 1500s /usr/bin/nice -n 18 /usr/bin/ionice -c 3 /usr/bin/python3 "$BASE/worker.py"

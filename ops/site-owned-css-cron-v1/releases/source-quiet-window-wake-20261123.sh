#!/bin/sh
# Resource-gated SECOND WINDOW into the ORIGINAL site-owned WP-Cron worker.
# No CSS certificate or ticket can be issued by this guard; the original
# worker still re-checks all company, Chromium and CPU admission rules.
set -eu
if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [ "$1" != '--inspect' ]; };then
  exit 64
fi
BASE='/opt/hostinger-migrations/hosting-3/etg-commerce-staging/operations/css-cron-v1'
if [ ! -f "$BASE/worker.py" ] || [ ! -f "$BASE/renderer/public-route-cpu-guard.py" ];then
  echo 'CSS_QUIET_WINDOW_SOURCE_MISSING source '
  exit 65
fi
budget=$(/usr/bin/python3 "$BASE/renderer/public-route-cpu-guard.py" 2>&1) || {
  echo 'CSS_QUIET_WINDOW_DEFER source ' "$budget"
  exit 0
}
case "$budget" in
  ADMIT_PUBLIC_CCSS_HOST_CPU_BUDGET*) : ;;
  *) echo 'CSS_QUIET_WINDOW_NOT_ADMITTED source ' "$budget"; exit 0 ;;
esac
if [ "${1-}" = '--inspect' ];then
  echo 'CSS_QUIET_WINDOW_INSPECT_ADMITTED source ' "$budget"
  exit 0
fi
echo 'CSS_QUIET_WINDOW_NATIVE_WORKER_BEGIN source '
exec /usr/bin/timeout -k 10s 1500s /usr/bin/nice -n 18 /usr/bin/ionice -c 3 /usr/bin/python3 "$BASE/worker.py"

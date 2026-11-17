#!/usr/bin/env bash
set -Eeuo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
if (( $# < 1 )); then echo "MISSING_JOB" >&2; exit 64; fi
job="$1"; shift
wait_sec=0
case "$job" in
private-mobile-wayfa)
  (( $# == 0 )) || exit 64
  cmd=(/usr/bin/flock -n -E 0 /run/lock/mad4b-private-css-family-wayfa.lock /usr/bin/python3 /opt/mad4b-company-provisioning/ops/private-mobile-css-family-v1/monitor.py);;
frontend-wayfa)
  (( $# == 0 )) || exit 64
  wait_sec=45
  cmd=(/usr/bin/python3 /opt/mad4b-company-provisioning/releases/configuration-jobs-v1/mad4b-frontend-contract-browser-v1.py wayfatours-production);;
presentation)
  (( $# == 0 )) || exit 64
  wait_sec=240
  cmd=(/opt/mad4b-company-provisioning/reports/presentation-integrity-20261108/refresh-browser-audits.sh);;
tour-story)
  (( $# == 0 )) || exit 64
  wait_sec=90
  cmd=(/opt/mad4b-company-provisioning/reports/story-observability-v2-20261111/bin/run-browser-qa.sh);;
manual)
  (( $# >= 1 && $# <= 20 )) || exit 64
  [[ "$1" == /usr/bin/timeout || "$1" == timeout ]] || { echo "MANUAL_REQUIRES_TIMEOUT" >&2; exit 64; }
  cmd=("$@");;
*) echo "UNKNOWN_JOB:$job" >&2; exit 64;;
esac
# 199 deliberately avoids the original jobs' fd8 and fd9 locks.
exec 199>/run/lock/mad4b-native-lazy-browser-global.lock
if ! flock -w "$wait_sec" 199; then
 echo "MAD4B_BROWSER_QA_DEFERRED_LOCK job=$job"
 if [[ "$job" == manual ]]; then exit 75; fi
 exit 0
fi
if [[ "$job" == manual ]]; then
 load="$(cut -d' ' -f1 /proc/loadavg)"
 if awk -v l="$load" 'BEGIN{exit !(l>=5.6)}'; then
   echo "MAD4B_BROWSER_QA_DEFERRED_CPU job=$job"
   exit 75
 fi
fi
echo "MAD4B_BROWSER_QA_ADMITTED job=$job"
exec "${cmd[@]}"

#!/bin/sh
# Staging-only optional QA. Never compete with Wayfa Production when host is busy.
set -eu
/usr/bin/python3 - <<'PY'
import os,pathlib,sys
try:
    load=os.getloadavg()[0]
    ps={}
    for k in ('cpu','io','memory'):
        ps[k]=float(pathlib.Path('/proc/pressure/'+k).read_text().splitlines()[0].split()[1].split('=')[1])
    if load>7 or ps['cpu']>17 or ps['io']>6 or ps['memory']>0.6:
        print('MAD4B_STAGING_QA_DEFERRED load=%.2f cpu_psi=%.2f io_psi=%.2f mem_psi=%.2f' % (load,ps['cpu'],ps['io'],ps['memory']))
        sys.exit(4)
except (ValueError, OSError, IndexError, KeyError):
    print('MAD4B_STAGING_QA_DEFERRED pressure_signal_unavailable')
    sys.exit(4)
PY
# The authoritative V2 post-release browser QA and this optional
# staging-only legacy probe MUST serialize on the same host-wide Chrome lock.
# The cron job's own per-probe lock remains in place. The global lock is
# nonblocking: never delay a CSS release or add another Chromium process.
# systemd-run --scope is synchronous, so flock owns the lock until the
# scoped browser/timeout exits. Resource gates above remain unchanged.
if /usr/bin/flock -n -E 75 /run/lock/mad4b-native-lazy-browser-global.lock \
  /usr/bin/systemd-run --scope --quiet --collect \
    --property=Slice=mad4bbackground.slice \
    --property=CPUQuota=80% \
    --property=IOWeight=20 \
    /usr/bin/nice -n 15 /usr/bin/ionice -c 3 \
    /usr/bin/timeout 95 /usr/bin/python3 \
    /opt/commerce-components/qa/mad4b-live-css-render-blocking.py; then
    :
else
    code=$?
    if [ "$code" -eq 75 ]; then
        echo 'MAD4B_STAGING_QA_DEFERRED original_css_browser_global_lock_busy'
        exit 0
    fi
    exit "$code"
fi

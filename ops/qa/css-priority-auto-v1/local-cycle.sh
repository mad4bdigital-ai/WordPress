#!/usr/bin/env bash
# MAD4B self-governing CSS: fully local Staging only, no GitHub/OAuth/Production dependency.
set -Eeuo pipefail
Q=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/css-priority-auto-v1
ROOT=/opt/hostinger-migrations/hosting-3/etg-commerce-staging
exec 8>/run/lock/mad4b-autonomous-css.lock
flock -n 8 || { echo 'SKIP: another CSS cycle running';exit 0; }
umask 077
cd "$Q"
mode="${1:-run}"
python3 "$Q/policy-engine.py" status >/dev/null
# Never compete with the earlier MU334 default rollout policy.
flag=$(docker exec -u www-data etg-commerce-staging-php sh -lc "cd /var/www/html; wp eval 'echo get_option(\"mad4b_css_priority_staging_en_ar_v1\",\"0\");' --quiet" | tail -1)
if [ "$flag" = 1 ];then echo 'HOLD: legacy MU334 rollout enabled; disable legacy first';exit 13;fi
# Prioritize WordPress source-change events; the periodic run still checks a fallback route.
pending=$(python3 "$Q/pending-queue.py")
if [ "$mode" = event ] && [ -z "$pending" ];then echo 'IDLE: no WordPress asset changes';exit 0;fi
if [ "$mode" != status ];then
 "$Q/cache-isolation-check.sh" || { echo 'HOLD: cache isolation proof failed; no CSS analysis or promotion';exit 12; }
fi
python3 "$Q/discover-routes.py"
if [ "$mode" = status ];then python3 "$Q/policy-engine.py" status;exit 0;fi
if [ "$mode" = smoke ];then route=/;
elif [ -n "$pending" ];then
 # WordPress already validated this published route when enqueuing.
 # Do not drop new tours or pages merely because they are outside the recent-page shortlist.
 route="$pending"
else
 route=$(python3 - "$Q/routes.json" "$Q/route-cursor.txt" <<'PY'
import json,sys,re
from pathlib import Path
x=json.load(open(sys.argv[1]));assert x['site']=='tours.dev.mad4b.com';p=x['paths'];p=sorted(p,key=lambda a:(0 if a=='/' else 1 if a=='/tours-and-activities/' else 2 if 'tours-and-activities' in a else 3,a))
c=Path(sys.argv[2]);n=int(c.read_text()) if c.exists() and c.read_text().strip().isdigit() else 0
route=p[n%len(p)];c.write_text(str(n+1)+'\n')
assert re.fullmatch(r'/[A-Za-z0-9/_~.-]*',route) and '..' not in route
print(route)
PY
)
fi
device=$(python3 "$Q/device-turn.py" choose "$route" "$mode")
if [ "$device" = ack ];then
 python3 "$Q/device-turn.py" ack "$route" event
 echo "EVENT_COMPLETED route=$route";exit 0
fi
if [ "$device" != desktop ] && [ "$device" != mobile ];then echo 'FAIL CLOSED: no device';exit 12;fi
printf '%s START route=%s device=%s mode=%s\n' "$(date -Is)" "$route" "$device" "$mode"
finish_device(){
 local result="$1"
 python3 "$Q/device-turn.py" complete "$route" "$mode" "$device" "$result" > "$Q/last-device-result.json"
 if [ "$mode" = event ] && grep -q '"done": true' "$Q/last-device-result.json";then
  python3 "$Q/device-turn.py" ack "$route" event
 fi
}
if ! timeout -k 5s 80s docker run --rm --network host --cpus=1.5 --memory=1536m -u 0:0 -v "$Q:/out" --entrypoint node mad4b-firecrawl-production-playwright-service:latest /out/css-scout.js "$route" "$device";then
 echo 'HOLD: device-specific scout failed';finish_device failed;exit 12
fi
if python3 "$Q/policy-engine.py" stage "$route" "$device";then :;else
 code=$?
 if [ "$code" -ne 2 ];then echo "HOLD: policy stage failed $code";finish_device failed;exit 13;fi
 echo "NATIVE: no new proven candidate for $device; prior verdict retained"
 finish_device native;exit 0
fi
[ "$mode" = smoke ] && { echo 'SMOKE: scoped stage complete, not certified';exit 0; }
if ! timeout -k 5s 360s docker run --rm --network host --cpus=1.5 --memory=1536m -u 0:0 -v "$Q:/out" --entrypoint node mad4b-firecrawl-production-playwright-service:latest /out/css-benchmark.js "$route" 5 "$device";then
 echo "HOLD: $device A/B incomplete; no promotion"
 finish_device failed;exit 13
fi
if python3 "$Q/policy-engine.py" verify "$route" "$device";then
 echo "$(date -Is) CERTIFIED $route $device" >> "$Q/cycle-events.log"
 finish_device certified
else
 echo "$(date -Is) HOLD $route $device" >> "$Q/cycle-events.log"
 finish_device hold
fi
printf '%s END route=%s device=%s\n' "$(date -Is)" "$route" "$device"

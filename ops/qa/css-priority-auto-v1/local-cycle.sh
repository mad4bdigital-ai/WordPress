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
printf '%s START route=%s mode=%s\n' "$(date -Is)" "$route" "$mode"
if ! timeout -k 5s 80s docker run --rm --network host --cpus=1.5 --memory=1536m -u 0:0 -v "$Q:/out" --entrypoint node mad4b-firecrawl-production-playwright-service:latest /out/css-scout.js "$route";then
 echo 'HOLD: scout failed, no promotion';exit 12
fi
if python3 "$Q/policy-engine.py" stage "$route";then :;else
 echo 'NATIVE: no safe CSS candidates found for route';
 if [ -n "$pending" ];then python3 "$Q/notification-bridge.py" "$route" native 'No safe CSS candidates';fi
 exit 0
fi
[ "$mode" = smoke ] && { echo 'SMOKE: browser discovery and policy stage succeeded, no automatic certification';exit 0; }
if ! timeout -k 5s 195s docker run --rm --network host --cpus=1.5 --memory=1536m -u 0:0 -v "$Q:/out" --entrypoint node mad4b-firecrawl-production-playwright-service:latest /out/css-benchmark.js "$route" 3;then
 echo 'HOLD: web vitals run failed, route remains canary-only';exit 13
fi
if python3 "$Q/policy-engine.py" verify "$route";then
 echo "$(date -Is) CERTIFIED $route" >> "$Q/cycle-events.log"
 python3 "$Q/notification-bridge.py" "$route" certified 'Three paired desktop-mobile A-B tests PASS'
else
 echo "$(date -Is) HOLD $route" >> "$Q/cycle-events.log"
 python3 "$Q/notification-bridge.py" "$route" hold 'Performance or visual gate failed'
fi
printf '%s END route=%s\n' "$(date -Is)" "$route"

#!/usr/bin/env bash
# MAD4B autonomous CPU diagnostics. No global JS modifications. Staging only.
set -Eeuo pipefail
Q=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/css-priority-auto-v1
SITE=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy
WP=(docker exec -u www-data -w /var/www/html etg-commerce-staging-php wp)
MODE=${1:-scheduled}
case "$MODE" in manual|scheduled|event) ;; *) echo 'Unsupported CPU cycle mode' >&2;exit 11;;esac
exec 8>/run/lock/mad4b-js-cpu-staging.lock
flock -n 8 || { echo 'JS_CPU_WORK_ALREADY_RUNNING';exit 0; }
# Prevent heavy Chrome profiling on an overloaded shared VPS.
python3 - <<'PY'
import os
load=os.getloadavg()[0]
mem=int(next(line.split()[1] for line in open('/proc/meminfo') if line.startswith('MemAvailable:')))/1024/1024
if load>9 or mem<4:raise SystemExit('CPU_RESOURCE_GUARD: load='+str(round(load,2))+' availableGiB='+str(round(mem,1)))
PY
"${WP[@]}" eval '$p=get_option("mad4b_scp_brand_context_profile_v1",[]);echo wp_json_encode(["site"=>site_url(),"env"=>wp_get_environment_type(),"uuid"=>($p["site_uuid"]??""),"epoch"=>(int)get_option("mad4b_css_ai_epoch_v1",1),"guest"=>(string)get_option("litespeed.conf.guest","0"),"defer"=>(string)get_option("litespeed.conf.optm-js_defer","0"),"mobile_cache"=>(string)get_option("litespeed.conf.cache-mobile","0"),"js_options"=>[(string)get_option("litespeed.conf.optm-js_min"),(string)get_option("litespeed.conf.optm-js_comb"),(string)get_option("litespeed.conf.optm-js_comb_ext_inl")]]);' --quiet > "$Q/js-cpu-current-site.json"
python3 - "$Q/js-cpu-current-site.json" <<'PY'
import json,sys
x=json.load(open(sys.argv[1]));assert x['site']=='https://tours.dev.mad4b.com' and x['env']=='staging' and x['uuid']=='e5e64bca-ed62-48d2-b2b3-dff693cd702a' and x['defer']=='0' and x['mobile_cache']=='1', 'SCOPE_GUARD_FAIL'
PY
# Include current runtime implementations in source fingerprint; a new epoch or update invalidates stale HOLD.
sha256sum "$SITE/wp-content/mu-plugins/337-mad4b-device-js-combine-policy.php" "$SITE/wp-content/mu-plugins/338-mad4b-js-cpu-intelligence.php" "$SITE/wp-includes/js/jquery/jquery.min.js" | sha256sum | awk '{print $1}' > "$Q/js-cpu-asset-digest.txt"
python3 - "$Q/js-cpu-current-site.json" "$Q/js-cpu-asset-digest.txt" <<'PY'
import json,sys
p=sys.argv[1];a=json.load(open(p));a['asset_digest']=open(sys.argv[2]).read().strip();open(p,'w').write(json.dumps(a))
PY
case "$MODE" in
 manual) path=${2:-/};;
 event)
  "${WP[@]}" eval 'echo wp_json_encode(MAD4B_JS_CPU_Intelligence::queue());' --quiet > "$Q/js-cpu-dirty-queue.json"
  path=$(python3 - "$Q/js-cpu-dirty-queue.json" <<'PY'
import json,sys
rows=json.load(open(sys.argv[1]));print(rows[-1]['path'] if rows else '')
PY
)
  [ -n "$path" ] || { echo 'JS_CPU_NO_DIRTY_ROUTE';exit 0; }
  ;;
 scheduled)
  path=$(python3 - "$Q/routes.json" "$Q/js-cpu-route-cursor.txt" <<'PY'
import json,sys,re
x=json.load(open(sys.argv[1]));assert x.get('site')=='tours.dev.mad4b.com'
paths=[p for p in x['paths'] if re.fullmatch(r'/[a-zA-Z0-9/_~.\-]{0,239}',p) and '..' not in p and not re.search(r'/(cart|checkout|account|favourites|my-account|booking|shop|wp-admin|wp-json)(/|$)',p,re.I)]
assert paths
try:i=int(open(sys.argv[2]).read().strip())
except (ValueError,OSError):i=0
print(paths[i%len(paths)])
open(sys.argv[2],'w').write(str(i+1))
PY
)
  ;;
esac
if ! [[ "$path" =~ ^/[a-zA-Z0-9/_~.\-]{0,239}$ ]] || [[ "$path" == *..* ]];then echo 'INVALID_CPU_ROUTE' >&2;exit 12;fi
printf '%s ANALYZE JS CPU %s mode=%s\n' "$(date -Is)" "$path" "$MODE"
# Browser is resource bounded, no secret credentials, no site mutation, and Native CSS + Native JS only.
timeout -k 6s 135s docker run --rm --network host --cpus=1.2 --memory=1280m -u 0:0 -v "$Q:/out" --entrypoint node mad4b-firecrawl-production-playwright-service:latest /out/jquery-cpu-profile.js "$path"
python3 "$Q/js-cpu-policy.py" publish "$Q/js-cpu-current-site.json" "$Q/jquery-cpu-profile.json"
# Read-only diagnostics are surfaced in the WordPress dashboard and internal notifications.
"${WP[@]}" eval '$d=MAD4B_JS_CPU_Intelligence::summary();$f=end($d["rows"]);echo "CPU_ROUTES=".count($d["rows"])." at=".$d["last_updated"]."\n";' --quiet
"${WP[@]}" eval '$d=MAD4B_JS_CPU_Intelligence::summary();$n=0;foreach($d["rows"] as $x){if($x["samples"]>0)$n++;}if($n){MAD4B_SCP_Adaptive_Asset_Intelligence::emit("hold","info","'"$path"'","JavaScript CPU analysis updated; per-device trials require A/B verification",["digest"=>"js-cpu-".gmdate("YmdHi",(int)$d["last_updated"])."-".(string)get_option("mad4b_css_ai_epoch_v1",1)]);}echo "CPU_DEVICES_MEASURED=".$n."\n";' --quiet
if [ "$MODE" = event ];then
 "${WP[@]}" eval '$path="'"$path"'";$q=MAD4B_JS_CPU_Intelligence::queue();$q=array_values(array_filter($q,static fn($x)=>($x["path"]??"")!==$path));update_option(MAD4B_JS_CPU_Intelligence::OPTION,$q,false);echo "CPU_DIRTY_QUEUE_REMAINING=".count($q)."\n";' --quiet
fi
printf '%s JS_CPU_DONE path=%s\n' "$(date -Is)" "$path"

#!/usr/bin/env bash
# Site-scoped, permanently scheduled native critical-CSS recertification.
# Source drift retains native CSS until paired Chromium checks pass again.
set -euo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
SITE="$1"
ROOT=/opt/mad4b-company-provisioning
TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$ROOT/reports/dynamic-critical-css-20261116"
IMAGE=mad4b-firecrawl-playwright:20261107-lifecycle-v3
case "$SITE" in
 source)
   HOST=tours.dev.mad4b.com;ENVIRONMENT=staging
   WP=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content
   PHP=php-wkgtkzta35wkkgouwt6ruljc;MANIFEST_VERSION=2
   CONSUMER_SHA=75e305345f46af0ec4dbed36f40def63626108de85f938238853b27674c11794
   QA_WIDTHS=320,390,414,767,768
   CSS_PRIORITY=high;CSS_PRELOAD=1;ADAPTIVE_MAX=767
   ;;
 wayfa)
   HOST=wayfatours.com;ENVIRONMENT=production
   WP=/opt/wayfa-rebuild-20261102/site-copy/wp-content
   PHP=php-guxhp9y6vgh7oouuw8plxrlr;MANIFEST_VERSION=1
   CONSUMER_SHA=cdb55bd9ab6a86df26483450e33cf0bbc91296940db51102dde082fdb839db5b
   QA_WIDTHS=320,390,414,768
   CSS_PRIORITY=low;CSS_PRELOAD=0;ADAPTIVE_MAX=0
   ;;
 *) echo 'INVALID_SITE: allowed source or wayfa' >&2; exit 2;;
esac
mkdir -p "$OUT/$SITE"
exec 9>"/run/lock/mad4b-critical-css-$SITE.lock"
if ! flock -n 9;then echo "SKIP $SITE another critical-css cycle";exit 0;fi
test -d "$WP" && test -f "$WP/mad4b-critical-css-v1/manifest.json"
test "$(sha256sum "$WP/mu-plugins/415-mad4b-critical-css-orchestrator.php"|cut -d' ' -f1)" = "$CONSUMER_SHA" ||
  { echo "HOLD $SITE live native consumer drift";exit 5; }
test -r "$TOOLS/tools/capture.js" && test -r "$TOOLS/tools/assemble.js" &&
test -r "$TOOLS/tools/verify.js" && test -r "$TOOLS/tools/publish.py"
STATE="$OUT/$SITE/current-site.json"
docker exec "$PHP" wp --allow-root eval '
 $home=strtolower((string)wp_parse_url(home_url("/"),PHP_URL_HOST));
 $langs=(array)(get_option("icl_sitepress_settings",[])["active_languages"]??[]);
 $langs=array_values(array_unique(array_filter($langs,static fn($x)=>is_string($x)&&preg_match("/^[a-z]{2}$/D",$x))));
 $last=0;
 foreach((array)get_option("mad4b_adaptive_asset_job_queue_v1",[]) as $job)
   if(!empty($job["home_css_dependency"]))
     $last=max($last,(int)($job["updated"]??0));
 $bp=null;
 if(class_exists("Elementor\\Plugin",false)){
   $manager=\Elementor\Plugin::$instance->breakpoints??null;
   if(is_object($manager)&&method_exists($manager,"get_breakpoints")){
     $all=$manager->get_breakpoints();
     $bp=$all["mobile"]->get_value()??null;
   }
 }
 echo wp_json_encode([
  "contract"=>"mad4b.critical-css.site-source.v1","site_host"=>$home,
  "environment"=>wp_get_environment_type(),
  "critical_css"=>(string)get_option("mad4b_perf_critical_css_v1","off"),
  "css_epoch"=>(int)get_option("mad4b_css_ai_epoch_v1",0),
  "native_breakpoint"=>$bp,
  "wpml_languages"=>$langs,
  "default_language"=>(string)(get_option("icl_sitepress_settings",[])["default_language"]??""),
  "last_home_impact_at"=>$last,
  "critical_css_dirty_generation"=>(int)get_option("mad4b_critical_css_home_dirty_v1",0),
  "runtime_versions"=>[
    "elementor"=>defined("ELEMENTOR_VERSION")?ELEMENTOR_VERSION:"",
    "jet_engine"=>defined("JET_ENGINE_VERSION")?JET_ENGINE_VERSION:"",
    "wpml"=>defined("ICL_SITEPRESS_VERSION")?ICL_SITEPRESS_VERSION:"",
    "theme"=>(string)wp_get_theme()->get("Version"),
    "active_kit"=>(int)get_option("elementor_active_kit",0)
  ]
 ],JSON_UNESCAPED_SLASHES),"\n";
' 2>"$OUT/$SITE/wpcli-stderr.log" > "$STATE"
python3 - "$STATE" "$HOST" "$ENVIRONMENT" <<'PY'
import json,sys
x=json.load(open(sys.argv[1]))
assert x['contract']=='mad4b.critical-css.site-source.v1'
assert x['site_host']==sys.argv[2] and x['environment']==sys.argv[3]
assert x['critical_css']=='on' and int(x['css_epoch'])>0
assert x['native_breakpoint']==767
assert isinstance(x['wpml_languages'],list) and x['default_language'] in x['wpml_languages']
assert 1<=len(x['wpml_languages'])==len(set(x['wpml_languages']))<=20
assert all(__import__('re').fullmatch(r'[a-z]{2}',v) for v in x['wpml_languages'])
print('SITE_SOURCE_TRUSTED',x['site_host'],x['css_epoch'],x['wpml_languages'])
PY
python3 - "$STATE" "$OUT/$SITE/epoch-current.json" <<'PY'
import json,sys
x=json.load(open(sys.argv[1]))
with open(sys.argv[2],'w') as p:
 json.dump({'contract':'mad4b.native-lazy.css-source-observation.v1',
            'epoch':x['css_epoch'],'last_home_impact_at':x['last_home_impact_at']},p)
PY
if ! python3 "$TOOLS/epoch-gate.py" "$OUT/$SITE/epoch-current.json" "$OUT/$SITE/epoch-state.json" 600;then
 echo "HOLD $SITE CSS epoch not quiet; retry on next recurring run"
 exit 0
fi
# Before the content-addressed event gate, sample at most once per hour
# the two query-free anonymous Wayfa home source CSS files. A live
# combined CSS filename/SHA change (even without a WP option event)
# invalidates an old home proof. This HTTP signal can NEVER certify a
# browser variant or bypass the original five-width Chromium gate.
# No source staging or customer billing/public route data is modified.
if [[ "$SITE" == wayfa ]];then
 if ! timeout 65 python3 "$TOOLS/wayfa-home-source-observer.py" wayfa;then
   echo 'HOLD_WAYFA_HOME_SOURCE_SIGNAL_UNAVAILABLE; prior first-party signal preserved'
 fi
fi
# Event-driven gate: 15-minute timer performs only cheap WP/source hash
# checking when observed source identity is unchanged. The daily audit
# independently monitors the actual delivery and fallback profile.
EVENT_LEDGER="$OUT/$SITE/event-gate-state.json"
if python3 "$TOOLS/event-gate.py" decide "$SITE" "$STATE"   "$WP/mad4b-critical-css-v1/manifest.json"   "$WP/mu-plugins/415-mad4b-critical-css-orchestrator.php"   "$EVENT_LEDGER";then
  :
else
  rc=$?
  if [[ "$rc" -eq 10 ]];then
    echo "SKIP $SITE unchanged Critical CSS sources; no Chromium"
    exit 0
  fi
  echo "HOLD $SITE invalid event-gate state ($rc)"
  exit "$rc"
fi
GATE_STATUS=hold
GATE_REASON=source_unavailable
record_event_gate(){
  python3 "$TOOLS/event-gate.py" complete "$SITE" "$STATE"     "$WP/mad4b-critical-css-v1/manifest.json"     "$WP/mu-plugins/415-mad4b-critical-css-orchestrator.php"     "$EVENT_LEDGER" "$GATE_STATUS" "$GATE_REASON" || true
}
trap record_event_gate EXIT
# Lock expensive browser only after content fingerprint admits a real
# certification run. A no-change timer never starves company page workers.
exec 8>/run/lock/mad4b-native-lazy-browser-global.lock
if ! flock -n 8;then
 echo "SKIP $SITE shared browser busy; retry during next timer"
 GATE_STATUS=pending
 GATE_REASON=browser_busy
 exit 0
fi
# Avoid costly six-language fetches when another VPS task saturates CPU.
if ! python3 - <<'EARLY_LOAD'
import os,sys
cpus=os.cpu_count() or 1
load=os.getloadavg()[0]
limit=max(4.0,cpus*1.75)
print('CSS_IDLE_ADMISSION',f'load1={load:.2f}',f'cores={cpus}',f'limit={limit:.2f}')
if load>limit:sys.exit(3)
EARLY_LOAD
then
 echo "HOLD $SITE CPU pressure; native CSS retained, next timer will retry"
 exit 0
fi
stamp=$(date -u +%Y%m%dT%H%M%SZ)
JOB="$OUT/$SITE/$stamp"
mkdir -m 700 "$JOB" "$JOB/observed"
cp "$STATE" "$JOB/site.json"
USER_AGENT='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36'
ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8'
python3 - "$STATE" <<'PY' > "$JOB/locales.txt"
import json,sys
print('\n'.join(json.load(open(sys.argv[1]))['wpml_languages']))
PY
# The browser receives a CSS variant different from curl despite identical
# UA/Accept text (client hints and LiteSpeed negotiation are involved).
# The same real Chromium profile MUST own both certificate discovery and
# the last-moment source identity check, or QA can never publish safely.
probe_public(){
 local LANGCSV="$1" DIRECTORY="$2"
 timeout 425 docker run --rm --user 0:0 --memory=1024m --cpus=1.3    --pids-limit=250 --security-opt=no-new-privileges    -v "$TOOLS/tools:/tools:ro" -v "$JOB:/out"    -e NODE_PATH=/usr/src/app/node_modules    -e "MAD4B_SOURCE_SITE=https://$HOST"    -e "MAD4B_PROBE_OUTPUT=/out/$DIRECTORY"    -e "MAD4B_PROBE_LANGS=$LANGCSV" -e "MAD4B_DEFAULT_LANG=$DEFAULT_LANG"    "$IMAGE" node /tools/probe-public.js
}
DEFAULT_LANG=$(python3 - "$STATE" <<'LANG'
import sys,json
print(json.load(open(sys.argv[1]))['default_language'])
LANG
)
LOCALES=$(paste -sd, "$JOB/locales.txt")
# Wayfatours: an old cached home generation can still contain an
# earlier product/destination navigation activation graph even when CSS
# bytes remain unchanged. Advance only the two authenticated EN/ES public
# homepage cache keys ONCE per exact WordPress dependency identity, before
# collecting browser evidence. Never infer equivalence from CSS alone.
if [[ "$SITE" == wayfa ]];then
 if ! python3 "$TOOLS/wayfa-home-preflight.py"     "$STATE" "$WP/mad4b-critical-css-v1/manifest.json"     "$WP/mu-plugins/415-mad4b-critical-css-orchestrator.php"     "$OUT/wayfa/home-qa-freshness.json" "$JOB/wayfa-home-qa-freshness.json";then
   echo 'HOLD wayfa: exact home cache preflight failed; native CSS retained'
   exit 0
 fi
fi
if ! probe_public "$LOCALES" observed > "$JOB/probe-initial.log" 2>&1;then
 echo "HOLD $SITE Chromium source discovery failed; native CSS retained"
 tail -5 "$JOB/probe-initial.log"
 exit 0
fi
python3 "$TOOLS/assess.py" "$SITE" "$WP" "$STATE" "$JOB/observed" > "$JOB/selection.json"
# Recovery from an earlier successful PHP certificate that was followed
# by a missed/failed Nginx home epoch rollover. Reuse the installed proof
# only if BOTH actual EN/ES public CSS source hashes match v1 manifest.
# This does not issue or relabel any certificate.
if [[ "$SITE" == wayfa ]];then
 python3 - "$JOB/selection.json" "$WP/mad4b-critical-css-v1/manifest.json" "$JOB/cache-recovery-inventory.json" <<'CACHE_PROOFS'
import json,sys
routes=json.load(open(sys.argv[1]))['routes']
manifest=json.load(open(sys.argv[2]))
if (len(routes)==2 and {r['locale'] for r in routes}=={'en','es'}
    and all(r.get('manifest_match') is True for r in routes)
    and any(r.get('status')!='certified_active' for r in routes)):
    rows=[]
    for r in routes:
        meta=manifest['entries'][r['css_sha']]
        rows.append({'lang':r['locale'],'source_sha':r['css_sha'],
                     'critical_sha':meta['critical_sha']})
    with open(sys.argv[3],'w') as f:
        json.dump({'contract':'mad4b.mobile-critical.candidates.v1',
                   'host':'wayfatours.com','errors':[],'candidates':rows},f)
CACHE_PROOFS
 if [[ -s "$JOB/cache-recovery-inventory.json" ]];then
   if python3 "$TOOLS/wayfa-home-cache-refresh.py"       "$WP/mad4b-critical-css-v1/manifest.json"       "$JOB/cache-recovery-inventory.json"       "/opt/wayfa-rebuild-20261102/stack/nginx.conf"       "/opt/mad4b-company-provisioning/ops/dynamic-critical-css-20261116"       > "$JOB/cache-recovery.log" 2>&1;then
      if ! probe_public en,es observed > "$JOB/probe-after-cache.log" 2>&1;then
       echo "HOLD wayfa mobile cache renewed but Chromium readback failed"
       exit 0
      fi
      python3 "$TOOLS/assess.py" "$SITE" "$WP" "$STATE" "$JOB/observed" > "$JOB/selection.json"
   else
      echo "HOLD wayfa existing CSS proof valid but home FastCGI refresh denied"
      tail -4 "$JOB/cache-recovery.log"
      exit 0
   fi
 fi
fi
# A valid v2 Critical CSS manifest can be hidden by an old Ready:0
# Nginx source FastCGI HTML generation. Reuse signed-off native evidence
# and selectively version exact enrolled WPML homepage routes.
if [[ "$SITE" == source ]];then
 if python3 "$TOOLS/source-home-cache-release.py" recover  "$STATE" "$WP/mad4b-critical-css-v1/manifest.json" "$JOB/selection.json"  "$WP" "$JOB/source-home-cache-recovery.json" > "$JOB/source-home-cache-recovery.log" 2>&1;then
  RECOVERED=$(python3 - "$JOB/source-home-cache-recovery.json" <<'RECOVERY'
import json,sys
print(len(json.load(open(sys.argv[1]))['paths']))
RECOVERY
)
  if [[ "$RECOVERED" -gt 0 ]];then
   echo "SOURCE_CERTIFIED_HOME_CACHE_RECOVERED $RECOVERED"
   if ! probe_public "$LOCALES" observed > "$JOB/probe-after-source-recovery.log" 2>&1;then
    echo 'HOLD source Chromium readback failed after selective cache recovery'
    exit 0
   fi
   python3 "$TOOLS/assess.py" "$SITE" "$WP" "$STATE" "$JOB/observed" > "$JOB/selection.json"
  fi
 else
  echo 'SOURCE_CERTIFIED_CACHE_RECOVERY_NOT_APPLICABLE'
  tail -2 "$JOB/source-home-cache-recovery.log"
 fi
fi
PROGRESS="$OUT/$SITE/coverage-progress.json"
python3 "$TOOLS/coverage-queue.py" select  "$JOB/selection.json" "$STATE" "$PROGRESS" "$JOB/batch.txt"
LANGS=$(cat "$JOB/batch.txt")
if [[ -z "$LANGS" ]];then
 python3 - "$JOB/selection.json" "$SITE" <<'COVERAGE_STATE'
import json,sys
routes=json.load(open(sys.argv[1]))['routes']
ready=[r['locale'] for r in routes if r['status']=='certified_active']
notready=[(r['locale'],r['status']) for r in routes if r['status']!='certified_active']
if notready:print('HOLD',sys.argv[2],'WPML languages not all certified',notready,'certified',ready)
else:print('READY',sys.argv[2],'all current public home CSS native-certified',ready)
COVERAGE_STATE
 if python3 - "$JOB/selection.json" <<'READY'
import json,sys
rows=json.load(open(sys.argv[1]))['routes']
raise SystemExit(0 if rows and all(r['status']=='certified_active' for r in rows) else 1)
READY
 then GATE_STATUS=ready;GATE_REASON=certified;fi
 exit 0
fi
# Never measure LCP/FCP against a CPU-saturated VPS. The test browser is
# CPU-throttled, but unrelated root tasks can still bias real response times.
# Source capture or certification under this load could admit a regression.
if ! python3 - <<'LOAD_GUARD'
import os,sys
cpus=os.cpu_count() or 1
load=os.getloadavg()[0]
limit=max(4.0,cpus*1.75)
if load>limit:
 print('HOLD: high VPS load prevents trustworthy browser QA',
       f'load1={load:.2f}',f'cores={cpus}',f'limit={limit:.2f}')
 sys.exit(3)
print('QA_LOAD_OK',f'load1={load:.2f}',f'cores={cpus}')
LOAD_GUARD
then
 echo "HOLD $SITE: browser QA deferred until resource contention ends"
 GATE_STATUS=pending
 GATE_REASON=host_cpu_busy
 exit 0
fi
echo "CAPTURE $SITE $LANGS"
browser_step(){
 local file="$1"
 timeout 1300 docker run --rm --user 0:0 --memory=1024m --cpus=1.3 \
   --pids-limit=250 --security-opt=no-new-privileges \
   -v "$TOOLS/tools:/tools:ro" -v "$JOB:/out" \
   -v "$WP/litespeed/css:/source-css:ro" \
   -e NODE_PATH=/usr/src/app/node_modules \
   -e MAD4B_OUTPUT=/out -e MAD4B_CSS_ROOT=/source-css \
   -e "MAD4B_SOURCE_SITE=https://$HOST" -e "MAD4B_LANGS=$LANGS" -e "MAD4B_DEFAULT_LANG=$DEFAULT_LANG" \
   -e MAD4B_WIDTHS=320,390,414,768 \
   -e "MAD4B_QA_LANGS=$LANGS" -e "MAD4B_QA_WIDTHS=$QA_WIDTHS" \
   -e "MAD4B_CSS_PRIORITY=$CSS_PRIORITY" -e "MAD4B_CSS_PRELOAD=$CSS_PRELOAD" \
   -e "MAD4B_CSS_ADAPTIVE_MAX=$ADAPTIVE_MAX" \
   -e MAD4B_HERO_900_CANDIDATE=0 -e MAD4B_FIRST_CITY_DEFER_CANDIDATE=0 \
   "$IMAGE" node "/tools/$file"
}
if ! browser_step capture.js > "$JOB/capture.log" 2>&1;then
 python3 "$TOOLS/coverage-queue.py" record   "$JOB/selection.json" "$STATE" "$PROGRESS" "$JOB/batch.txt"   "$JOB/verification.json" capture_failure
 echo "HOLD $SITE public CSS capture failed; other WPML languages will be attempted"
 tail -3 "$JOB/capture.log"
 exit 0
fi
if ! browser_step assemble.js > "$JOB/assemble.log" 2>&1;then
 python3 "$TOOLS/coverage-queue.py" record   "$JOB/selection.json" "$STATE" "$PROGRESS" "$JOB/batch.txt"   "$JOB/verification.json" assembly_failure
 echo "HOLD $SITE safe CSS assembly failed; other WPML languages will be attempted"
 tail -3 "$JOB/assemble.log"
 exit 0
fi
python3 - "$JOB/candidates.json" "$LANGS" <<'PY'
import json,sys
x=json.load(open(sys.argv[1]));desired=sys.argv[2].split(',')
assert x['contract']=='mad4b.mobile-critical.candidates.v1'
assert not x['errors']
assert len(x['candidates'])==len(desired)
assert set(r['lang'] for r in x['candidates'])==set(desired)
assert all(r['widths']==[320,390,414,768] and r['source_bytes']>16000 for r in x['candidates'])
print('CAPTURE_SOURCES_STABLE',[r['source_sha'][:12] for r in x['candidates']])
PY
browser_step verify.js > "$JOB/browser-qa.log" 2>&1 ||
   { echo "HOLD $SITE Chromium paired CSS QA failed";tail -4 "$JOB/browser-qa.log";exit 0;}
# Self-heal 767px-only LCP regression without changing Elementor. A
# narrower submobile cap is an independent seven-width Chromium
# experiment, not a permission to reuse the failed 767px certificate.
if [[ "$SITE" == source ]];then
 cp "$JOB/verification.json" "$JOB/verification-primary-767.json"
 if ! python3 "$TOOLS/safe-cap-fallback.py" select    "$JOB/verification-primary-767.json" "$JOB/candidates.json"    "$JOB/selection.json" "$STATE" "$JOB/safe-cap"    > "$JOB/safe-cap-selection.log" 2>&1;then
   echo "HOLD source narrower-CSS discovery failed; primary QA kept"
   tail -3 "$JOB/safe-cap-selection.log"
 else
   SAFE_LANGS=$(cat "$JOB/safe-cap/batch.txt")
   if [[ -n "$SAFE_LANGS" ]];then
     echo "SOURCE_SAFE_CAP_599_CHROMIUM_PROBE $SAFE_LANGS"
     if timeout 1800 docker run --rm --user 0:0        --memory=1024m --cpus=1.3 --pids-limit=250        --security-opt=no-new-privileges        -v "$TOOLS/tools:/tools:ro" -v "$JOB:/out"        -e NODE_PATH=/usr/src/app/node_modules        -e MAD4B_OUTPUT=/out/safe-cap        -e "MAD4B_SOURCE_SITE=https://$HOST"        -e "MAD4B_QA_LANGS=$SAFE_LANGS"        -e MAD4B_QA_WIDTHS=320,390,414,599,600,767,768        -e MAD4B_CSS_PRIORITY=high -e MAD4B_CSS_PRELOAD=1        -e MAD4B_CSS_ADAPTIVE_MAX=599        -e MAD4B_HERO_900_CANDIDATE=0        -e MAD4B_FIRST_CITY_DEFER_CANDIDATE=0        "$IMAGE" node /tools/verify.js > "$JOB/safe-cap-chromium-qa.log" 2>&1;then
       if ! python3 "$TOOLS/safe-cap-fallback.py" merge          "$JOB/verification-primary-767.json" "$JOB/safe-cap/verification.json"          "$JOB/safe-cap/decision.json" "$JOB/verification.json"          > "$JOB/safe-cap-merge.log" 2>&1;then
         echo "HOLD source narrower fallback proof invalid; original QA restored"
         cp "$JOB/verification-primary-767.json" "$JOB/verification.json"
       else
         tail -2 "$JOB/safe-cap-merge.log"
       fi
     else
       echo "HOLD source narrower Chromium QA failed; primary QA retained"
       tail -3 "$JOB/safe-cap-chromium-qa.log"
     fi
   fi
 fi
fi
# Each active WPML locale receives its OWN complete QA decision.
# A failed French candidate must not block an independently passing German
# candidate, nor keep untouched Arabic/Italian candidates out of the queue.
python3 "$TOOLS/coverage-queue.py" record  "$JOB/selection.json" "$STATE" "$PROGRESS" "$JOB/batch.txt"  "$JOB/verification.json" none
if ! python3 "$TOOLS/qa-admission.py" "$SITE"  "$JOB/batch.txt" "$JOB/candidates.json" "$JOB/verification.json"  "$JOB/selection.json" "$JOB/locale-admission.json"  "$JOB/admitted-candidates.json";then
 echo "HOLD $SITE no fully certified language in batch; fair queue advances"
 exit 0
fi
ADMITTED_LANGS=$(python3 - "$JOB/locale-admission.json" <<'LANGS'
import json,sys
print(','.join(json.load(open(sys.argv[1]))['accepted']))
LANGS
)
[[ -n "$ADMITTED_LANGS" ]] || { echo 'HOLD_EMPTY_LOCALE_ADMISSION';exit 0; }
echo "ADMITTED_LOCALES_FOR_FINAL_PROOF $ADMITTED_LANGS"
# Re-read each exact public locale source after all browser measurements.
# Elementor translations may change mid-audit without changing CSS bytes.
# Never reuse an old page/document fingerprint from a frozen browser HTML.
mkdir -m 700 "$JOB/recheck"
if ! probe_public "$ADMITTED_LANGS" recheck > "$JOB/probe-recheck.log" 2>&1;then
 echo "HOLD $SITE final Chromium source recheck failed"
 tail -4 "$JOB/probe-recheck.log"
 exit 0
fi
if ! python3 "$TOOLS/verify-source-revision.py"  "$JOB/admitted-candidates.json" "$JOB/recheck" "$WP/litespeed/css"  > "$JOB/final-source-identity.json";then
 echo "HOLD $SITE source changed during browser QA"
 GATE_STATUS=pending
 GATE_REASON=source_changed_during_qa
 exit 0
fi
AFTER="$JOB/site-after.json"
docker exec "$PHP" wp --allow-root eval '
 echo wp_json_encode([
 "css_epoch"=>(int)get_option("mad4b_css_ai_epoch_v1",0),
 "site_host"=>(string)wp_parse_url(home_url("/"),PHP_URL_HOST),
 "critical_css"=>(string)get_option("mad4b_perf_critical_css_v1","off")
 ],JSON_UNESCAPED_SLASHES),"\n";
' 2>"$JOB/wpcli-after-stderr.log" > "$AFTER"
if ! python3 - "$STATE" "$AFTER" "$HOST" <<'PY'
import json,sys
before,after=map(lambda x:json.load(open(x)),sys.argv[1:3])
assert before['css_epoch']==after['css_epoch'], 'CSS EPOCH CHANGED DURING QA'
assert after['site_host']==sys.argv[3] and after['critical_css']=='on'
PY
then
 echo "HOLD $SITE WordPress epoch/profile changed during browser QA"
 exit 0
fi
EPOCH=$(python3 - "$STATE" <<'PY'
import json,sys
print(int(json.load(open(sys.argv[1]))['css_epoch']))
PY
)
echo "PUBLISH_PREFLIGHT $SITE epoch=$EPOCH"
publish_step(){
 local DRY="$1"
 local COMPAT=0
 if [[ "$MANIFEST_VERSION" -eq 1 ]];then COMPAT=1;fi
 env MAD4B_CSS_NATIVE_V1_COMPAT="$COMPAT" MAD4B_WP_CONTENT="$WP" MAD4B_OUTPUT="$JOB" \
   MAD4B_TARGET_HOST="$HOST" MAD4B_CSS_EPOCH="$EPOCH" \
   MAD4B_CSS_LEGACY_CONSUMER_SHA256="$CONSUMER_SHA" \
   MAD4B_CSS_ADAPTIVE_CONSUMER_SHA256="$CONSUMER_SHA" \
   MAD4B_CSS_ADMITTED_CANDIDATES="$([[ "$SITE" == source ]] && printf admitted-candidates.json)" \
   MAD4B_DRY_RUN="$DRY" \
   timeout 120 python3 "$TOOLS/tools/publish.py"
}
publish_step 1 > "$JOB/publish-dryrun.log" 2>&1 ||
 { echo "HOLD $SITE native publisher refused dry-run";tail -6 "$JOB/publish-dryrun.log";exit 0;}
publish_step 0 > "$JOB/publish.log" 2>&1 ||
 { echo "HOLD $SITE native publisher refused commit";tail -6 "$JOB/publish.log";exit 0;}
# Publication is a necessary but insufficient proof of public activation:
# bump only exact enrolled source home paths, then independently verify the
# real Chromium HTML, CSS SHA and Elementor document fingerprint.
if [[ "$SITE" == source ]];then
 if ! python3 "$TOOLS/source-home-cache-release.py" promote  "$STATE" "$WP/mad4b-critical-css-v1/manifest.json"  "$JOB/promotion-readback.json" "$WP" "$JOB/source-home-cache-release.json"  > "$JOB/source-home-cache-release.log" 2>&1;then
  echo 'HOLD source certificate published but selective cache release failed'
  tail -4 "$JOB/source-home-cache-release.log"
  exit 0
 fi
 mkdir -m 700 "$JOB/released"
 if ! probe_public "$ADMITTED_LANGS" released > "$JOB/public-release-probe.log" 2>&1;then
  echo 'HOLD source certificate published but public Chromium readback failed'
  exit 0
 fi
 if ! python3 - "$TOOLS/assess.py" "$WP" "$STATE" "$JOB/released" "$ADMITTED_LANGS" <<'READBACK'
import importlib.util,json,sys,pathlib
fn,root,state,report,langs=sys.argv[1:]
spec=importlib.util.spec_from_file_location('assess',fn)
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
root=pathlib.Path(root);state=json.load(open(state));report=pathlib.Path(report)
for lang in langs.split(','):
 result=module.classify('source',root,state,lang,
      (report/(lang+'.html')).read_text(errors='replace'),
      (report/(lang+'.headers')).read_text().replace(chr(13),''))
 if result['status']!='certified_active':
  raise SystemExit('HOLD_SOURCE_PUBLIC_DELIVERY_NOT_ACTIVE:'+lang+':'+str(result))
 print('PUBLIC_CHROMIUM_CERTIFICATE_ACTIVE',lang,result['css_sha'][:16])
READBACK
 then
  echo 'HOLD source certificate issued but public Chromium has not confirmed delivery'
  exit 0
 fi
fi
# On Wayfatours only, rotate the EN/ES home FastCGI key after a fresh
# content-addressed v1 Critical CSS certificate publishes. Browser QA
# success alone is not sufficient: verify exact final assets are readable
# by PHP and preserve the prior Nginx config until nginx -t passes.
if [[ "$SITE" == wayfa ]];then
 if ! python3 "$TOOLS/wayfa-home-cache-refresh.py"     "$WP/mad4b-critical-css-v1/manifest.json"     "$JOB/candidates.json"     "/opt/wayfa-rebuild-20261102/stack/nginx.conf"     "/opt/mad4b-company-provisioning/ops/dynamic-critical-css-20261116"     > "$JOB/home-cache-release.log" 2>&1;then
  echo "HOLD wayfa: certificate published; home FastCGI cache refresh pending"
  tail -4 "$JOB/home-cache-release.log"
  exit 0
 fi
fi
GATE_STATUS=pending
GATE_REASON=post_publish_public_readback_pending
echo "CERTIFIED $SITE $ADMITTED_LANGS epoch=$EPOCH manifest=$(sha256sum "$WP/mad4b-critical-css-v1/manifest.json"|cut -d' ' -f1)"

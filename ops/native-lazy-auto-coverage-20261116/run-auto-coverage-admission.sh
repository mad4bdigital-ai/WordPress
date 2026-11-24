#!/usr/bin/env bash
# Autonomous source-backed QA and cert admission for already-discovered cohorts.
# Never enables unproven, personal, cross-market or low-volume lists.
set -euo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
SRC=/opt/mad4b-company-provisioning/runtime-source/native-lazy-discovery-20261124
APP="$SRC/ops/native-lazy-auto-coverage-20261116"
LIVE=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy
REPORT=/opt/mad4b-company-provisioning/reports/native-lazy-coverage-discovery-20261116
OUTPUT=/opt/mad4b-company-provisioning/reports/native-lazy-coverage-admission-20261116
PHP=php-wkgtkzta35wkkgouwt6ruljc
BROWSER=playwright-service-8ckuu8cyb4axyzkl3zqcn1z9
mkdir -p "$OUTPUT"
exec 9>/run/lock/mad4b-native-lazy-auto-admission.lock
if ! flock -n 9;then echo 'SKIP: coverage admission running';exit 0;fi
exec 8>/run/lock/mad4b-native-lazy-browser-global.lock
if ! flock -n 8;then echo 'SKIP: another governed browser audit owns the shared lock';exit 0;fi
# Respect existing browser resource ceiling; never weaken admission.
load="$(cut -d' ' -f1 /proc/loadavg)"
if awk -v l="$load" 'BEGIN{exit !(l>=5.6)}'; then
 echo 'SKIP: browser QA resource ceiling reached';exit 0
fi
test -e "$REPORT/latest"
for file in mad4b-secondary-listing-lazy.php mad4b-render-query-governor.php;do
 a=$(sha256sum "$SRC/wp-content/mu-plugins/$file"|cut -d' ' -f1)
 b=$(sha256sum "$LIVE/wp-content/mu-plugins/$file"|cut -d' ' -f1)
 test "$a" = "$b" || { echo "HOLD source/live mismatch $file";exit 8; }
done
for name in prepare-cohort-probe.py browser-prove-new-cohort.cjs issue-browser-certified-cohort.php run-cohort-qa.sh discover-public-listings.php css-epoch-quiescence.py;do
 test -r "$APP/$name"
done
# A new CSS generation must remain quiet after first-party edits before
# the browser can meaningfully certify any listing's responsive geometry.
if ! docker exec "$PHP" wp --allow-root eval '
 $last=0;
 foreach((array)get_option("mad4b_adaptive_asset_job_queue_v1",[]) as $job){
  if(!empty($job["home_css_dependency"]))
    $last=max($last,(int)($job["updated"]??0));
 }
 echo wp_json_encode([
  "contract"=>"mad4b.native-lazy.css-source-observation.v1",
  "epoch"=>(int)get_option("mad4b_css_ai_epoch_v1",0),
  "last_home_impact_at"=>$last
 ],JSON_UNESCAPED_SLASHES),"\n";
' 2>"$OUTPUT/epoch-wp-cli-stderr.log" > "$OUTPUT/current-css-epoch.json";then
 echo 'HOLD: first-party CSS observation unavailable';exit 0
fi
if ! python3 "$APP/css-epoch-quiescence.py"    "$OUTPUT/current-css-epoch.json" "$OUTPUT/epoch-state.json" 600;then
 echo 'SKIP: CSS source still changing; retry on next scheduled run';exit 0
fi
test ! -e "$LIVE/wp-content/mu-plugins/998-mad4b-auto-coverage-qa.php" ||
 { echo 'HOLD: another temporary QA probe exists';exit 9; }
stamp=$(date -u +%Y%m%dT%H%M%SZ)
dest="$OUTPUT/$stamp"
mkdir -m 700 "$dest"
read -r latest < "$REPORT/latest"
if [[ ! "$latest" =~ ^20[0-9]{6}T[0-9]{6}Z$ ]];then echo INVALID_REPORT_REF;exit 10;fi
data="$REPORT/$latest"
test -r "$data/discovery.json" && test -r "$data/ssr.jsonl"
# A page/source inventory older than 24 hours must be rediscovered, not
# used as a pretext to certify a new market or document.
age=$(( $(date +%s) - $(stat -c %Y "$data/discovery.json") ))
if (( age<0 || age>86400 ));then echo 'HOLD: stale source inventory';exit 11;fi
python3 "$APP/classify-coverage.py" "$data/discovery.json" "$data/ssr.jsonl" "$dest/coverage.json" > "$dest/classification.log"
max_batch=3
python3 - "$dest/coverage.json" "$dest" "$max_batch" <<'PY'
import json,sys
from pathlib import Path
coverage=json.load(open(sys.argv[1]))
assert coverage['contract']=='mad4b.native-lazy.auto-coverage-queue.v1'
assert coverage['site_host']=='tours.dev.mad4b.com'
assert coverage['has_written_certificates'] is False
folder=Path(sys.argv[2])
for index,cohort in enumerate(coverage['queue'][:int(sys.argv[3])]):
 assert cohort['reason'] in ('requires_native_signed_ajax_evidence',
   'recertify_changed_query_source','recertify_previous_certificate')
 assert cohort['market']==('de-usd' if coverage['observed_market']=={'country':'DE','currency':'USD'}
   else cohort['market'])
 (folder/('candidate-%02d.json'%index)).write_text(json.dumps(cohort))
print('CANDIDATES',len(coverage['queue']),'THIS_RUN',min(int(sys.argv[3]),len(coverage['queue'])))
PY
docker inspect "$PHP" "$BROWSER" >/dev/null
docker cp "$APP/discover-public-listings.php" "$PHP:/tmp/mad4b-native-listing-discovery.php"
docker cp "$APP/issue-browser-certified-cohort.php" "$PHP:/tmp/mad4b-auto-coverage-issuer.php"
docker exec -u0 "$PHP" chmod 644 /tmp/mad4b-native-listing-discovery.php /tmp/mad4b-auto-coverage-issuer.php
docker exec "$PHP" php -l /tmp/mad4b-auto-coverage-issuer.php | grep -q 'No syntax errors'
succeeded=0
rejected=0
for file in "$dest"/candidate-*.json;do
 [[ -f "$file" ]] || continue
 label=$(basename "$file" .json)
 work="$dest/$label"
 mkdir -m 700 "$work"
 read -r locale widget url page_id < <(python3 - "$file" <<'PY'
import json,sys
c=json.load(open(sys.argv[1]))
print(c['locale'],c['widget_id'],c['url'],int(c['page_id']))
PY
)
 echo "CHECK $label locale=$locale page=$page_id widget=$widget"
 # Re-discover this exact public page immediately before the seven-width
 # browser proof. Old daily inventories select work, never authorize
 # certificates. This prevents an hourly source-generation change from
 # invalidating the whole batch before the first test.
 cursor=$((page_id>0?page_id-1:0))
 if ! timeout 55 docker exec -e MAD4B_COVERAGE_CURSOR="$cursor" "$PHP" wp --allow-root eval-file      /tmp/mad4b-native-listing-discovery.php > "$work/fresh-discovery.json" 2>"$work/discovery-error.log";then
    echo "HOLD $label source discovery unavailable";rejected=$((rejected+1));continue
 fi
 if ! python3 - "$work/fresh-discovery.json" "$file" "$work/current-source.json" <<'FRESH_SELECT'
import json,sys
from pathlib import Path
data=json.load(open(sys.argv[1]))
prior=json.load(open(sys.argv[2]))
assert data['contract']=='mad4b.native-lazy.auto-discovery.v1'
assert data['site_host']=='tours.dev.mad4b.com'
assert data['site_environment']=='staging'
keys=['locale','url','page_id','document_id','widget_id','query_id','listing_id','market']
matches=[r for r in data['candidates']
         if all(r.get(k)==prior.get(k) for k in keys)]
assert len(matches)==1,(len(matches),'source relation changed')
data['candidates']=matches
data['discovered_count']=1
Path(sys.argv[3]).write_text(json.dumps(data))
print('CURRENT_QUERY_SOURCE',matches[0]['query_generation'])
FRESH_SELECT
 then
    echo "HOLD $label source relation changed";rejected=$((rejected+1));continue
 fi
 # Revalidate today's queue against the native gate before paying for
 # more browser audits. A prior daily report can list an already admitted
 # case, but that is no reason to re-run it.
 if python3 - "$work/current-source.json" <<'NATIVE_PASS'
import json,sys
x=json.load(open(sys.argv[1]))
sys.exit(0 if x['candidates'][0].get('runtime_admitted') is True else 1)
NATIVE_PASS
 then
  echo "PASS $label current native certificate still valid"
  succeeded=$((succeeded+1));continue
 fi
 docker cp "$work/current-source.json" "$BROWSER:/tmp/mad4b-auto-coverage-inventory.json"
 docker exec -u0 "$BROWSER" chmod 644 /tmp/mad4b-auto-coverage-inventory.json
 if ! timeout 110 bash -c 'cat "$1" | docker exec -i "$2" nice -n 15 node - /tmp/mad4b-auto-coverage-inventory.json 390,1920 all > "$3" 2>&1' _    "$APP/browser-ssr-inventory.cjs" "$BROWSER" "$work/fresh-ssr.jsonl";then
    echo "HOLD $label current SSR source/footprint failed";rejected=$((rejected+1));continue
 fi
 # These two just-observed SSR controls supply the probe footprint;
 # the seven signed browser pairs remain the authority for admission.
 if ! python3 "$APP/prepare-cohort-probe.py" "$work/current-source.json" \
      "$work/fresh-ssr.jsonl" "$locale" "$widget" "$url" "$work" > "$work/prepare.log" 2>&1;then
   echo "HOLD $label missing stable SSR baseline"; rejected=$((rejected+1));continue
 fi
 # Capture the exact scoped source before the original browser QA.
 # This is evidence collection only, not a qualification gate.
 docker cp "$work/qa-config.json" "$PHP:/tmp/mad4b-family-capture-config.json"
 docker exec -u0 "$PHP" chmod 644 /tmp/mad4b-family-capture-config.json
 if timeout 30 docker exec "$PHP" wp --allow-root eval '
   $c=json_decode((string)file_get_contents("/tmp/mad4b-family-capture-config.json"),true);
   if(is_array($c)&&class_exists("MAD4B_Native_Layout_Epoch_Continuity_V1")&&method_exists("MAD4B_Native_Layout_Epoch_Continuity_V1","family_source")&&preg_match("/^[a-z]{2}(?:-[a-z]{2})?$/D",(string)($c["locale"]??""))){
     $original=(string)apply_filters("wpml_current_language","");
     try{
       do_action("wpml_switch_language",$c["locale"]);
       if((string)apply_filters("wpml_current_language","")===$c["locale"])
         echo wp_json_encode(MAD4B_Native_Layout_Epoch_Continuity_V1::family_source($c));
     }finally{if($original!=="")do_action("wpml_switch_language",$original);}
   }
 ' > "$work/family-source-before.json" 2>"$work/family-source-before.stderr"; then
   python3 - "$work/qa-config.json" "$work/family-source-before.json" <<'FAMILY_SOURCE'
import json,sys
from pathlib import Path
c=Path(sys.argv[1]); raw=Path(sys.argv[2])
try:
 d=json.loads(raw.read_text()); cfg=json.loads(c.read_text())
 if isinstance(d,dict) and d.get('site_host')==cfg.get('host'):
  cfg['family_source_before']=d
  c.write_text(json.dumps(cfg,sort_keys=True))
except (ValueError,OSError):
 pass
FAMILY_SOURCE
 fi
 if ! timeout 390 "$APP/run-cohort-qa.sh" "$work" "$work/browser.jsonl" > "$work/qa.log" 2>&1;then
   echo "HOLD $label signed native seven-width QA failed"; rejected=$((rejected+1));continue
 fi
 if [[ -f "$LIVE/wp-content/mu-plugins/998-mad4b-auto-coverage-qa.php" ]];then
   echo "HOLD $label temporary QA not cleared"; rejected=$((rejected+1));continue
 fi
 docker cp "$work/qa-config.json" "$PHP:/tmp/mad4b-native-coverage-current.json"
 docker cp "$work/browser.jsonl" "$PHP:/tmp/mad4b-native-coverage-report.jsonl"
 docker exec -u0 "$PHP" chmod 644 /tmp/mad4b-native-coverage-current.json /tmp/mad4b-native-coverage-report.jsonl
 # Force the matching public page into the read-only paginated discovery
 # window, without rewriting visitor context or inventing market cookies.
 cursor=$((page_id>0?page_id-1:0))
 # Retry only the original issuer on its explicit busy result, using the
 # same complete report. Never retry a rejected semantic/market proof.
 issue_ok=0
 for attempt in 1 2 3;do
   if docker exec -e MAD4B_COVERAGE_CURSOR="$cursor" "$PHP" wp --allow-root eval-file \
       /tmp/mad4b-auto-coverage-issuer.php > "$work/issuer-attempt-$attempt.log" 2>&1;then
     cp "$work/issuer-attempt-$attempt.log" "$work/issuer.log"
     issue_ok=1;break
   fi
   cp "$work/issuer-attempt-$attempt.log" "$work/issuer.log"
   if ! grep -q '^STAGING_WPCLI_BUSY:' "$work/issuer.log" || [[ "$attempt" == 3 ]];then break;fi
   if (( $(date +%s)-$(stat -c %Y "$work/browser.jsonl") >= 660 ));then break;fi
   echo "RETRY $label original issuer busy, preserving fresh browser evidence"
   sleep 15
 done
 if [[ "$issue_ok" == 1 ]];then
   if grep -q '^PASS newly enrolled ' "$work/issuer.log";then
     echo "PASS $label source-bound native Lazy certificate admitted"
     succeeded=$((succeeded+1))
   elif grep -q 'SKIP: healthy current certificate' "$work/issuer.log";then
     echo "PASS $label prior certificate remains valid"
     succeeded=$((succeeded+1))
   else
     echo "HOLD $label issuer did not confirm admission"
     rejected=$((rejected+1))
   fi
 else
   echo "HOLD $label source/market/QA issuer rejected"
   tail -n 2 "$work/issuer.log" |cut -c1-180
   rejected=$((rejected+1))
 fi
done
echo "RESULT success=$succeeded hold=$rejected"
echo "FINISH $(date -u +%FT%TZ)"
# A failed candidate does not break the daily scan, and never removes SSR.

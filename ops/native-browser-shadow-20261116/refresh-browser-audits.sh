#!/usr/bin/env bash
# MAD4B presentation browser evidence refresh, read-only site navigation.
# One source: Chromium on the existing Playwright service. Native WP-CLI import
# writes verified reports; the original Content Readiness scheduler owns alerts.
set -Eeuo pipefail
R=/opt/mad4b-company-provisioning/reports/presentation-integrity-20261108
C=playwright-service-8ckuu8cyb4axyzkl3zqcn1z9
exec 9>"$R/.presentation-browser.lock"
if ! flock -n 9; then echo "existing_presentation_browser_job_running"; exit 0; fi
mkdir -p "$R/browser-runs"
WORK=$(mktemp -d "$R/browser-runs/job-$(date -u +%Y%m%dT%H%M%SZ)-XXXX")
cp "$R/browser-card-link-audit-v1.js" "$R/browser-card-contrast-v1.js" "$R/aggregate-browser-evidence.py" "$WORK/"
cd "$WORK"
run_browser() {
  local kind="$1" url="$2" locale="$3" out="$4" script
  if [[ "$kind" == link ]]; then script=browser-card-link-audit-v1.js; else script=browser-card-contrast-v1.js; fi
  echo "browser_audit_start kind=$kind host=$url locales=$locale out=$out"
  timeout 200s bash -c 'cat "$1" | docker exec -i "$2" nice -n 10 node - "$3" ${4:+"$4"}' _ "$script" "$C" "$url" "$locale" > "$out"
  python3 - "$out" <<'PY'
import sys,json
p=sys.argv[1];raw=open(p).read().splitlines()
if not raw:raise SystemExit('no_browser_result:'+p)
d=json.loads(raw[0])
if not d.get('complete') or d.get('errors'):raise SystemExit('browser_report_incomplete:'+p+':'+str(d.get('errors',[])[:2]))
# Remove possible trailing runner noise; keep a canonical JSON record.
open(p,'w').write(json.dumps(d,ensure_ascii=False)+'\n')
print('browser_audit_pass',p,'pages',len(d.get('pages',d.get('views',[]))),'links',d.get('summary',{}).get('links_examined',0),'contrast_samples',d.get('sampled',0))
PY
}
run_browser link https://tours.dev.mad4b.com/ en,fr tours-dev-link-en-fr.json
run_browser link https://tours.dev.mad4b.com/ de,es tours-dev-link-de-es.json
run_browser link https://tours.dev.mad4b.com/ it,ar tours-dev-link-it-ar.json
run_browser link https://wayfatours.com/ en,es wayfa-link-browser.json
run_browser contrast https://tours.dev.mad4b.com/ '' tours-dev-contrast-browser.json
run_browser contrast https://wayfatours.com/ '' wayfa-contrast-browser.json
python3 aggregate-browser-evidence.py
for pair in "tours-dev:php-wkgtkzta35wkkgouwt6ruljc:/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy" "wayfa:php-guxhp9y6vgh7oouuw8plxrlr:/opt/wayfa-rebuild-20261102/site-copy"; do
  IFS=: read -r site container root <<< "$pair"
  dir="$root/wp-content/mad4b-private/presentation-audits"
  # Only presentation audit files are readable to the canonical WordPress
  # owner (uid 33). The parent stays non-enumerable to that group; unrelated
  # Hostinger secrets and deployment bindings are never touched.
  install -d -o root -g 33 -m 0710 "$root/wp-content/mad4b-private"
  install -d -o 33 -g 33 -m 0700 "$dir"
  install -o 33 -g 33 -m 0600 "$site-browser-evidence-v1.json" "$dir/latest.json"
  docker exec -u www-data "$container" wp mad4b presentation-browser-import /var/www/html/wp-content/mad4b-private/presentation-audits/latest.json
  docker exec -u www-data "$container" wp mad4b presentation-browser-status
  echo "verified_import=$site"
done
cp -f tours-dev-link-*.json wayfa-link-browser.json tours-dev-contrast-browser.json wayfa-contrast-browser.json "$R/"
cp -f tours-dev-browser-evidence-v1.json wayfa-browser-evidence-v1.json "$R/"
printf '{"status":"PASS","contract":"mad4b.presentation-browser-refresh.v1","utc":"%s","job_dir":"%s","sites":["tours-dev","wayfa"],"native_alert_execution":"existing_content_readiness_only"}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$WORK" > "$R/browser-refresh-last-success.json"
# Same scheduled browser-audit worker, no new daemon or WordPress writes.
# This bounded shadow probe discovers published WPML locales/JetEngine grids
# at runtime, rotates language cohorts, stores only redacted source digests,
# and NEVER issues SSR/AJAX certification or changes public render modes.
if [[ -r "$R/browser-native-listing-shadow-v1.js" ]]; then
  SHADOW_OUT="$WORK/tours-dev-native-listing-shadow.json"
  if timeout 190s bash -c 'cat "$1" | docker exec -i "$2" nice -n 10 node - "$3" > "$4"' _ \
    "$R/browser-native-listing-shadow-v1.js" "$C" \
    https://tours.dev.mad4b.com/ "$SHADOW_OUT"; then
    if python3 - "$SHADOW_OUT" "$R/native-listing-shadow-latest.json" <<'PYA'
import json,os,sys,tempfile
src,out=sys.argv[1:]
if os.path.getsize(src)>262144:raise SystemExit('SHADOW_EVIDENCE_TOO_LARGE')
data=json.load(open(src))
if not (isinstance(data,dict)
        and data.get('contract')=='mad4b.native-listing.shadow-browser-evidence.v1'
        and data.get('site_host')=='tours.dev.mad4b.com'
        and data.get('complete') is True
        and data.get('authorized_lazy_promotion') is False
        and data.get('high_trust_certificate_created') is False
        and data.get('signed_ajax_parity_certified') is False
        and data.get('decision')=='HOLD_AJAX_PARITY_NOT_MEASURED'
        and len(data.get('pages',[]))<=8
        and all(isinstance(x,dict) and x.get('status')==200
                for x in data.get('pages',[]))):
    raise SystemExit('SHADOW_EVIDENCE_CONTRACT_INVALID')
parent=os.path.dirname(out)
fd,temp=tempfile.mkstemp(prefix='.native-listing-shadow-',dir=parent)
try:
    with os.fdopen(fd,'w') as f:
        os.fchmod(f.fileno(),0o600)
        json.dump(data,f,separators=(',',':'),sort_keys=True)
        f.write('\n');f.flush();os.fsync(f.fileno())
    os.replace(temp,out)
finally:
    if os.path.exists(temp):os.unlink(temp)
print('native_listings_shadow_observed_only',len(data['pages']),
      'locales',','.join(data.get('sampled_locales',[])))
PYA
    then
      echo native_listing_shadow_report_valid
    else
      echo native_listing_shadow_contract_hold >&2
    fi
  else
    echo native_listing_shadow_browser_hold >&2
  fi
fi
# Existing browser audit also processes a tiny, due-only set of the original
# Adaptive Asset Intelligence jobs. No new timer/worker, no certification.
# Each receipt is tied to the source_token returned from current WordPress.
# A concurrent content edit invalidates the token and causes a safe rejection.
if [[ -r "$R/browser-native-route-recheck-v1.js" ]]; then
  DUE_JSON="$WORK/adaptive-due.json"
  DUE_TSV="$WORK/adaptive-due.tsv"
  if timeout 30 flock -w 10 /run/lock/mad4b-central-wpcli-site.lock \
      docker exec php-wkgtkzta35wkkgouwt6ruljc wp --allow-root eval \
        'echo wp_json_encode(MAD4B_SCP_Adaptive_Asset_Intelligence::due_worker_jobs(25));' \
      > "$DUE_JSON" 2>"$WORK/adaptive-due-wp-stderr.log"; then
    if python3 - "$DUE_JSON" "$DUE_TSV" <<'PYA'
import json,re,sys
raw=open(sys.argv[1]).read().strip().splitlines()
jobs=json.loads(raw[-1]) if raw else []
if not isinstance(jobs,list) or len(jobs)>25:raise SystemExit('DUE_JOBS_NOT_BOUNDED')
safe=[]
for x in jobs:
    if not isinstance(x,dict):continue
    path=x.get('path');token=x.get('source_token')
    if not (isinstance(path,str) and len(path)<=240
        and re.fullmatch(r'/[A-Za-z0-9/_~.-]*',path)
        and '..' not in path and '//' not in path
        and not re.search(r'/(?:cart|checkout|payment|booking|account|wp-admin|wp-json)(?:/|$)',path,re.I)
        and isinstance(token,str) and re.fullmatch(r'[a-f0-9]{32}',token)):
        continue
    safe.append((int(x.get('attempts',0)),path,token))
safe.sort(key=lambda x:(x[0],x[1]))
with open(sys.argv[2],'w') as out:
    for _,path,token in safe[:2]:out.write(path+'\t'+token+'\n')
print('bounded_due_route_rechecks',min(2,len(safe)))
PYA
    then
      index=0
      while IFS=$'\t' read -r route source_token; do
        [[ -n "$route" && -n "$source_token" ]] || continue
        index=$((index+1))
        case "$index" in 1|2) ;; *) break ;; esac
        ROUTE_RESULT="$WORK/adaptive-native-route-$index.json"
        # Chromium uses fixed first-party origin + path, not user URL or headers.
        timeout 115 bash -c 'cat "$1" | docker exec -i "$2" nice -n 10 node - "$3" "$4" > "$5"' _ \
          "$R/browser-native-route-recheck-v1.js" "$C" \
          https://tours.dev.mad4b.com/ "$route" "$ROUTE_RESULT" || true
        if ! python3 - "$ROUTE_RESULT" "$route" "$WORK/adaptive-route-verdict-$index.txt" <<'PYA'
import json,os,re,sys
source,expected,out=sys.argv[1:]
if not os.path.isfile(source) or os.path.getsize(source)>32000:
    raise SystemExit('ROUTE_BROWSER_EVIDENCE_MISSING')
x=json.load(open(source))
if not (x.get('contract')=='mad4b.adaptive-asset.native-route-recheck.v1'
        and x.get('site_host')=='tours.dev.mad4b.com'
        and x.get('route')==expected
        and x.get('authorization_created') is False
        and x.get('certification_issued') is False
        and x.get('automatic_lazy_promotion') is False
        and x.get('state')=='HOLD'):
    raise SystemExit('ROUTE_BROWSER_EVIDENCE_INVALID')
observations=x.get('observations',[])
if not isinstance(observations,list) or len(observations)>2:
    raise SystemExit('ROUTE_BROWSER_EVIDENCE_UNBOUNDED')
status='hold' if x.get('complete') is True and len(observations)==2 \
    and all(i.get('status')==200 and i.get('javascript_error_count')==0
            for i in observations) else 'failed'
with open(out,'w') as f:f.write(status+'\n')
print('route_browser_state',status,'path_sha',__import__('hashlib').sha256(expected.encode()).hexdigest()[:12])
PYA
        then
          echo "adaptive_route_browser_contract_hold" >&2
          continue
        fi
        state="$(cat "$WORK/adaptive-route-verdict-$index.txt")"
        if [[ "$state" == hold || "$state" == failed ]]; then
          # Calls the existing first-party WP-CLI-only acknowledgement,
          # with an immutable source token. Never requests certified/native.
          timeout 30 flock -w 10 /run/lock/mad4b-central-wpcli-site.lock \
            docker exec -e "MAD4B_ADAPTIVE_QA_PATH=$route" \
              -e "MAD4B_ADAPTIVE_SOURCE_TOKEN=$source_token" \
              -e "MAD4B_ADAPTIVE_RECHECK_STATE=$state" \
              php-wkgtkzta35wkkgouwt6ruljc wp --allow-root eval \
              '$path=getenv("MAD4B_ADAPTIVE_QA_PATH");$token=getenv("MAD4B_ADAPTIVE_SOURCE_TOKEN");$state=getenv("MAD4B_ADAPTIVE_RECHECK_STATE");if(!in_array($state,["hold","failed"],true)||!preg_match("/^[a-f0-9]{32}$/D",(string)$token)){exit(3);} $ack=MAD4B_SCP_Adaptive_Asset_Intelligence::acknowledge_worker($path,$state,"read_only_browser_recheck_no_parity_certificate",$token);echo wp_json_encode(["ok"=>$ack["ok"]??false,"state"=>$state,"error"=>$ack["error"]??null,"attempts"=>$ack["attempts"]??null]),"\n";' \
            > "$WORK/adaptive-ack-$index.json" 2>"$WORK/adaptive-ack-$index.stderr" || true
          echo "adaptive_native_route_recheck_processed_index=$index result=$state"
        fi
      done < "$DUE_TSV"
    else
      echo "adaptive_due_route_contract_hold" >&2
    fi
  else
    echo "adaptive_due_source_read_unavailable" >&2
  fi
fi
echo browser_audit_all_sites_pass

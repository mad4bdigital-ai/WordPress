#!/usr/bin/env bash
# Company-scoped autonomous WPML/WCML + Elementor/JetEngine discovery.
# This stage does NOT issue or falsify browser certificates; SSR remains the
# default for uncovered/too-small/conditional listings.
set -euo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
SRC=/opt/mad4b-company-provisioning/worktrees/pr814-converged-local-20261115
LIVE=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy
SCRIPT="$SRC/ops/native-lazy-auto-coverage-20261116"
OUT=/opt/mad4b-company-provisioning/reports/native-lazy-coverage-discovery-20261116
P=php-wkgtkzta35wkkgouwt6ruljc
B=playwright-service-8ckuu8cyb4axyzkl3zqcn1z9
mkdir -p "$OUT"
exec 9>/run/lock/mad4b-native-lazy-coverage-discovery.lock
if ! flock -n 9;then echo 'SKIP: discovery already active';exit 0;fi
exec 8>/run/lock/mad4b-native-lazy-browser-global.lock
if ! flock -n 8;then echo 'SKIP: another governed browser audit owns the shared lock';exit 0;fi
test -r "$SCRIPT/discover-public-listings.php"
test -r "$SCRIPT/browser-ssr-inventory.cjs"
test -r "$SCRIPT/classify-coverage.py"
for name in mad4b-secondary-listing-lazy.php mad4b-render-query-governor.php;do
 a=$(sha256sum "$SRC/wp-content/mu-plugins/$name"|cut -d' ' -f1)
 b=$(sha256sum "$LIVE/wp-content/mu-plugins/$name"|cut -d' ' -f1)
 test "$a" = "$b" || { echo "HOLD: source/live code divergence in $name";exit 8; }
done
docker inspect "$P" "$B" >/dev/null
stamp=$(date -u +%Y%m%dT%H%M%SZ)
dest="$OUT/$stamp"
mkdir -m 700 "$dest"
cursor=0
if [[ -f "$OUT/cursor" ]];then
 read -r candidate < "$OUT/cursor"
 if [[ "$candidate" =~ ^[0-9]{1,10}$ ]];then cursor="$candidate";fi
fi
echo "DISCOVERY_START $stamp cursor=$cursor"
docker cp "$SCRIPT/discover-public-listings.php" "$P:/tmp/mad4b-native-listing-discovery.php"
docker exec -u0 "$P" chmod 644 /tmp/mad4b-native-listing-discovery.php
wp_eval() {
 local file="$1"
 local i
 for i in 1 2 3;do
  if docker exec -e "MAD4B_COVERAGE_CURSOR=$cursor" -e MAD4B_COVERAGE_PAGE_BATCH=60 "$P" wp --allow-root eval-file /tmp/mad4b-native-listing-discovery.php > "$file" 2>"$dest/wp-cli-stderr.log";then return 0;fi
  if ! grep -q STAGING_WPCLI_BUSY "$dest/wp-cli-stderr.log";then
   echo 'ERROR: WPCLI discovery failure';tail -n 6 "$dest/wp-cli-stderr.log";return 1
  fi
  sleep $((i*8))
 done
 echo 'HOLD: concurrency busy, retry on next scheduled run'
 return 2
}
wp_eval "$dest/discovery.json"
python3 - "$dest/discovery.json" <<'PY'
import json,sys
m=json.load(open(sys.argv[1]))
assert m['contract']=='mad4b.native-lazy.auto-discovery.v1'
assert m['site_environment']=='staging'
assert m['site_host']=='tours.dev.mad4b.com'
assert len(m['candidates'])<=256
assert m['page_scan']['cursor']>=0 and 0<=m['page_scan']['next_cursor']<=9999999999
print('DISCOVERED',m['discovered_count'],
  'LANGUAGES',len(m['wpml_languages']),
  'WCML_CURRENCIES',len(m['wcml_configured_currencies']))
PY
if [[ "$(printenv MAD4B_COVERAGE_MODE || echo full)" == inventory ]];then
 echo 'INVENTORY_VALIDATED_NO_CERTIFICATES_CHANGED';exit 0
fi
docker cp "$dest/discovery.json" "$B:/tmp/mad4b-auto-coverage-inventory.json"
docker exec -u0 "$B" chmod 644 /tmp/mad4b-auto-coverage-inventory.json
echo 'SSR_BASELINE: public locale routes, two independent device footprints'
set +e
timeout 410 bash -c 'cat "$1" | docker exec -i "$2" nice -n 15 node - /tmp/mad4b-auto-coverage-inventory.json 390,1920 all > "$3" 2>&1' _ \
 "$SCRIPT/browser-ssr-inventory.cjs" "$B" "$dest/ssr.jsonl"
browser_rc=$?
set -e
# Missing conditional widgets are classified as conditional_not_rendered;
# they do not block discovered/public lists that passed their own SSR audit.
if [[ "$browser_rc" != 0 && "$browser_rc" != 2 ]];then
 echo "ERROR: baseline browser failed rc=$browser_rc";tail -n 4 "$dest/ssr.jsonl";exit 9
fi
python3 "$SCRIPT/classify-coverage.py" \
 "$dest/discovery.json" "$dest/ssr.jsonl" "$dest/coverage.json" > "$dest/summary.txt"
tail -n 14 "$dest/summary.txt"
python3 - "$dest/coverage.json" <<'PY'
import json,sys
x=json.load(open(sys.argv[1]))
assert x['contract']=='mad4b.native-lazy.auto-coverage-queue.v1'
assert x['has_written_certificates'] is False
assert len(x['matrix'])==x['discovered_count']
print('QUEUE',len(x['queue']),
 'UNOBSERVED_CURRENCIES',','.join(x['configured_but_unobserved_currencies']))
PY
# Latest result pointer is safe: write only under the managed report directory.
python3 - "$dest/discovery.json" "$OUT/cursor.tmp" <<'CURSOR_META'
import json,sys
m=json.load(open(sys.argv[1]))
open(sys.argv[2],'w').write(str(int(m['page_scan']['next_cursor']))+'\n')
CURSOR_META
mv "$OUT/cursor.tmp" "$OUT/cursor"
printf '%s\n' "$stamp" > "$OUT/latest.tmp"
mv "$OUT/latest.tmp" "$OUT/latest"
echo "DISCOVERY_COMPLETE $(date -u +%FT%TZ) output=$dest"

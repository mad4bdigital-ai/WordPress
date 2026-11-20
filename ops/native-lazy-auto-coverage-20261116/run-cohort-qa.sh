#!/usr/bin/env bash
set -euo pipefail
umask 077
if [ "$#" -ne 2 ];then echo 'Usage: run-cohort-qa.sh <private probe dir> <result>' >&2;exit 2;fi
PROBE="$1"; RESULT="$2"
SCRIPT=/opt/mad4b-company-provisioning/worktrees/pr814-converged-local-20261115/ops/native-lazy-auto-coverage-20261116/browser-prove-new-cohort.cjs
S=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy
F="$S/wp-content/mu-plugins/998-mad4b-auto-coverage-qa.php"
C=php-wkgtkzta35wkkgouwt6ruljc
B=playwright-service-8ckuu8cyb4axyzkl3zqcn1z9
exec 9>/run/lock/mad4b-native-auto-coverage-qa.lock
flock -n 9
test ! -e "$F"
test -f "$PROBE/qa-plugin.php"
test -f "$PROBE/qa-secret.json"
test -f "$PROBE/qa-config.json"
test -f "$SCRIPT"
if test -f "$RESULT";then echo 'No overwrite of old QA run' >&2;exit 3;fi
cp "$PROBE/qa-plugin.php" "$F"
chmod 644 "$F"
trap 'rm -f "$F"; echo QA_PROBE_REMOVED' EXIT
docker exec "$C" php -l /var/www/html/wp-content/mu-plugins/998-mad4b-auto-coverage-qa.php
docker cp "$PROBE/qa-config.json" "$B:/tmp/mad4b-auto-coverage-selected.json"
docker exec -u0 "$B" chmod 644 /tmp/mad4b-auto-coverage-selected.json
token=$(python3 - "$PROBE/qa-secret.json" <<'PY'
import json,sys
x=json.load(open(sys.argv[1]))
print(x['token'])
PY
)
if ! [[ "$token" =~ ^[a-f0-9]{64}$ ]];then echo BAD_TOKEN >&2;exit 4;fi
echo "BROWSER_START $(date -u +%FT%TZ)"
set +e
timeout 315 bash -c 'cat "$1" | docker exec -i -e "MAD4B_COVERAGE_QA_TOKEN=$2" "$3" nice -n 13 node - /tmp/mad4b-auto-coverage-selected.json 320,390,767,768,1024,1440,1920 > "$4" 2>&1' _ "$SCRIPT" "$token" "$B" "$RESULT"
rc=$?
set -e
echo "BROWSER_EXIT=$rc"
python3 - "$RESULT" <<'PY'
import json,sys
rows=[json.loads(s[10:]) for s in open(sys.argv[1]) if s.startswith('QA_RESULT ')]
for r in rows:print('WIDTH',r['width'],'pass',r['pass'],'items',r.get('baseline_count'),r.get('ajax_count'),'cls',r.get('cls_canary'),'error',r.get('error'))
assert len(rows)==7,(len(rows),'not 7')
assert {r['width'] for r in rows}=={320,390,767,768,1024,1440,1920}
assert all(r.get('pass') for r in rows),[(r['width'],r.get('error')) for r in rows]
print('PASS 7/7 complete signed native browser QA')
PY
test "$rc" -eq 0

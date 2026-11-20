#!/usr/bin/env bash
# Company-scoped browser re-attestation. Never certifies unproven content.
set -euo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
BASE=/opt/mad4b-company-provisioning
LIVE=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy
SOURCE="$BASE/worktrees/pr814-converged-local-20261115"
ACTIVE="$BASE/ops/native-lazy-auto-renew-20261116"
OUT="$BASE/reports/native-lazy-auto-renew-20261116"
PHP_CONTAINER=php-wkgtkzta35wkkgouwt6ruljc
BROWSER_CONTAINER=playwright-service-8ckuu8cyb4axyzkl3zqcn1z9
mkdir -p "$OUT"
exec 9>/run/lock/mad4b-native-lazy-auto-renew.lock
if ! flock -n 9; then echo 'SKIP: another lazy browser audit active'; exit 0; fi
echo "BEGIN $(date -u +%FT%TZ)"
test -f "$ACTIVE/renew-verified-native-lazy-browser.cjs"
test -f "$ACTIVE/renew-verified-native-lazy-admission.php"
test -f "$SOURCE/wp-content/mu-plugins/mad4b-secondary-listing-lazy.php"
sha_src=$(sha256sum "$SOURCE/wp-content/mu-plugins/mad4b-secondary-listing-lazy.php"|cut -d' ' -f1)
sha_live=$(sha256sum "$LIVE/wp-content/mu-plugins/mad4b-secondary-listing-lazy.php"|cut -d' ' -f1)
test "$sha_src" = "$sha_live" || { echo 'HOLD: source/live fingerprint diverged'; exit 9; }
test "$sha_live" = a14c09227888d9854593e16841e358ad9969a121d214a7d95e9793f01e49a547 ||
 { echo 'HOLD: admission engine not yet independently reviewed'; exit 10; }
# The query-generation authority is part of the proof trust chain.
q_src=$(sha256sum "$SOURCE/wp-content/mu-plugins/mad4b-render-query-governor.php"|cut -d' ' -f1)
q_live=$(sha256sum "$LIVE/wp-content/mu-plugins/mad4b-render-query-governor.php"|cut -d' ' -f1)
test "$q_src" = "$q_live" || { echo 'HOLD: query source/live SHA changed'; exit 16; }
test "$q_live" = 17b6edcacd104c38b358d5357379592b3e04b20c3e9d1a37ed16e150aeae51bd ||
 { echo 'HOLD: query membership source not verified'; exit 17; }
docker inspect "$PHP_CONTAINER" "$BROWSER_CONTAINER" >/dev/null
wpcli() {
 local out="$1"; local mode="$2"; shift 2
 local i status
 for i in 1 2 3; do
  if docker exec -e MAD4B_LAZY_RENEW_MODE="$mode" \
   -e MAD4B_LAZY_RENEW_EVIDENCE=/tmp/mad4b-lazy-renew-audit.jsonl \
   "$PHP_CONTAINER" wp --allow-root "$@" > "$out" 2>&1; then return 0
  else status=$?; fi
  if ! grep -q 'STAGING_WPCLI_BUSY' "$out"; then
    echo "ERROR: WordPress CLI failed exit=$status"
    tail -n 12 "$out"
    return 1
  fi
  echo "WPCLI_BUSY retry=$i"
  sleep $((i*8))
 done
 echo 'ERROR: WordPress CLI busy; retry automatically on next timer cycle'
 return 2
}
docker cp "$ACTIVE/renew-verified-native-lazy-admission.php" "$PHP_CONTAINER":/tmp/mad4b-lazy-renew-admission.php
docker exec -u0 "$PHP_CONTAINER" chmod 644 /tmp/mad4b-lazy-renew-admission.php
docker exec "$PHP_CONTAINER" php -l /tmp/mad4b-lazy-renew-admission.php | grep -q 'No syntax errors'
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
CHECK="$OUT/preflight-$STAMP.log"
wpcli "$CHECK" check eval-file /tmp/mad4b-lazy-renew-admission.php || exit $?
decision=$(python3 - "$CHECK" <<'PY'
import json,sys
try:
 lines=open(sys.argv[1]).read().splitlines()
 rows=[json.loads(x) for x in lines if x.startswith('{') and x.endswith('}')]
 print(rows[-1].get('decision','invalid'))
except Exception:print('invalid')
PY
)
echo "PREFLIGHT=$decision"
force="$(printenv MAD4B_LAZY_RENEW_FORCE_VALIDATE || echo 0)"
if [[ "$decision" != due && "$force" != 1 ]]; then
 echo 'SKIP: not due or unsupported market; native SSR remains safe'
 exit 0
fi
if [[ "$decision" != due && "$decision" != skip ]]; then
 echo 'ERROR: unrecognized preflight decision'
 exit 11
fi
if [[ "$force" == 1 && "$decision" == skip ]] &&
   ! grep -q 'certificate-young' "$CHECK"; then
 echo 'SKIP: forced QA never bypasses stale source or foreign market'
 exit 0
fi
REPORT="$OUT/browser-evidence-$STAMP.jsonl"
echo 'BROWSER: 12 independent SSR and fully signed AJAX comparisons'
set +e
timeout 600 bash -c 'cat "$1" | docker exec -i "$2" nice -n 15 node - > "$3" 2>&1' _ \
 "$ACTIVE/renew-verified-native-lazy-browser.cjs" "$BROWSER_CONTAINER" "$REPORT"
qa_result=$?
set -e
if [[ "$qa_result" != 0 ]]; then
 echo "QA_FAILED rc=$qa_result report=$REPORT"
 tail -n 6 "$REPORT"
 exit 12
fi
grep -qx 'RENEW_PASS 12' "$REPORT" || { echo 'QA_INCOMPLETE'; exit 13; }
docker cp "$REPORT" "$PHP_CONTAINER":/tmp/mad4b-lazy-renew-audit.jsonl
docker exec -u0 "$PHP_CONTAINER" chmod 644 /tmp/mad4b-lazy-renew-audit.jsonl
VALIDATE="$OUT/validate-$STAMP.log"
wpcli "$VALIDATE" validate eval-file /tmp/mad4b-lazy-renew-admission.php || exit $?
if ! grep -q '^PASS 12/12 browser parity;' "$VALIDATE"; then
 echo 'EVIDENCE_REJECTED'; cat "$VALIDATE"; exit 14
fi
echo 'PROOF: independent browser and live source fingerprints validated'
if [[ "$force" == 1 ]];then
 echo 'DRY_RUN_VALIDATION_ONLY: no certificate changed'
 exit 0
fi
RENEW="$OUT/renew-$STAMP.log"
wpcli "$RENEW" renew eval-file /tmp/mad4b-lazy-renew-admission.php || exit $?
cat "$RENEW"
grep -q '^PASS renewed 4/4 active DE-USD certs' "$RENEW" || { echo 'RENEWAL_NOT_APPROVED';exit 15; }
echo "DONE $(date -u +%FT%TZ)"

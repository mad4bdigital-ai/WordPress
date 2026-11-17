from pathlib import Path
import sys
p=Path(sys.argv[1])
worker=(p/'run-auto-coverage-admission.sh').read_text()
probe=(p/'ephemeral-qa-probe-template.php').read_text()
b=(p/'browser-prove-new-cohort.cjs').read_text()
issuer=(p/'issue-browser-certified-cohort.php').read_text()
prepare=(p/'prepare-cohort-probe.py').read_text()
timer=(p/'systemd/mad4b-native-lazy-auto-coverage-admission.timer').read_text()
checks=[
 ('source/runtime SHA guarded', 'sha256sum "$SRC/wp-content/mu-plugins/$file"' in worker and 'sha256sum "$LIVE/wp-content/mu-plugins/$file"' in worker),
 ('new CSS epoch atomically holds every old proof', 'hold_css_epoch_rollover' in issuer and "$registry['css_epoch']=$nextEpoch" in issuer and 'hold_existing_until_per_cohort_signed_qa' in issuer),
 ('concurrent epoch and listing CSS changes fail', 'Last moment query/CSS source changed' in issuer),
 ('bounded three candidates', 'max_batch=3' in worker),
 ('fresh discovery only', 'age>86400' in worker),
 ('per-cohort fresh source and SSR before QA', 'fresh-discovery.json' in worker and 'fresh-ssr.jsonl' in worker and 'prepare-cohort-probe.py' in worker),
 ('source-probed route selector', "x['url']==url" in prepare),
 ('document fingerprint', "'document_sha256'" in prepare),
 ('hashed short-lived proof token', "token=secrets.token_hex(32)" in prepare and 'hash_equals' in probe),
 ('same-origin document token', "request.isNavigationRequest()" in b and 'uri.origin===new URL(url).origin' in b),
 ('QA HTML cache isolated', "'x-mad4b-widget-probe'" in b),
 ('seven responsive sizes', '[320,390,767,768,1024,1440,1920]' in issuer),
 ('staging-only gate', "wp_get_environment_type()!=='staging'" in probe),
 ('temporary QA removed', "trap 'rm -f" in (p/'run-cohort-qa.sh').read_text() and 'Temporary browser probe still active' in issuer),
 ('signed AJAX and full HTML/performance', "native_signed_ajax" in issuer and "fcp_canary" in issuer),
 ('trusted server market context', 'mad4b_render_query_market_identity()' in issuer),
 ('read-only source rediscovery', 'require $scanner' in issuer and 'hash_file' in issuer),
 ('atomic DB update', 'GET_LOCK(' in issuer and 'option_value=%s' in issuer),
 ('runtime gate checked after update', 'mad4b_secondary_grid_lazy_ajax_parity_proven($query,$listing,$doc)' in issuer),
 ('bounded half-hourly retry with jitter', '*:15,45:00 UTC' in timer and 'RandomizedDelaySec=300' in timer),
 ('CSS epoch must remain quiet before expensive QA', 'css-epoch-quiescence.py' in worker and 'current-css-epoch.json' in worker and 'epoch-state.json' in worker),
 ('actual first-party changed-at source, not client headers', 'mad4b_adaptive_asset_job_queue_v1' in worker and 'mad4b_css_ai_epoch_v1' in worker and 'home_css_dependency' in worker),
 ('current valid cohorts skip browser recertification', "get('runtime_admitted') is True" in worker),
 ('browser discovery and admission share nonoverlapping lock',
  'mad4b-native-lazy-browser-global.lock' in worker and
  'mad4b-native-lazy-browser-global.lock' in (p/'run-auto-coverage-discovery.sh').read_text()),
]
for label,ok in checks:
 assert ok,label
 print('PASS',label)
print('PASS %d/%d auto-issuance source contracts'%(len(checks),len(checks)))

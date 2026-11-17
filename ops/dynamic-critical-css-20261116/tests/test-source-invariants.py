from pathlib import Path
import hashlib
import sys
p=Path(sys.argv[1])
run=(p/'run-site.sh').read_text()
pub=(p/'tools/publish.py').read_text()
vfy=(p/'tools/verify.js').read_text()
cap=(p/'tools/capture.js').read_text()
source=(p/'systemd/mad4b-critical-css-source.service').read_text()
wayfa=(p/'systemd/mad4b-critical-css-wayfa.service').read_text()
checks=[
 ('independent company source roots', 'etg-commerce-staging/site-copy' in run and 'wayfa-rebuild-20261102/site-copy' in run),
 ('company-scoped WordPress writes', 'case "$SITE"' in run and 'source)' in run and 'wayfa)' in run),
 ('current WordPress CSS epoch', 'mad4b_css_ai_epoch_v1' in run and 'epoch-gate.py' in run),
 ('strict host and manifest binding', 'MAD4B_TARGET_HOST' in run and "old.get('site',host)==host" in pub),
 ('exact native consumer identity', 'CONSUMER_SHA=' in run and 'SHA(consumer)!=expected' in pub),
 ('native CSS on failed browser gate', "gate.get('decision') != 'passed_evidence'" in pub),
 ('adaptive breakpoint constraint', 'ADAPTIVE_MAX=767' in run and 'adaptive==599' in pub
  and 'native_breakpoint==767' in pub and 'adaptive_width_proven(' in
  (p.parents[1]/'wp-content/mu-plugins/415-mad4b-critical-css-orchestrator.php').read_text()),
 ('mobile/tablet 767/768 both measured', 'QA_WIDTHS=320,390,414,767,768' in run and '320,390,414,767,768' in pub),
 ('narrower 599/600 measured while retaining native 767/768',
  'MAD4B_QA_WIDTHS=320,390,414,599,600,767,768' in run
  and 'required={320,390,414,599,600,767,768}' in pub
  and 'verification-primary-767.json' in run),

 ('Wayfa no-preload strategy preserved', 'CSS_PRIORITY=low;CSS_PRELOAD=0;ADAPTIVE_MAX=0' in run),
 ('CSS byte-level SHA', "SHA(cssfile)!=item['source_sha']" in pub and "SHA(original_css)!=item['source_sha']" in pub),
 ('CAS manifest plus rollback backup', 'CONCURRENT_MANIFEST_CHANGE' in pub and 'manifest-before-promotion.json' in pub),
 ('browser LCP/FCP/CLS/interactive checks', all(x in vfy for x in ('lcpReg','fcpGain','variant.cls','popupOpened','calendarOpened'))),
 ('real public query-free capture', 'CSS_CAPTURE_REQUIRES_PUBLIC_QUERY_FREE_ROUTE' in cap),
 ('serialized browser resources', 'mad4b-native-lazy-browser-global.lock' in run and '--cpus=1.3' in run),
 ('default-on but explicit current certificate', 'critical_css' in run and 'needs_native_browser_qa' in (p/'assess.py').read_text()),
 ('separate company timers', 'run-site.sh source' in source and 'run-site.sh wayfa' in wayfa),
 ('real idle admission before expensive public crawl',
  'CSS_IDLE_ADMISSION' in run and run.index('CSS_IDLE_ADMISSION')<run.index('stamp=$(date -u')),
 ('browser negative QA is a successful native-CSS HOLD',
  'no fully certified language in batch; fair queue advances' in run),
 ('both renewal timers retry each 15 minutes with company stagger',
  '*:05,20,35,50:00 UTC' in (p/'systemd/mad4b-critical-css-source.timer').read_text()
  and '*:12,27,42,57:00 UTC' in (p/'systemd/mad4b-critical-css-wayfa.timer').read_text()),

 ('legacy Wayfa publisher explicitly uses strict existing v1 adapter',
  'MAD4B_CSS_NATIVE_V1_COMPAT' in run and 'MANIFEST_VERSION' in run and
  "if os.environ.get('MAD4B_CSS_NATIVE_V1_COMPAT','0') == '1':" in pub),

 ('CSS pressure admission prevents unreliable QA', 'QA_LOAD_OK' in run and 'high VPS load' in run),
 ('source preload is mobile-scoped, not tablet-wide',
  'preloadMedia' in (p.parents[1]/'wp-content/mu-plugins/415-mad4b-critical-css-orchestrator.php').read_text()
  and "ADAPTIVE_MAX?' media=" in vfy),
 ('pinned SHA matches source renderer staging exact',
  hashlib.sha256((p.parents[1]/'wp-content/mu-plugins/415-mad4b-critical-css-orchestrator.php').read_bytes()).hexdigest() in run),
 ('last-moment publisher refuses CPU-contested QA', 'HOLD_HIGH_VPS_LOAD_AT_PUBLISH' in pub and 'os.getloadavg()[0]' in pub),
 ('public v1 CSS assets explicitly ignore restrictive umask',
  'os.fchmod(stream.fileno(),0o644)' in pub and 'GENERATED_PUBLIC_ASSET_NOT_READABLE' in pub),
 ('Wayfatours successful v1 publication refreshes only its home cache',
  'wayfa-home-cache-refresh.py' in run and 'wayfa_home_firstpaint_epoch' in
  (p/'wayfa-home-cache-refresh.py').read_text()),
 ('existing Wayfa proofs self-heal stale home cache without new Chromium runs',
  'cache-recovery-inventory.json' in run and "manifest_match') is True" in run
  and "status')!='certified_active'" in run),


 ('browser QA reproduces real loader fallback', 'mad4b-qa-complete' in vfy and 'restoreCss' in vfy and '6500' in vfy),

]
for label,ok in checks:
 assert ok,label
 print('PASS',label)
print('PASS %d/%d critical-CSS dual-site source invariants'%(len(checks),len(checks)))

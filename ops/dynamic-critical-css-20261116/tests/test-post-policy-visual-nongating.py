#!/usr/bin/env python3
"""Post-policy observations must NEVER grant or veto public CSS policy."""
import pathlib,sys,ast
D=pathlib.Path(sys.argv[1])
py=(D/'post-policy-visual-watch.py').read_text()
js=(D/'tools/observe-public-visual.js').read_text()
timer=(D/'systemd/mad4b-post-policy-visual-watch.timer').read_text()
svc=(D/'systemd/mad4b-post-policy-visual-watch.service').read_text()
run=(D/'run-public-route.py').read_text()
reg=(D/'refresh-source-families.py').read_text()
checks=0
def test(name,ok):
 global checks
 assert ok,name
 checks+=1;print('PASS',name)
ast.parse(py)
test('only original WordPress catalog routes are sampled',all(x in py for x in ("'sample_paths'","'source-families-latest.json'","fam.queue.safe_catalog(catalog,site)")))
test('unverified policy routes still receive post-policy visual observation',"group.get('next_canary')" in py)
test('no user cookies, secrets or private website paths',all(x not in js for x in ['Cookie:', 'Authorization:', 'localStorage', 'sessionStorage', 'fetch(']))
test('responsive small mobile and tablet checks', 'widths=[390,768]' in js)
test('visual LCP CLS and horizontal overflow observed',all(x in js for x in ['layout-shift','largest-contentful-paint','viewportOverflowPx','styleSheetCount']))
test('safe menu interaction with Escape, no form submission',all(x in js for x in ['.elementor-menu-toggle','menu.click','keyboard.press(\'Escape\')']) and 'submit(' not in js)
test('alerts are standalone and never mutate WordPress',all(x not in py for x in ['wp eval','wp option update','wp post','mysql','wp-cli']))
test('post-policy visual report never imported by original worker', 'post-policy-visual-watch' not in run and 'visual-interaction-health-latest' not in run)
test('post-policy visual report never imported by source policy', 'visual-interaction-health-latest' not in reg)
test('resource admission and original browser lock', "'public-route-cpu-guard.py'" in py and '/run/lock/mad4b-native-lazy-browser-global.lock' in py)
test('service cannot write any WordPress tree', 'ProtectSystem=strict' in svc and 'ReadWritePaths=/opt/mad4b-company-provisioning/reports/public-critical-css-v1' in svc)
test('daily persisted timer never controls source policy service', 'OnCalendar=*-*-* 03:41:00 UTC' in timer and 'Unit=mad4b-post-policy-visual-watch.service' in timer)
test('native template policy unaffected by QA source failure', 'POLICY_ACTIVE_BROWSER_EVIDENCE_DEFERRED' in run)
test('post-policy report is observation-only', 'purpose:\'visual_and_interaction_observation_only_never_policy_gate\'' in js)
print('POST_POLICY_VISUAL_NONGATING',checks)

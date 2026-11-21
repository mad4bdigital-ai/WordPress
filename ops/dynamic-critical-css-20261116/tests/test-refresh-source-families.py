from pathlib import Path
import sys
D=Path(sys.argv[1]);py=(D/'refresh-source-families.py').read_text();svc=(D/'systemd/mad4b-public-source-families-refresh.service').read_text();timer=(D/'systemd/mad4b-public-source-families-refresh.timer').read_text();checks=[]
def test(name,ok):
 assert ok,name
 checks.append(name);print('PASS',name)
test('separate read-only source coordinator',
     ("for site in ('source','wayfa')" in py or
      "for site in selected_sites(sys.argv[1:])" in py)
     and 'public-source-families.py' in py)
test('budget guard before any site WPCLI',py.index("'public-route-cpu-guard.py'")<py.index('runner.obs(php,host,env)'))
test('original source and runtime code parity both verified',
     'runner.approved_native_runtime_sha(site,wp)' in py
     and all(x in (D/'run-public-route.py').read_text() for x in
             ('421-mad4b-public-critical-css-coverage.php',
              '422-mad4b-public-critical-css-runtime.php')))
test('authoritative active WPML catalog only',"catalog=runner.catalog(php,host)" in py)
test('private atomic source group report',"families.queue.atom(root/'source-families-latest.json',result)" in py)
test('systemd unit only invokes reporting script','refresh-source-families.py' in svc and 'run-public-route.py source' not in svc)
test('unit resource limits and no new privileges',all(x in svc for x in ('CPUQuota=80%','Nice=19','NoNewPrivileges=true','UMask=0077','TimeoutStartSec=490')))
test('timer persists autonomous refresh with stagger',all(x in timer for x in ('OnCalendar=*-*-* *:07,37:00 UTC','Persistent=true','RandomizedDelaySec=90')))
test('source coordinator contains no Chromium or certificate publishing',all(x not in py for x in ('runner.browser(',"'public-route-certificate.py'",'queue.record(',"'certified'")))
test('source registry refresh may write only its private report tree',
    all(x in svc for x in ('ProtectSystem=strict','ReadWritePaths=/opt/mad4b-company-provisioning/reports/public-critical-css-v1','PrivateTmp=true','PrivateDevices=true','CapabilityBoundingSet=')))
test('certification worker updates source timestamp with the same contract', "report['refreshed_at_utc']" in (D/'run-public-route.py').read_text())
test('template mapping is automatic and Chromium-free',
     'public-template-variant-index.py' in py
     and 'variants.refresh_file(' in py
     and 'variant_index=index' in py
     and 'OBSERVE_TEMPLATE_HTML_NONBLOCKING' in py)
test('visual QA cannot veto original template policy state',
     'original template policy' in py.lower()
     or 'always-on native policy' in py.lower())
print(f'PASS {len(checks)}/{len(checks)} automatic source registry admission tests')

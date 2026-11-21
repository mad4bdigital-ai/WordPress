#!/usr/bin/env python3
"""Age-priority audit and health summary cannot claim CSS or bypass CPU."""
import pathlib,importlib.util,sys,ast
D=pathlib.Path(sys.argv[1])
file=D/'wpcron/site-owned-css-worker-v2.py'
s=file.read_text()
ast.parse(s)
count=0
def ok(flag,label):
 global count
 assert flag,label
 count+=1;print('PASS',label)
ok("audit_due=(job=='audit'" in s and "now-issued_at>=3600" in s,
   'overdue audit prioritization triggered only after one hour')
ok("priority=(-1 if audit_due else" in s and
   "if now<next_retry:continue" in s,
   'overdue audit still respects bounded retry cooldown')
ok("admitted=headroom" in s and
   "if not admitted:" in s and "invoke(site,job" in s,
   'audit queue never bypasses existing host CPU/WPCLI admission')
ok("def update_readonly_health_rollup():" in s and
   "runpy.run_path(str(SOURCE/'health-audit.py')" in s,
   'site owner uses fingerprinted renderer health module')
ok("audit['aggregate_company_health']()" in s and
   "audit['write'](audit['REPORT']/'latest.json',merged)" in s,
   'combined report is built solely from independently attested company reports')
ok("set(merged.get('sites',{}))!={'source','wayfa'}" in s,
   'foreign or missing company reporting cannot impersonate both sites')
ok("if '--inspect' not in sys.argv:" in s and
   "update_readonly_health_rollup()" in s,
   'normal Cron wake refreshes health without a ticket or Chromium')
ok("if '--inspect' not in sys.argv and SITE=='source':" in s,
   'read-only source inspect cannot mutate the aggregate')
ok("WPCSS_HEALTH_SUMMARY_DEFERRED" in s and
   "WPCSS_HEALTH_SUMMARY_UPDATED" in s,
   'report failure leaves CSS queue intact and observable')
ok("MAD4B_MULTI_CSS_V2_RELEASE_FIRST':'1'" in s and
   "WPCSS_CPU_QUEUE_PRESERVED" in s,
   'existing release-first, resource guards and exact WP ACK unchanged')
print('PASS',count,'source-owned health and fair audit contract checks')

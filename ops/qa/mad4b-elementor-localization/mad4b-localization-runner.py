#!/usr/bin/env python3
"""Exact-host WP-CLI localization reconciliation; no user impersonation or production access."""
import os,sys,json,subprocess,datetime
assert os.geteuid()==0, 'host_root_required'
ENV=[
'MAD4B_CLI_TARGET_SITE_UUID=e5e64bca-ed62-48d2-b2b3-dff693cd702a',
'MAD4B_CLI_TARGET_ORIGIN=https://tours.dev.mad4b.com',
'MAD4B_CLI_TARGET_DATABASE=etg_commerce_staging',
'MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS=staging'
]
BASE=['/usr/bin/docker','exec','-u','www-data']+[x for e in ENV for x in ['-e',e]]+['etg-commerce-staging-php','wp']
def run(args,timeout=35):
    p=subprocess.run(BASE+list(args),capture_output=True,text=True,timeout=timeout)
    if p.returncode:raise RuntimeError('command_failed: '+p.stderr[:150])
    return json.loads(p.stdout)
origin=subprocess.run(BASE+['option','get','home'],capture_output=True,text=True,timeout=20)
if origin.returncode or origin.stdout.strip()!='https://tours.dev.mad4b.com':
    raise SystemExit('exact_site_identity_failed')
print('localization:',json.dumps(run(['mad4b','localization','scan']),separators=(',',':')))
plan=run(['mad4b','readiness','plan'])
if not plan['eligible']:raise SystemExit('readiness_blocked: '+','.join(plan['blockers']))
if plan['due']:
    r=run(['mad4b','readiness','run',
        '--expected-plan='+plan['plan_sha256'],
        '--confirm='+plan['confirmation']],timeout=50)
    print('readiness:',json.dumps({'status':r.get('status'),'scanner_result':r.get('scanner_result')},separators=(',',':')))
else:print('readiness:',json.dumps({'status':'not_due','next_due_at':plan['due_at_utc']}))

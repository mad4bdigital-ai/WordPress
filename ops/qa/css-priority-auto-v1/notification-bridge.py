#!/usr/bin/env python3
"""Local worker -> native MAD4B WP notification feed, no network or SMTP."""
import json,sys,re,subprocess
if len(sys.argv)!=4:raise SystemExit('Usage: notification-bridge.py <path> <certified|hold|native|failed> <safe-reason>')
path,status,reason=sys.argv[1:]
if not re.fullmatch(r'/[a-zA-Z0-9/_~.-]{0,239}',path) or '..' in path or '//' in path:raise SystemExit('Bad path')
if status not in ('certified','hold','native','failed'):raise SystemExit('Bad status')
reason=re.sub(r'[^a-zA-Z0-9_ .:-]','',reason)[:110]
params=','.join(map(json.dumps,[path,status,reason]))
script='echo wp_json_encode(MAD4B_SCP_Adaptive_Asset_Intelligence::acknowledge_worker('+params+'));'
cmd=['docker','exec','-u','www-data','-w','/var/www/html','etg-commerce-staging-php','wp','eval',script,'--quiet']
r=subprocess.run(cmd,capture_output=True,text=True,timeout=32)
if r.returncode:raise SystemExit('WP notification bridge rejected: '+r.stderr[:250])
try:out=json.loads(r.stdout.strip())
except:raise SystemExit('Bad WordPress event response: '+r.stdout[:110])
if out.get('ok') is not True:raise SystemExit('WordPress event rejected: '+json.dumps(out))
print(json.dumps({'path':path,'status':status,'stored':out.get('event',{}).get('stored',False),'contract':'mad4b.notification.event.v1'}))

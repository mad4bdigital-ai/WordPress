#!/usr/bin/env python3
"""Refresh exactly published site-local routes, 30 minute cadence."""
import subprocess,json,hashlib,datetime,os,sys
from pathlib import Path
ROOT=Path('/opt/mad4b-company-provisioning')
D=ROOT/'reports/runtime-policy-next-20261108'
PHP='php-wkgtkzta35wkkgouwt6ruljc'
IN_CONTAINER='/var/www/html/wp-content/mu-plugins/assets/mad4b-public-route-source-v1.php'
INVENTORY=D/'qa/public-inventory.json'
REGISTRY=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins/assets/mad4b-public-route-registry-v1.php')
proc=subprocess.run(['docker','exec','-w','/var/www/html',PHP,'wp','eval-file',IN_CONTAINER,
                     '--allow-root','--skip-themes'],capture_output=True,text=True,timeout=112)
if proc.returncode:raise RuntimeError('WP_CLI_READ_FAIL:'+proc.stderr[-180:])
doc=json.loads(proc.stdout)
if doc.get('contract')!='mad4b.public-route-inventory.v1' or not doc.get('home','').startswith('https://tours.dev.mad4b.com/'):
 raise RuntimeError('UNTRUSTED_ROUTE_INVENTORY')
if len(doc.get('routes',{}))<150 or len(doc['routes'])>10000:raise RuntimeError('ROUTE_INVENTORY_OUT_OF_BOUNDS')
previous=REGISTRY.read_bytes() if REGISTRY.is_file() else b''
INVENTORY.write_text(json.dumps(doc,ensure_ascii=False,sort_keys=True)+'\n')
check=subprocess.run([sys.executable,str(ROOT/'bin/mad4b-public-route-registry-compile-v1.py'),
 '--inventory',str(INVENTORY),'--out',str(REGISTRY)],capture_output=True,text=True,timeout=30)
if check.returncode:raise RuntimeError('ROUTE_COMPILATION_FAILED:'+check.stderr[-180:])
result=json.loads(check.stdout)
if result['eligible_count']<150:raise RuntimeError('PUBLIC_ROUTES_UNEXPECTEDLY_DROPPED')
state={'contract':'mad4b.public-route-registry-refresh.v1',
 'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
 'registry_sha256':result['sha256'],'eligible_count':result['eligible_count'],
 'excluded_count':result['excluded_count'],
 'changed':previous!=REGISTRY.read_bytes(),
 'result':'PASS'}
p=ROOT/'reports/public-route-registry-current.json'
temp=p.with_suffix('.tmp')
temp.write_text(json.dumps(state,indent=2)+'\n');os.replace(temp,p)
print(json.dumps(state))

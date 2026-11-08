#!/usr/bin/env python3
"""MAD4B Cloud Startup CDN private-context collision sentinel; never mutates."""
import argparse,datetime,json,os,secrets,subprocess,tempfile
from pathlib import Path
from urllib.parse import urlparse
ROOT=Path('/opt/mad4b-company-provisioning')
INV=ROOT/'reports/hostinger-cloud-startup-sites-current.json'
OUT=ROOT/'reports/company-hostinger-cloud-cdn-edge-current.json'
ACCOUNT_ORIGIN_IP='147.93.49.130'
TARGETS={'https://tourism.almallahgroup-mg.com','https://www.wovacation.com'}
CASES=[
 ('anonymous',[]),
 ('currency',['-H','X-MAD4B-Currency: EUR']),
 ('market',['-H','X-MAD4B-Market-Context: alternate-test']),
 ('passport',['-H','X-MAD4B-Passport: test']),
 ('auth',['-H','Cookie: wordpress_logged_in_mad4bprobe=invalid']),
 ('woo_session',['-H','Cookie: wp_woocommerce_session_mad4bprobe=invalid']),
]
def probe(origin,label,args,direct=False):
    host=urlparse(origin).hostname
    argv=['curl','-sS','-D','-','-o','/dev/null','--connect-timeout','5','--max-time','16']
    if direct:argv+=['--resolve',host+':443:'+ACCOUNT_ORIGIN_IP]
    argv+=[*args,origin+'/']
    r=subprocess.run(argv,capture_output=True,text=True,timeout=20)
    blocks=[x for x in r.stdout.replace('\r','').split('\n\n') if x.startswith('HTTP/')]
    if r.returncode or not blocks:return {'state':'ERROR','cause':'HTTP_UNAVAILABLE'}
    header={}
    for line in blocks[-1].splitlines()[1:]:
        if ':' in line:
            k,v=line.split(':',1);header[k.strip().lower()]=v.strip()
    fields={k:header.get(k) for k in ('cache-control','x-hcdn-cache-status','x-litespeed-cache')}
    status=int(blocks[-1].splitlines()[0].split()[1])
    state='PASS'
    if status!=200:state='ERROR'
    if label!='anonymous':
        if not direct and header.get('x-hcdn-cache-status')=='HIT':
            state='CRITICAL_CDN_SENSITIVE_CONTEXT_HIT'
        elif 'no-store' not in header.get('cache-control',''):
            state='DRIFT_SENSITIVE_NOT_NO_STORE'
        elif header.get('x-litespeed-cache','').lower()=='hit':
            state='DRIFT_SENSITIVE_LITESPEED_HIT'
    return {'state':state,'http':status,**fields,'scope':'origin' if direct else 'cdn'}
def evaluate(results):
    statuses=[v['state'] for site in results.values() for v in site.values()]
    if any(x.startswith('CRITICAL') for x in statuses):return 'CRITICAL'
    if any(x!='PASS' for x in statuses):return 'DRIFT'
    return 'PASS'
def main():
    ap=argparse.ArgumentParser();ap.add_argument('--selftest',action='store_true');ap.add_argument('--strict',action='store_true')
    opts=ap.parse_args()
    if opts.selftest:
        assert evaluate({'a':{'b':{'state':'CRITICAL_CDN_SENSITIVE_CONTEXT_HIT'}}})=='CRITICAL'
        assert evaluate({'a':{'b':{'state':'PASS'}}})=='PASS'
        print('CLOUD_CDN_SENTINEL_SELFTEST_PASS');return
    inv=json.loads(INV.read_text())
    actual={x['home'] for x in inv['sites']}
    assert TARGETS.issubset(actual),'ENROLLED_CLOUD_SITE_IDENTITY_MISSING'
    result={}
    for origin in sorted(TARGETS):
        row={}
        for label,args in CASES:
            row['cdn_'+label]=probe(origin,label,args)
        for label,args in CASES:
            if label!='anonymous':row['origin_'+label]=probe(origin,label,args,direct=True)
        result[urlparse(origin).hostname]=row
        print(json.dumps({'site':origin,'failures':[(k,v['state']) for k,v in row.items() if v['state']!='PASS']}),flush=True)
    verdict=evaluate(result)
    document={'contract':'mad4b.company-hostinger-cloud-cdn-edge-audit.v1',
              'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
              'run_id':secrets.token_hex(12),'verdict':verdict,
              'sites':result,'remediation_hint':'CRITICAL requires verified Hostinger CDN edge purge or disabling HTML edge cache in authorized hPanel/API, followed by independent CDN origin parity test',
              'allowed_mutation':False,'website_html_bodies_collected':False}
    fd,tmp=tempfile.mkstemp(prefix='.cloud-edge-',dir=OUT.parent)
    try:
      with os.fdopen(fd,'w') as f:
        json.dump(document,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
      os.chmod(tmp,0o600);os.replace(tmp,OUT)
    finally:
      if os.path.exists(tmp):os.unlink(tmp)
    print('CLOUD_CDN_EDGE_VERDICT',verdict)
    if opts.strict and verdict!='PASS':raise SystemExit(2)
if __name__=='__main__':main()

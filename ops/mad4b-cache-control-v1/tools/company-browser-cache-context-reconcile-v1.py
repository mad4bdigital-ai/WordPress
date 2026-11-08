#!/usr/bin/env python3
"""MAD4B fleet browser cache contract reconciler: read-only, dynamic company discovery.

The WordPress origin and Nginx are the authorities for page eligibility and
request context. This observer never creates cache entries directly, purges
anything, changes a configuration, or infers private content from URL alone.
"""
import argparse, datetime as dt, json, os, re, secrets, subprocess, tempfile, time
from pathlib import Path
from urllib.parse import urlparse
ROOT=Path('/opt/mad4b-company-provisioning')
POLICY=ROOT/'policies/company-cache-coherence-v1.json'
REPORT=ROOT/'reports/company-browser-cache-context-current.json'
STANDARD_UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/156.0 Safari/537.36'
HTML_TTL=1800
SAFE_ORIGIN_CACHE={'HIT','MISS','STALE','UPDATING','EXPIRED','REVALIDATED'}
def expected_class(has_cookie,safe_cookie,approved,sensitive):
    if sensitive or not approved or (has_cookie and not safe_cookie):return 'private_no_store'
    return 'private_bounded' if has_cookie else 'public_bounded'
def expectation(label):
    return {
        'public_bounded':'public, max-age=1800, must-revalidate',
        'private_bounded':'private, max-age=1800, must-revalidate',
        'private_no_store':'private, no-store'
    }[label]
def command(args,timeout=20):
    return subprocess.run(args,capture_output=True,text=True,timeout=timeout,check=False)
def read_headers(url,cookie=None,custom_headers=None):
    args=['curl','-sS','-D','-','-o','/dev/null','--connect-timeout','4','--max-time','17','-A',STANDARD_UA]
    if cookie:args+=['-H','Cookie: '+cookie]
    for k,v in (custom_headers or {}).items():args+=['-H',k+': '+v]
    args.append(url)
    call=command(args,timeout=20)
    if call.returncode:raise RuntimeError('HTTP_TRANSPORT_'+str(call.returncode))
    segments=[z for z in call.stdout.replace('\r','').split('\n\n') if z.startswith('HTTP/')]
    if not segments:raise RuntimeError('HTTP_HEADER_UNAVAILABLE')
    lines=segments[-1].splitlines()
    try:code=int(lines[0].split()[1])
    except (ValueError,IndexError):raise RuntimeError('HTTP_STATUS_UNPARSEABLE')
    headers={}
    for line in lines[1:]:
        if ':' in line:
            key,value=line.split(':',1)
            headers.setdefault(key.lower().strip(),[]).append(value.strip())
    cache=headers.get('x-wayfa-origin-cache',headers.get('x-mad4b-preset-cache',[]))
    return {'code':code,'cache_control':headers.get('cache-control',[]),
            'origin_cache':cache,'vary':headers.get('vary',[])}
def verify(name,url,classification,cookie=None,custom_headers=None,allow_redirect=False):
    began=time.monotonic()
    try:
        x=read_headers(url,cookie,custom_headers)
        expected=expectation(classification)
        control=x['cache_control']
        code_good=x['code']==200 or (allow_redirect and x['code'] in (301,302,303,307,308))
        control_good=len(control)==1 and (control[0]==expected if classification!='private_no_store'
            else ('private' in control[0].lower().split(',')[0] and
                  'no-store' in control[0].lower() and
                  'public' not in control[0].lower()))
        origin=x['origin_cache']
        origin_good=(not origin or
            (len(origin)==1 and (origin[0]=='BYPASS' if classification=='private_no_store'
                                 else origin[0] in SAFE_ORIGIN_CACHE)))
        passed=code_good and control_good and origin_good
        return {'probe':name,'status':'PASS' if passed else 'FAIL','http':x['code'],
                'cache_control':control,'origin_cache':origin,
                'expected':classification,'duration_ms':round((time.monotonic()-began)*1000)}
    except (OSError,RuntimeError,subprocess.TimeoutExpired) as e:
        return {'probe':name,'status':'ERROR','reason':str(e)[:180],
                'expected':classification,'duration_ms':round((time.monotonic()-began)*1000)}
def company_row(site,spec,langs):
    origin=spec.get('origin','')
    parsed=urlparse(origin)
    if parsed.scheme!='https' or not parsed.netloc or parsed.path not in ('','/') or not isinstance(langs,list):
        return {'status':'UNENROLLED_BROWSER_POLICY','probes':[],'reason':'MISSING_SAFE_ORIGIN_OR_LOCALES'}
    if not langs or len(langs)>12 or any(not re.fullmatch(r'[a-z]{2,3}',v) for v in langs):
        return {'status':'UNENROLLED_BROWSER_POLICY','probes':[],'reason':'INVALID_LANGUAGE_MANIFEST'}
    probes=[]
    for lang in langs:
        path='/' if lang=='en' else '/'+lang+'/'
        probes.append(verify('anonymous_'+lang,origin+path,'public_bounded'))
    probes.append(verify('safe_analytics',origin+'/','private_bounded',
                         cookie='_ga=GA1.1.12345.67890'))
    for lang in langs:
        path='/' if lang=='en' else '/'+lang+'/'
        probes.append(verify('safe_locale_'+lang,origin+path,'private_bounded',
                             cookie='wp-wpml_current_language='+lang))
    wrong=next((x for x in langs if x!='en'),None)
    if wrong:
        probes.append(verify('locale_conflict',origin+'/'+wrong+'/','private_no_store',
                             cookie='wp-wpml_current_language=en'))
    probes.append(verify('auth_cookie',origin+'/','private_no_store',
                         cookie='wordpress_logged_in_fake_probe=none'))
    probes.append(verify('currency_context',origin+'/','private_no_store',
                         custom_headers={'X-MAD4B-Currency':'EUR'}))
    probes.append(verify('query_context',origin+'/?mad4b_contract_probe=1','private_no_store'))
    probes.append(verify('checkout',origin+'/checkout/','private_no_store',allow_redirect=True))
    fails=[x['probe'] for x in probes if x['status']!='PASS']
    return {'status':'PASS' if not fails else 'ALERT',
            'lang_count':len(langs),'pass_count':len(probes)-len(fails),
            'total_count':len(probes),'failed_probes':fails,'probes':probes}
def atomic(path,doc):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.browser-cache-context-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as stream:
            json.dump(doc,stream,sort_keys=True,indent=2)
            stream.write('\n');stream.flush();os.fsync(stream.fileno())
        os.chmod(tmp,0o600)
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def selftest():
    assert expected_class(False,False,True,False)=='public_bounded'
    assert expected_class(True,True,True,False)=='private_bounded'
    for args in [(True,False,True,False),(True,True,False,False),(False,False,True,True)]:
        assert expected_class(*args)=='private_no_store'
    assert expectation('public_bounded')=='public, max-age=1800, must-revalidate'
    assert expectation('private_no_store')=='private, no-store'
    print('BROWSER_CACHE_CONTEXT_CONTRACT_SELFTEST_PASS')
def main():
    opts=argparse.ArgumentParser()
    opts.add_argument('--selftest',action='store_true')
    opts.add_argument('--strict',action='store_true')
    opts.add_argument('--dry-run',action='store_true')
    opts.add_argument('--entry-point',choices=('cli','systemd_timer'),default='cli')
    args=opts.parse_args()
    if args.selftest:selftest();return
    pol=json.loads(POLICY.read_text())
    model=pol.get('cookie_decision_model',{})
    assert model.get('public_ttl_seconds')==HTML_TTL
    assert model.get('browser_ttl_seconds')==HTML_TTL
    registry=pol.get('company_sites',{})
    langs=model.get('sites',{})
    if args.dry_run:
        print(json.dumps({k:{'origin':v.get('origin'),'languages':langs.get(k,[])} for k,v in registry.items()}))
        return
    now=dt.datetime.now(dt.timezone.utc).isoformat()
    run_id=secrets.token_hex(12)
    rows={}
    for site,spec in registry.items():
        try:rows[site]=company_row(site,spec,langs.get(site,[]))
        except Exception as e:rows[site]={'status':'ERROR','reason':str(e)[:200],'probes':[]}
        print(json.dumps({'company':site,'result':rows[site]['status'],
                          'passed':rows[site].get('pass_count'),
                          'total':rows[site].get('total_count'),
                          'failed':rows[site].get('failed_probes',[]) }),flush=True)
    verdict='PASS' if rows and all(x['status']=='PASS' for x in rows.values()) else 'ALERT'
    out={'contract':'mad4b.company-browser-cache-context-reconciler.v1',
         'utc':now,'run_id':run_id,'entry_point':args.entry_point,
         'verdict':verdict,'mode':'read_only_no_redis_or_nginx_mutations',
         'registry':str(POLICY),'sites':rows,'user_cookies_or_html_collected':False}
    atomic(REPORT,out)
    print('BROWSER_CACHE_FLEET_VERDICT',verdict,len(rows),flush=True)
    if args.strict and verdict!='PASS':raise SystemExit(2)
if __name__=='__main__':main()


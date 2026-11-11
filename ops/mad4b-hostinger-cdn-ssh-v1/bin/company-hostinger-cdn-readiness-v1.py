#!/usr/bin/env python3
"""Read-only Hostinger CDN public HTML policy monitor (registered company scopes)."""
import argparse,datetime,hashlib,importlib.util,json,os,re,secrets,shlex,subprocess,sys,tempfile,time
from pathlib import Path
from urllib.parse import urlparse
BASE=Path('/opt/mad4b-company-provisioning')
POL=BASE/'policies/company-hostinger-cdn-sites-v1.json'
REPORT=BASE/'reports/company-hostinger-cdn-readiness-current.json'
OBSERVER=Path('/opt/mad4b-company-transport/universal-runtime-v1/etg_staging_release_observer.py')
ARE_OBSERVER=Path('/opt/mad4b-company-provisioning/source-owned/cache-lifecycle/hostinger-cache.py')
EXACT_ROOTS={
'etg_registered_ssh':{
 '/home/u783534439/domains/egypttourgates.com/public_html',
 '/home/u783534439/domains/egypttourgates.com/public_html/staging'},
'are_registered_ssh':{
 '/home/u948953904/domains/allroyalegypt.com/public_html',
 '/home/u948953904/domains/allroyalegypt.com/public_html/staging'}
}
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/156.0 Safari/537.36'
SAFE_FIELDS=('cache-control','x-hcdn-cache-status','x-litespeed-cache','age','x-mad4b-edge-policy')
def invoke(argv,timeout=20):
    return subprocess.run(argv,capture_output=True,text=True,timeout=timeout,check=False)
def fetch(base,cookie=None,path='/',headers=None):
    argv=['curl','-sS','-D','-','-o','/dev/null','--connect-timeout','5','--max-time','17','-A',UA]
    if cookie:argv+=['-H','Cookie: '+cookie]
    for name,value in (headers or {}).items():argv+=['-H',name+': '+value]
    argv.append(base+path)
    res=invoke(argv,20)
    if res.returncode:raise RuntimeError('HTTP_REQUEST_'+str(res.returncode))
    chunks=[x for x in res.stdout.replace('\r','').split('\n\n') if x.startswith('HTTP/')]
    if not chunks:raise RuntimeError('HTTP_MISSING_RESPONSE')
    block=chunks[-1].splitlines()
    status=int(block[0].split()[1])
    values={}
    for line in block[1:]:
        if ':' not in line:continue
        name,value=line.split(':',1)
        name=name.lower().strip()
        if name in SAFE_FIELDS:values[name]=value.strip()[:140]
        if name=='x-hcdn-request-id':values['cdn_header_present']=True
    return {'http':status,**values}
def get_ssh():
    modules={}
    for name,path in (('etg_registered_ssh',OBSERVER),('are_registered_ssh',ARE_OBSERVER)):
        if path.is_symlink() or path.stat().st_uid!=0 or path.stat().st_mode & 0o022:
            raise RuntimeError('ROOT_OWNED_PINNED_OBSERVER_REQUIRED')
        spec=importlib.util.spec_from_file_location('mad4b_cdn_'+name,path)
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        command=module.SSH if name=='etg_registered_ssh' else module.ssh('are')
        if not isinstance(command,list) or not all(x in command for x in ('StrictHostKeyChecking=yes','IdentitiesOnly=yes','-i')):
            raise RuntimeError('REGISTERED_PINNED_SSH_REQUIRED')
        modules[name]=command
    return modules
def remote_read(command,scope,root,origin):
    if root not in EXACT_ROOTS.get(scope,set()):
        raise RuntimeError('REMOTE_ROOT_OUTSIDE_APPROVED_SITE')
    cd='cd '+shlex.quote(root)+' && '
    def wp(args):
        result=invoke(command+[cd+'wp '+shlex.join(args)+' --skip-themes --skip-plugins'],28)
        if result.returncode:raise RuntimeError('SSH_WPCLI_READ_FAILED')
        return result.stdout.strip()
    home=wp(['option','get','home'])
    if home!=origin:raise RuntimeError('SSH_SITE_IDENTITY_MISMATCH')
    option_names=['litespeed.conf.cache-ttl_pub','litespeed.conf.cache-ttl_frontpage',
        'litespeed.conf.cache-ttl_priv','mad4b_cache_lifecycle_edge_isolation_verified_v1']
    values={}
    for key in option_names:
        try:values[key]=wp(['option','get',key])
        except RuntimeError:values[key]='UNSET_OR_NOT_READABLE'
    # Separate marker from provider-generated LiteSpeed rules, so future plugin
    # .htaccess rewrites cannot silently drop the MAD4B sensitive header guard.
    guard=invoke(command+[cd+"grep -Fq '# BEGIN MAD4B HOSTINGER STATEFUL CONTEXT BYPASS V2' .htaccess"],15)
    values['managed_header_bypass_marker_present']=guard.returncode==0
    values['registered_ssh_verified']=True
    values['site_home_verified']=True
    return values
def verdict_for_probe(headers,kind):
    status=headers.get('x-hcdn-cache-status')
    if kind=='auth':
        if status=='HIT':return 'CRITICAL_AUTH_CDN_HIT'
        if headers.get('x-litespeed-cache','').lower()=='hit':return 'DRIFT_AUTH_COOKIE_LSCACHE_HIT'
        if 'no-store' not in headers.get('cache-control','').lower():return 'DRIFT_AUTH_COOKIE_NOT_NO_STORE'
    if kind in ('currency','market','passport'):
        if status=='HIT':return 'CRITICAL_SENSITIVE_CONTEXT_CDN_HIT'
        if headers.get('x-litespeed-cache','').lower()=='hit':return 'DRIFT_SENSITIVE_CONTEXT_LSCACHE_HIT'
        if 'no-store' not in headers.get('cache-control','').lower():return 'DRIFT_SENSITIVE_CONTEXT_NOT_NO_STORE'
    if kind=='anonymous' and status=='HIT':
        cc=headers.get('cache-control','').lower()
        if 'private' in cc or 'no-store' in cc:return 'CRITICAL_CDN_HIT_PRIVATE_RESPONSE'
    return 'OK'
def inspect_site(site,spec,policy,ssh_module):
    origin=spec['url']
    parsed=urlparse(origin)
    assert parsed.scheme=='https' and parsed.netloc and not parsed.query and parsed.path==''
    row={'origin':origin,'readback_capability':spec['remote_readback'],'probes':{},'reasons':[]}
    for name,cookie,hdr in [
      ('anonymous',None,None),
      ('analytics','_ga=GA1.1.12345.67890',None),
      ('auth','wordpress_logged_in_mad4bprobe=invalid',None),
      ('currency',None,{'X-MAD4B-Currency':'EUR'}),
      ('market',None,{'X-MAD4B-Market-Context':'alternate-test'}),
      ('passport',None,{'X-MAD4B-Passport':'test'})
    ]:
        try:
            response=fetch(origin,cookie=cookie,headers=hdr)
            response['verdict']=verdict_for_probe(response,name)
            row['probes'][name]=response
        except Exception as e:
            row['probes'][name]={'verdict':'ERROR','error':type(e).__name__}
    start=row['probes']['anonymous']
    if not start.get('cdn_header_present'):row['reasons'].append('HOSTINGER_CDN_HEADER_NOT_VERIFIED')
    if start.get('http')!=200:row['reasons'].append('PUBLIC_HOMEPAGE_NOT_HTTP_200')
    if start.get('cache-control','').lower().startswith('private'):
        row['reasons'].append('ANONYMOUS_HTML_CURRENTLY_BROWSER_PRIVATE')
    if spec['remote_readback'] in ssh_module:
        try:
            remote=remote_read(ssh_module[spec['remote_readback']],
                               spec['remote_readback'],spec['remote_root'],origin)
            row['ssh_readback']=remote
            for key in ('litespeed.conf.cache-ttl_pub','litespeed.conf.cache-ttl_frontpage'):
                if remote.get(key)!=str(policy['target_html_ttl_seconds']):
                    row['reasons'].append('LITESPEED_TTL_DRIFT_'+key)
            if remote.get('managed_header_bypass_marker_present') is not True:
                row['reasons'].append('STATEFUL_HEADER_GUARD_MISSING')
            if remote.get('mad4b_cache_lifecycle_edge_isolation_verified_v1')!='1':
                row['reasons'].append('EDGE_CONTEXT_ISOLATION_NOT_CERTIFIED')
        except Exception as e:
            row['ssh_readback']={'verified':False,'error':str(e)[:180]}
            row['reasons'].append('REGISTERED_REMOTE_SSH_READBACK_FAILED')
    else:row['ssh_readback']={'verified':False,'reason':'REGISTERED_SSH_PROFILE_NOT_ENROLLED'}
    severe=[k for k,v in row['probes'].items() if v['verdict'].startswith('CRITICAL')]
    for key,check in row['probes'].items():
        if check['verdict'].startswith('DRIFT'):
            row['reasons'].append(check['verdict']+'_'+key.upper())
    row['status']='CRITICAL' if severe else ('REMEDIATION_REQUIRED' if row['reasons'] else 'OBSERVED_OK')
    row['security_incidents']=severe
    return row
def atomic_report(data):
    REPORT.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.hostinger-cdn-',dir=REPORT.parent)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(data,f,indent=2,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o600);os.replace(tmp,REPORT)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def selftest():
    assert verdict_for_probe({'x-hcdn-cache-status':'HIT'},'auth')=='CRITICAL_AUTH_CDN_HIT'
    assert verdict_for_probe({'x-hcdn-cache-status':'BYPASS','x-litespeed-cache':'hit'},'auth')=='DRIFT_AUTH_COOKIE_LSCACHE_HIT'
    assert verdict_for_probe({'x-hcdn-cache-status':'BYPASS','x-litespeed-cache':'miss','cache-control':'private, no-store'},'auth')=='OK'
    assert verdict_for_probe({'x-hcdn-cache-status':'HIT'},'currency')=='CRITICAL_SENSITIVE_CONTEXT_CDN_HIT'
    assert verdict_for_probe({'x-hcdn-cache-status':'MISS','x-litespeed-cache':'hit'},'market')=='DRIFT_SENSITIVE_CONTEXT_LSCACHE_HIT'
    assert verdict_for_probe({'x-hcdn-cache-status':'MISS','x-litespeed-cache':'miss','cache-control':'private, max-age=0'},'passport')=='DRIFT_SENSITIVE_CONTEXT_NOT_NO_STORE'
    assert verdict_for_probe({'x-hcdn-cache-status':'MISS','x-litespeed-cache':'miss','cache-control':'private, no-store'},'currency')=='OK'
    assert verdict_for_probe({'x-hcdn-cache-status':'HIT','cache-control':'private, max-age=0'},'anonymous')=='CRITICAL_CDN_HIT_PRIVATE_RESPONSE'
    assert verdict_for_probe({'x-hcdn-cache-status':'MISS'},'anonymous')=='OK'
    print('HOSTINGER_CDN_READINESS_SELFTEST_PASS')
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--selftest',action='store_true')
    parser.add_argument('--strict-security',action='store_true')
    parser.add_argument('--entry-point',choices=('cli','systemd_timer'),default='cli')
    args=parser.parse_args()
    if args.selftest:selftest();return
    policy=json.loads(POL.read_text());assert policy['contract']=='mad4b.company-hostinger-cdn-sites.v1'
    module=get_ssh()
    site_rows={}
    for site,spec in policy['sites'].items():
        try:site_rows[site]=inspect_site(site,spec,policy,module)
        except Exception as e:site_rows[site]={'status':'ERROR','error':str(e)[:180]}
        v=site_rows[site]
        print(json.dumps({'site':site,'status':v['status'],'reasons':v.get('reasons',[])},ensure_ascii=False),flush=True)
    state='CRITICAL' if any(s['status']=='CRITICAL' for s in site_rows.values()) else (
      'REMEDIATION_REQUIRED' if any(s['status']!='OBSERVED_OK' for s in site_rows.values()) else 'PASS')
    obj={'contract':'mad4b.company-hostinger-cdn-readiness.v1',
         'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
         'run_id':secrets.token_hex(12),'entry_point':args.entry_point,
         'verdict':state,'target_html_ttl_seconds':policy['target_html_ttl_seconds'],
         'sites':site_rows,'unresolved_ssh_targets':policy.get('unresolved_ssh_targets',[]),
         'mode':'strictly_read_only_no_hostinger_purge_no_redis_flush_no_remote_wordpress_mutation',
         'global_cdn_html_public_activated':False}
    atomic_report(obj)
    print('HOSTINGER_CDN_READINESS',state,len(site_rows))
    if state=='CRITICAL' and args.strict_security:raise SystemExit(2)
if __name__=='__main__':main()

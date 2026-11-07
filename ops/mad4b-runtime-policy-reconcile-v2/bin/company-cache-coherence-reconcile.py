#!/usr/bin/env python3
"""MAD4B per-company cache coherence reconciler v2.
Read-only: no flush, purge, WP writes, config edits or credentials in reports.
Runs on enrolled companies, detects drift and new company enrollment.
"""
from __future__ import annotations
import argparse,datetime as dt,hashlib,json,os,re,subprocess,sys
from pathlib import Path
ROOT=Path('/opt/mad4b-company-provisioning')
POL=ROOT/'policies/company-cache-coherence-v1.json'
REPORT=ROOT/'reports/company-cache-coherence-current.json'
HISTORY=ROOT/'reports/company-cache-coherence-events.jsonl'
FPM_PROBE=ROOT/'reports/cache-policy-reconcile-20261107/fpm-opcache-probe.py'
def command(argv,timeout=23):
    return subprocess.run(argv,capture_output=True,text=True,timeout=timeout,check=False)
def http(url,cookie=None,method='GET'):
    args=['curl','-sS','-A','Mozilla/5.0 (Linux; Android 14; Mobile) AppleWebKit/537.36 Chrome/156.0 Mobile Safari/537.36',
          '--connect-timeout','5','--max-time','23','-o','/dev/null','-D','-','-w','MAD4B_TOTAL=%{time_starttransfer}|%{http_code}\n']
    if cookie:args.extend(['-H','Cookie: '+cookie])
    if method=='HEAD':args.append('-I')
    args.append(url)
    r=command(args,26)
    if r.returncode:raise RuntimeError('HTTP_ERROR_'+str(r.returncode))
    heads=re.findall(r'(?im)^([^:\r\n]+):\s*([^\r\n]+)',r.stdout)
    headers={};vary=[]
    for k,v in heads:
        key=k.strip().lower()
        if key=='vary':vary.append(v.strip())
        else:headers[key]=v.strip()
    headers['vary']=', '.join(vary)
    meta=re.search(r'MAD4B_TOTAL=([0-9.]+)\|(\d+)',r.stdout)
    if not meta:raise RuntimeError('HTTP_NO_METRICS')
    return {'code':int(meta[2]),'ttfb_ms':round(float(meta[1])*1000),
      'cache':headers.get('x-wayfa-origin-cache',headers.get('x-mad4b-preset-cache','')),
      'cache_control':headers.get('cache-control',''),'vary':headers.get('vary','')}
def size(path):
    r=command(['du','-sB1',str(path)],timeout=18)
    if r.returncode:return None
    return int(r.stdout.split()[0])
def container_health(container):
    r=command(['docker','inspect',container,'--format','{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{end}}'],10)
    if r.returncode:return 'UNAVAILABLE'
    return r.stdout.strip()
def redis_read(container):
    script=r'''export REDISCLI_AUTH="$(awk '/^[[:space:]]*requirepass[[:space:]]+/ {print $2;exit}' /usr/local/etc/redis/redis.conf)";
printf "%s\n" "$(
 redis-cli --no-auth-warning INFO memory | grep -E '^(used_memory:|maxmemory:|evicted_keys:)' | tr -d '\r'
)"; redis-cli --no-auth-warning INFO stats | grep '^evicted_keys:' | tr -d '\r';
redis-cli --no-auth-warning INFO commandstats | grep '^cmdstat_flushdb:' | tr -d '\r';
redis-cli --no-auth-warning INFO server | grep '^uptime_in_seconds:' | tr -d '\r';
redis-cli --no-auth-warning INFO keyspace | grep '^db0:' | tr -d '\r';'''
    r=command(['docker','exec',container,'sh','-c',script],15)
    if r.returncode:raise RuntimeError('REDIS_READ_UNAVAILABLE')
    data=dict(re.findall(r'(?m)^([a-z_]+):(\d+)$',r.stdout))
    flush=re.search(r'^cmdstat_flushdb:calls=(\d+)',r.stdout,re.M)
    keys=re.search(r'^db0:keys=(\d+),expires=(\d+)',r.stdout,re.M)
    used=int(data.get('used_memory',0));maximum=int(data.get('maxmemory',0))
    if maximum<1:raise ValueError('INVALID_REDIS_MEMORY_LIMIT')
    return {'used_mb':round(used/1048576,1),'max_mb':round(maximum/1048576,1),
       'used_pct':round(100*used/maximum,1),'evicted_keys':int(data.get('evicted_keys',0)),
       'flushdb_calls':int(flush[1]) if flush else None,
       'uptime_sec':int(data.get('uptime_in_seconds',0)),
       'keys':int(keys[1]) if keys else None,'keys_with_ttl':int(keys[2]) if keys else None}
def opcache():
    result=command([sys.executable,str(FPM_PROBE)],timeout=35)
    if result.returncode:raise RuntimeError('FPM_OPCACHE_INTERNAL_READ_UNAVAILABLE')
    p=ROOT/'reports/cache-policy-reconcile-20261107/proofs/fpm-opcache-live.json'
    return json.loads(p.read_text())
def edge_map_digest(cfg):
    result=[]
    for label in ('mad4b_tracked_cookie_safe_v2','mad4b_tracking_public_path_v2','mad4b_effective_cookie_unsafe_v2'):
        pattern=r'(?ms)^map [^\n]* \$'+re.escape(label)+r' \{\n.*?^\}'
        blocks=re.findall(pattern,cfg)
        if len(blocks)!=1:return None
        result.extend(blocks)
    return hashlib.sha256('\n'.join(result).encode()).hexdigest()

def check(policy,site,spec,fpm,previous=None):
    issues=[];warnings=[]
    origin=spec['origin'];root=Path(spec['root'])
    mu=root/'wp-content/mu-plugins'
    conf=Path(spec['nginx_config'])
    if not root.is_dir() or not mu.is_dir() or not conf.is_file():
        raise RuntimeError('COMPANY_PATHS_UNAVAILABLE')
    services={k:container_health(spec[k+'_container']) for k in ['nginx','php','redis']}
    for service,status in services.items():
        if not (status.startswith('running|healthy') or status=='running|'):
            issues.append('CONTAINER_'+service.upper()+'_UNHEALTHY')
    cfg=conf.read_text()
    if 'max-age=1800, must-revalidate' not in cfg:issues.append('CSS_TTL_1800_MISSING')
    count_1800=cfg.count('fastcgi_cache_valid 200 1800s;')
    if count_1800 < (4 if site=='tours-dev' else 1):
        issues.append('FASTCGI_HTML_1800_MISSING')
    php_contract=policy['site_php_public_cache_readbacks'][site]
    php_file=mu/php_contract['filename']
    if not php_file.is_file() or php_contract['expected'] not in php_file.read_text():
        issues.append('PHP_PUBLIC_CACHE_POLICY_DRIFT')
    model=policy.get('cookie_decision_model',{})
    if model.get('contract')!='mad4b.public-cookie-fastcgi-decision.v2' or model.get('public_ttl_seconds')!=1800:
        issues.append('DESIRED_COOKIE_MODEL_V2_MISSING')
    if site not in model.get('sites',{}):issues.append('SITE_COOKIE_MODEL_V2_MISSING')
    if not all(x in cfg for x in ('mad4b_tracked_cookie_safe_v2',
         'mad4b_tracking_public_path_v2','mad4b_effective_cookie_unsafe_v2')):
        issues.append('TRACKING_EDGE_GATE_V2_MISSING')
    php_cookie=mu/'417-mad4b-public-cookie-contract.php'
    if not php_cookie.is_file() or 'safe_marketing_cookie' not in php_cookie.read_text():
        issues.append('TRACKING_PHP_GATE_V2_MISSING')
    if edge_map_digest(cfg)!=spec.get('edge_maps_sha256'):
        issues.append('EDGE_TRACKING_MAP_RELEASE_DRIFT')
    if site=='tours-dev':
        pinned_router=policy['runtime_dependency_preflight'].get('approved_router_sha256','')
        router=mu/'00-0-mad4b-runtime-router.php'
        if not router.is_file() or hashlib.sha256(router.read_bytes()).hexdigest()!=pinned_router:
            issues.append('PUBLIC_CONTROL_ROUTER_RELEASE_DRIFT')
        approved=policy['runtime_dependency_preflight']['approved_control_modules_sha256']
        for name,sha in approved.items():
            path=root/'wp-content/plugins/mad4b-platform/modules/compat'/name
            if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest()!=sha:
                issues.append('CONTROL_MODULE_RELEASE_DRIFT_'+name)
    if site=='wayfatours':
        if 'wayfa_analytics_cookie_unsafe' not in cfg:issues.append('STRICT_COOKIE_GATE_MISSING')
        if '"private, max-age=1800, must-revalidate"' not in cfg:issues.append('BROWSER_HTML_1800_MISSING')
    elif site=='tours-dev':
        if ('mad4b_browser_html_coherence' not in cfg
            or '"public, max-age=1800, must-revalidate"' not in cfg):
            issues.append('BROWSER_HTML_1800_MISSING')
        if 'company_perf_has_cookie' not in cfg or '_ga(?:_[A-Za-z0-9]+)?' not in cfg:issues.append('STRICT_COOKIE_GATE_MISSING')
    policies=['mad4b-tour-css-delivery.policy.json','mad4b-tour-css-segments.policy.json','mad4b-public-cookie-cache.policy.json','mad4b-backup-option-autoload.policy.json']
    policy_status={}
    for filename in policies:
        try:
            obj=json.loads((mu/filename).read_text())
            policy_status[filename]=obj.get('mode')
            if obj.get('mode')!='active' or obj.get('site')!='@current':issues.append('POLICY_NOT_ACTIVE_'+filename)
        except Exception:issues.append('POLICY_MISSING_'+filename)
    versions={}
    for name,sha in policy['modules_sha256'].items():
        p=mu/name
        if not p.is_file():issues.append('MODULE_MISSING_'+name);continue
        actual=hashlib.sha256(p.read_bytes()).hexdigest()
        versions[name]='MATCH' if actual==sha else 'DRIFT'
        if actual!=sha:issues.append('MODULE_DRIFT_'+name)
    for name,expected_sha in spec.get('modules_sha256',{}).items():
        module_path=mu/name
        if not module_path.is_file():
            issues.append('SITE_MODULE_MISSING_'+name)
        else:
            actual_sha=hashlib.sha256(module_path.read_bytes()).hexdigest()
            if actual_sha!=expected_sha:issues.append('SITE_MODULE_DRIFT_'+name)
            if 'wp_cache_flush();' in module_path.read_text():
                issues.append('FULL_REDIS_FLUSH_REINTRODUCED_'+name)
    f=fpm.get(site,{})
    free=(f.get('memory_usage') or {}).get('free_memory',0)
    total=free+(f.get('memory_usage') or {}).get('used_memory',0)+(f.get('memory_usage') or {}).get('wasted_memory',0)
    free_pct=round(100*free/total,1) if total else None
    ff=(f.get('opcache_statistics') or {})
    if free_pct is None:
        if site in ('tours-dev','wayfatours'):issues.append('OPCACHE_UNOBSERVED')
        else:warnings.append('NEW_COMPANY_OPCACHE_PROBE_PENDING')
    elif free_pct<policy['fpm_opcache_min_free_percent']:warnings.append('OPCACHE_LOW_FREE_MEMORY')
    op={'free_pct':free_pct,'cached_scripts':ff.get('num_cached_scripts'),'misses_cumulative':ff.get('misses'),
      'oom_restarts':ff.get('oom_restarts'),'free_mb':round(free/1048576,1)}
    redis=redis_read(spec['redis_container'])
    if redis['used_pct']>80:warnings.append('REDIS_MEMORY_GT80')
    prev=(previous or {}).get('redis',{})
    prev_count=prev.get('flushdb_calls')
    prev_uptime=prev.get('uptime_sec')
    if isinstance(prev_count,int) and isinstance(prev_uptime,int) and redis['uptime_sec']>0:
        if redis['uptime_sec']<prev_uptime:
            warnings.append('REDIS_RESTART_SINCE_PREVIOUS_CHECK')
            redis['flushdb_delta']=None
        else:
            delta=max(0,redis['flushdb_calls']-prev_count) if redis['flushdb_calls'] is not None else None
            redis['flushdb_delta']=delta
            if delta and delta>0:warnings.append('REDIS_FLUSHDB_INCREASED')
    else:redis['flushdb_delta']=None
    warm_path=Path('/var/lib/mad4b-cache-warm')/(site+'.json')
    warm=json.loads(warm_path.read_text()) if warm_path.exists() else {}
    service=command(['systemctl','show','mad4b-public-object-cache-warm@'+site+'.service',
                     '--property=Result','--value'],8)
    admitted_result=service.stdout.strip() if service.returncode==0 else 'unknown'
    warm_state=('READY_CANDIDATE' if warm.get('complete') is True
                else ('DEFERRED_BY_ADMISSION' if admitted_result=='exec-condition' else 'NEEDS_WARMUP'))
    warm_info={'complete_claimed':warm.get('complete') is True,'verified_routes':len(warm.get('completed',[])),
      'expected_routes':warm.get('route_count'),'current_state':warm_state,
      'last_service_result':admitted_result,
      'sentinel_validation':'BY_SEPARATE_AUTHORIZED_FLEET_OBSERVER'}
    if not warm_info['complete_claimed']:warnings.append('REDIS_WARM_IN_PROGRESS')
    routes={'anonymous':http(origin+spec['tour_path']),
      'safe_analytics':http(origin+spec['tour_path'],'_ga=GA1.1.111.222'),
      'private_favourite':http(origin+spec['tour_path'],'je_data_store_favourites=1'),
      'mixed_tracking':http(origin+spec['tour_path'],'_ga=GA1.1.111.222; sbjs_current=1'),
      'private_cart':http(origin+spec['tour_path'],'woocommerce_items_in_cart=1'),
      'private_auth':http(origin+spec['tour_path'],'wordpress_logged_in_fake=test'),
      'locale_mismatch':http(origin+spec['tour_path'],'sbjs_session=sample; wp-wpml_current_language=es')}
    for kind,r in routes.items():
        if r['code']!=200:issues.append('HTTP_'+kind.upper()+'_NOT_200')
    if routes['private_favourite']['cache'] not in ['BYPASS','MISS','']:
        issues.append('PRIVATE_FAVOURITE_CACHE_HIT')
    if routes['mixed_tracking']['cache'] not in ['HIT','MISS','STALE','EXPIRED','UPDATING']:
        issues.append('APPROVED_TRACKING_NOT_PUBLIC_CACHE')
    if 'max-age=1800' not in routes['mixed_tracking']['cache_control'] or 'no-store' in routes['mixed_tracking']['cache_control']:
        issues.append('APPROVED_TRACKING_TTL_DRIFT')
    for private in ['private_favourite','private_cart','private_auth','locale_mismatch']:
        if routes[private]['cache'] not in ('BYPASS',''):
            issues.append('PRIVATE_CONTEXT_SHARED_CACHE_'+private.upper())
        if 'no-store' not in routes[private]['cache_control']:
            issues.append('PRIVATE_CONTEXT_BROWSER_STORE_'+private.upper())
    if routes['anonymous']['cache'] in ['HIT','STALE','MISS','EXPIRED']:
        vary=routes['anonymous']['vary'].lower()
        for required in policy['browser_html_required_vary']:
            if required.lower() not in vary:issues.append('BROWSER_CACHE_VARY_MISSING_'+required.upper())
        cc=routes['anonymous']['cache_control']
        if 'max-age=1800' not in cc or 'no-cache' in cc or 'no-store' in cc:
            issues.append('BROWSER_HTML_1800_MISMATCH')
    if routes['safe_analytics']['cache'] == 'BYPASS':warnings.append('PURE_ANALYTICS_CACHE_BYPASS')
    # Safe-by-design personal-cookie BYPASS can still be a slow returning-user
    # experience. Report it separately; never fix by sharing private HTML.
    return_cookie_paths=['private_favourite','private_cart','private_auth']
    uncached=[name for name in return_cookie_paths
              if routes[name]['cache'] in ('BYPASS','MISS')
              and routes[name]['ttfb_ms']>1000]
    performance_state='RETURN_VISITOR_HTML_SLOW' if uncached else 'WITHIN_CACHE_GOAL'
    ux={'state':performance_state,'slow_return_cookie_profiles':uncached,
        'same_site_cookie_qa':'/opt/mad4b-company-provisioning/reports/cache-policy-reconcile-20261107/proofs/real-anonymous-return.json',
        'private_html_share_allowed':False}
    # Resource policy readback — CSS versioned/unversioned have 1800s browser TTL.
    elementor=http(origin+spec.get('css_probe_path','/wp-content/uploads/elementor/css/post-24780.css'),method='HEAD')
    if elementor['code']!=200 or 'max-age=1800' not in elementor['cache_control']:
        issues.append('ELEMENTOR_CSS_TTL_DRIFT')
    assetdir=root/'wp-content/litespeed'
    asset_bytes=size(assetdir)
    if asset_bytes is not None and asset_bytes>=5*1024**3:warnings.append('LITESPEED_ASSETS_GT5G')
    return {'site':site,'origin':origin,'services':services,'policies':policy_status,
      'source_module_parity':versions,'redis':redis,'opcache':op,'warm':warm_info,
      'html':routes,'ux_performance':ux,'performance_state':performance_state,
      'elementor_css':elementor,'litespeed_disk_gb':round(asset_bytes/1024**3,2) if asset_bytes is not None else None,
      'issues':sorted(set(issues)),'warnings':sorted(set(warnings)),
      'status':'DRIFT' if issues else ('DEGRADED' if warnings else 'PASS')}
def atomic(path,doc):
    path.parent.mkdir(parents=True,exist_ok=True)
    tmp=path.with_name(path.name+'.tmp.'+str(os.getpid()))
    tmp.write_text(json.dumps(doc,indent=2,ensure_ascii=False)+'\n')
    os.chmod(tmp,0o600)
    os.replace(tmp,path)
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--strict',action='store_true')
    parser.add_argument('--policy',default=str(POL))
    parser.add_argument('--report',default=str(REPORT))
    parser.add_argument('--history',default=str(HISTORY))
    args=parser.parse_args()
    policy_path=Path(args.policy);report_path=Path(args.report);history_path=Path(args.history)
    pol=json.loads(policy_path.read_text())
    assert pol['contract']=='mad4b.company-cache-coherence-desired-state.v1'
    assert pol.get('version')=='2.3.0'
    assert pol.get('release_contract')=='mad4b.runtime-policy-release.v2'
    fpm=opcache()
    previous={}
    if report_path.is_file():
        try:previous=json.loads(report_path.read_text()).get('sites',{})
        except (OSError,ValueError):pass
    sites={}
    for name,spec in pol['company_sites'].items():
        try:sites[name]=check(pol,name,spec,fpm,previous.get(name,{}))
        except Exception as e:
            sites[name]={'site':name,'status':'OBSERVATION_UNAVAILABLE','issues':['OBSERVER_'+type(e).__name__],'warnings':[]}
    # Original ordered control-plane modules must be valid independently
    # before any release is called accepted. Execution grants are untouched.
    tool=Path(pol['runtime_dependency_preflight']['tool'])
    runtime={'verdict':'UNAVAILABLE','issues':['PREFLIGHT_NOT_AVAILABLE']}
    if tool.is_file():
        run=command([sys.executable,str(tool),'--strict'],timeout=20)
        try:runtime=json.loads(run.stdout)
        except (json.JSONDecodeError,ValueError):pass
        if run.returncode:runtime['verdict']='FAIL'
    pinned=pol['runtime_dependency_preflight']['approved_map_sha256']
    if runtime.get('map_sha256')!=pinned:
        runtime['verdict']='FAIL'
        runtime['issues']=sorted(set(runtime.get('issues',[])+['RELEASE_MAP_SHA256_DRIFT']))
    if runtime.get('verdict')!='PASS' and 'tours-dev' in sites:
        sites['tours-dev']['issues']=sorted(set(sites['tours-dev'].get('issues',[])+['RUNTIME_DEPENDENCY_PREFLIGHT_FAILED']))
        sites['tours-dev']['status']='DRIFT'
    enrolled=[x.stem for x in Path('/etc/mad4b/cache-warm').glob('*.json')]
    new=sorted(set(enrolled)-set(sites))
    for name in new:sites[name]={'site':name,'status':'NEEDS_ENROLLMENT','issues':['CACHE_BEHAVIOR_POLICY_MISSING'],'warnings':[]}
    now=dt.datetime.now(dt.timezone.utc).isoformat()
    counts={s:sum(v['status']==s for v in sites.values()) for s in ['PASS','DEGRADED','DRIFT','OBSERVATION_UNAVAILABLE','NEEDS_ENROLLMENT']}
    doc={'contract':'mad4b.company-cache-coherence-reconcile.v2','observed_utc':now,'desired_state':str(policy_path),
      'audit_only':True,'sites':sites,'summary':counts,
      'runtime_preflight':runtime,'release_contract':pol['release_contract'],'events':[]}
    for name,row in sites.items():
        older=previous.get(name,{})
        if (older.get('status')!=row.get('status') or older.get('issues')!=row.get('issues')
            or older.get('performance_state')!=row.get('performance_state')):
            doc['events'].append({'site':name,'before':older.get('status','UNKNOWN'),'after':row['status'],
                 'issues':row.get('issues',[]),
                 'performance_before':older.get('performance_state','UNKNOWN'),
                 'performance_after':row.get('performance_state','UNKNOWN')})
    atomic(report_path,doc)
    if doc['events']:
        with history_path.open('a') as f:
            for event in doc['events']:f.write(json.dumps({'observed_utc':now,**event},separators=(',',':'))+'\n')
    print('CACHE_COHERENCE',json.dumps({'time':now,'summary':counts,'sites':{
      n:{'status':v['status'],'issues':v.get('issues'),'warnings':v.get('warnings'),
         'performance_state':v.get('performance_state'),
         'opcache_free_pct':v.get('opcache',{}).get('free_pct')}
      for n,v in sites.items()}},ensure_ascii=False))
    return 2 if args.strict and any(row['status'] not in ['PASS','DEGRADED'] for row in sites.values()) else 0
if __name__=='__main__':sys.exit(main())

#!/usr/bin/env python3
"""Original site-owned family-aware *source* preparation. Never publishes CSS.

Precompiles exact route-specific CSS/market assets in bounded family cohorts;
no Chromium, shared route certificates, WP mutations, or public cache writes.
"""
import collections,concurrent.futures,hashlib,importlib.util,json,os,re,shutil,sys,time
from pathlib import Path
HERE=Path(__file__).resolve().parent
ROOT=Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1')
SITES={'source':('tours.dev.mad4b.com',
    '/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content'),
       'wayfa':('wayfatours.com',
    '/opt/wayfa-rebuild-20261102/site-copy/wp-content')}
spec=importlib.util.spec_from_file_location('original_family_css_source',HERE/'public-css-manifest-observer.py')
observer=importlib.util.module_from_spec(spec);spec.loader.exec_module(observer)
spec=importlib.util.spec_from_file_location('original_family_source_queue',HERE/'public-route-queue.py')
queue=importlib.util.module_from_spec(spec);spec.loader.exec_module(queue)
CONTRACT='mad4b.css.v2.family-prepared-source.v1'
MAX_PREPARE=24
MAX_PARALLEL=3
MAX_RETAIN=96
TTL=3600

def family_order(site,catalog):
    rows=queue.safe_catalog(catalog,site)
    groups=collections.defaultdict(list)
    for r in rows:
        if queue.independently_certified_home(r):continue
        profile=r.get('source_profile');graph=r.get('source_graph')
        if (r.get('source_graph_status')!='configured' or
            not isinstance(profile,str) or not re.fullmatch(r'[A-Za-z0-9:/_-]{1,145}',profile) or
            not isinstance(graph,str) or not re.fullmatch('[a-f0-9]{64}',graph)):
            continue
        groups[(r['language'],profile,r['type'])].append(r)
    ranks={'tours-and-activities':0,'destinations':1,'properties':2,'page':3,'post':4}
    keys=sorted(groups,key=lambda k:(ranks.get(k[2],5),0 if k[0]=='en' else 1,k[0],k[1]))
    for key in keys:groups[key].sort(key=lambda r:r['path'])
    result=[]
    while keys:
        nxt=[]
        for k in keys:
            result.append(groups[k].pop(0))
            if groups[k]:nxt.append(k)
        keys=nxt
    return result

def selected_cursor(site):
    file=ROOT/site/'bulk-publish-attempts.json'
    if not file.exists():return 0
    if (file.is_symlink() or file.stat().st_size>250000
        or file.stat().st_uid!=0 or file.stat().st_mode&0o022):
        raise ValueError('untrusted-family-publisher-cursor')
    data=json.loads(file.read_text())
    cursor=data.get('next_index',0)
    if (data.get('site')!=site or
        data.get('contract')!='mad4b.css.v2.bulk.publish-attempts.v1'
        or type(cursor)is not int or not 0<=cursor<1<<24):
        raise ValueError('untrusted-family-publisher-source-ledger')
    return cursor

def read_metadata(directory):
    file=directory/'prepared.json'
    if (directory.is_symlink() or not directory.is_dir()
        or file.is_symlink() or not file.is_file()
        or file.stat().st_size>8192 or file.stat().st_uid!=0
        or file.stat().st_mode&0o022):
        raise ValueError('untrusted-first-party-prepared-directory')
    return json.loads(file.read_text())

def matched_meta(row,site,route,state,now):
    return (row.get('contract')==CONTRACT
      and row.get('site')==site and row.get('host')==SITES[site][0]
      and row.get('route_key')==route['route_key']
      and row.get('path')==route['path']
      and row.get('language')==route['language']
      and row.get('source_profile')==route['source_profile']
      and row.get('source_graph')==route['source_graph']
      and row.get('route_revision')==route['revision']
      and row.get('css_epoch')==state['css_epoch']
      and row.get('runtime_sha')==state['runtime_sha']
      and row.get('consumer_v2_sha')==state['consumer_v2_sha']
      and row.get('compiler_sha')==hashlib.sha256(
         (HERE/'public-css-dependency-manifest.py').read_bytes()).hexdigest()
      and type(row.get('prepared_at'))is int
      and 0<=now-row['prepared_at']<=TTL)

def prepare_one(site,route,state,wp,base):
    target=base/route['route_key']
    try:
        if matched_meta(read_metadata(target),site,route,state,int(time.time())):
            return 'reused',route['route_key']
    except (OSError,ValueError,TypeError,KeyError,UnicodeError,json.JSONDecodeError):
        pass
    report,manifest,compiled,_=observer.observe(
        {'contract':queue.CATALOG,'host':SITES[site][0],
         'routes':[route]},route,wp,
        expected_host=SITES[site][0],include_html=True)
    tmp=base/('.'+route['route_key']+'.'+str(os.getpid())+'-'+str(time.monotonic_ns()))
    tmp.mkdir(mode=0o700)
    try:
        observer.store(tmp,report,manifest,compiled)
        metadata={
          'contract':CONTRACT,'site':site,'host':SITES[site][0],
          'route_key':route['route_key'],'path':route['path'],
          'language':route['language'],
          'source_profile':route['source_profile'],'source_graph':route['source_graph'],
          'route_revision':route['revision'],
          'css_epoch':state['css_epoch'],
          'runtime_sha':state['runtime_sha'],
          'consumer_v2_sha':state['consumer_v2_sha'],
          'compiler_sha':hashlib.sha256(
             (HERE/'public-css-dependency-manifest.py').read_bytes()).hexdigest(),
          'market_country':report['market_country'],'currency':report['currency'],
          'manifest_sha256':manifest['manifest_sha256'],
          'compiled_sha256':compiled['compiled_sha256'],
          'compiled_bytes':compiled['compiled_bytes'],
          'prepared_at':int(time.time())}
        meta=tmp/'prepared.json'
        meta.write_text(json.dumps(metadata,sort_keys=True)+'\n')
        os.chmod(meta,0o600)
        if target.exists():
            if target.is_symlink() or not target.is_dir():
                raise ValueError('untrusted-previous-prepared-directory')
            backup=base/('.old-'+route['route_key']+'-'+str(os.getpid()))
            os.replace(target,backup)
            try:os.replace(tmp,target)
            except BaseException:os.replace(backup,target);raise
            shutil.rmtree(backup)
        else:os.replace(tmp,target)
        return 'prepared',route['route_key']
    finally:
        if tmp.exists():shutil.rmtree(tmp)

def prepare(site,catalog,state,wp,*,budget=MAX_PREPARE):
    if site not in SITES or catalog.get('host')!=SITES[site][0] or (
        Path(wp)!=Path(SITES[site][1])) or state.get('site_host')!=SITES[site][0]:
        raise ValueError('foreign-company-css-family-preparation')
    if (state.get('critical_css')!='on' or
        not re.fullmatch('[a-f0-9]{64}',str(state.get('runtime_sha',''))) or
        not re.fullmatch('[a-f0-9]{64}',str(state.get('consumer_v2_sha',''))) or
        type(state.get('css_epoch'))is not int):
        raise ValueError('untrusted-source-CSS-runtime-family-state')
    if type(budget)is not int or not 1<=budget<=MAX_PREPARE:
        raise ValueError('unbounded-CSS-family-preparation')
    base=ROOT/site/'family-prepared-v1'
    if base.is_symlink():raise ValueError('untrusted-family-prep-root')
    base.mkdir(mode=0o700,exist_ok=True)
    if base.stat().st_uid!=0 or base.stat().st_mode&0o077:
        raise ValueError('unsafe-family-prep-root-permissions')
    ordered=family_order(site,catalog)
    if not ordered:return {'attempted':0,'prepared':0,'reused':0,'deferred':0,'source_profiles':0}
    cursor=selected_cursor(site)%len(ordered)
    current=[ordered[(cursor+i)%len(ordered)] for i in range(min(budget,len(ordered)))]
    results=[];errors=[]
    # Bounded browserless HTML/CSS compilation; no second WordPress CLI,
    # no Chrome and no cross-route/currency certificate promotion.
    with concurrent.futures.ThreadPoolExecutor(max_workers=MAX_PARALLEL) as pool:
        futures=[pool.submit(prepare_one,site,r,state,wp,base) for r in current]
        for future in futures:
            try:results.append(future.result())
            except (OSError,ValueError,KeyError,TypeError,UnicodeError) as exc:
                errors.append(type(exc).__name__+':'+str(exc)[:110])
    # Keep at most 96 bounded family source samples per company; all records
    # outside the in-progress publisher cursor will be revalidated at issue.
    saved=[x for x in base.iterdir() if x.is_dir() and
           not x.is_symlink() and re.fullmatch('[a-f0-9]{64}',x.name)]
    for old in sorted(saved,key=lambda d:d.stat().st_mtime)[:max(0,len(saved)-MAX_RETAIN)]:
        shutil.rmtree(old)
    return {'attempted':len(current),'prepared':sum(x[0]=='prepared' for x in results),
       'reused':sum(x[0]=='reused' for x in results),
       'deferred':len(errors),
       'source_profiles':len({(r['language'],r['source_profile']) for r in current}),
       'route_scope':'source_observation_only','public_certificates_issued':0,
       'errors':errors[:3]}

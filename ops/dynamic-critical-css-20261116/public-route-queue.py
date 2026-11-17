#!/usr/bin/env python3
"""Incremental source-backed public Critical CSS queue with persistent private ledger.

Routes come only from the authenticated WPML/WordPress catalog. Never from
a user query or a sitemap. Select one eligible public route per bounded
worker cycle, with fair failed-item cooldown and per-object dependency keys.
Never represent a queued route as a public critical CSS success.
"""
import collections,hashlib,json,pathlib,re,sys,time,tempfile,os
from pathlib import Path
CATALOG='mad4b.public-critical-css.routes.v1'
QUEUE='mad4b.public-critical-css.queue.v1'
TYPES={'page','post','tours-and-activities','properties',
       'destinations','tour-types','property-types','tour-categories',
       'category','archive:tours-and-activities','archive:properties'}
HOLD_REASONS={'market_context_unavailable','capture_error',
              'source_html_drift','country_or_currency_context_drift',
              'unstable_css_source','unstable_elementor_graph',
              'unstable_market_currency','candidate_incomplete'}
EXCLUDED=['checkout','cart','my-account','order-pay','order-received','carrito',
          'carrello','panier','warenkorb','kasse','paiement','pagamento',
          'mein-konto','mio-account','account','password','login','checkout']
def read(file,limit=3000000):
    p=Path(file)
    if p.is_symlink() or not p.is_file() or p.stat().st_size>limit:raise ValueError('untrusted-input-file')
    return json.loads(p.read_text())
def safe_catalog(cat,site):
    host={'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}.get(site)
    if not host or cat.get('contract')!=CATALOG or cat.get('host')!=host:raise ValueError('foreign-catalog')
    langs=cat.get('wpml_languages',[])
    if not isinstance(langs,list) or not langs or len(langs)>20 or cat.get('default_language') not in langs:raise ValueError('bad-WPML-registry')
    rows=cat.get('routes',[])
    if not isinstance(rows,list) or len(rows)>25000:raise ValueError('invalid-route-registry')
    keys=set()
    for r in rows:
        p=r.get('path','')
        if (not isinstance(p,str) or not re.fullmatch(r'/[A-Za-z0-9/_%.\-]{0,349}',p)
            or r.get('type') not in TYPES or r.get('language') not in langs
            or not isinstance(r.get('id'),int) or r['id']<0
            or not re.fullmatch(r'[a-f0-9]{64}',r.get('route_key',''))
            or p.startswith('/wp-') or '//' in p or '..' in p
            or any(b in p.lower().split('/') for b in EXCLUDED)):
            raise ValueError('untrusted-private-or-inconsistent-route')
        k=r['language']+'|'+p
        if k in keys:raise ValueError('duplicated-WPML-path')
        keys.add(k)
    return rows
def read_ledger(p,site):
    if not p.exists():return {'contract':QUEUE,'site':site,'routes':{}}
    if p.is_symlink() or p.stat().st_size>2000000:raise ValueError('invalid-private-ledger')
    x=json.loads(p.read_text())
    if x.get('contract')!=QUEUE or x.get('site')!=site or not isinstance(x.get('routes'),dict):raise ValueError('foreign-ledger')
    return x
def source_key(cat,r,state,depends_on_listings=False):
    deps=[cat['host'],r['language'],r['path'],r['route_key'],
          str(r.get('revision',0)),str(cat.get('global_generation',0)),
          str(cat.get('listing_generation',0)) if (r['type'] not in ['page','post'] or depends_on_listings) else '',
          str(state.get('css_epoch')),str(state.get('native_breakpoint')),
          str(state.get('critical_css')),str(state.get('runtime_sha',''))]
    return hashlib.sha256('|'.join(deps).encode()).hexdigest()
def order_key(r):
    typ=r['type']
    # Simple static pages establish safe rollout first. Every other public
    # type remains present in catalog and can be served only with its full
    # market and interaction proof; no automatic inheritance from Home.
    tier={'page':0,'post':1,'tours-and-activities':2,'properties':3,
          'destinations':4,'tour-types':5,'property-types':6,
          'category':7,'tour-categories':8,
          'archive:tours-and-activities':9,'archive:properties':10}[typ]
    return (tier,0 if r['language']=='en' else 1,r['path'])
def choose(cat,state,ledger,now):
    rows=safe_catalog(cat,ledger['site'])
    if (state.get('site_host')!=cat['host'] or state.get('critical_css')!='on'
        or not isinstance(state.get('css_epoch'),int)):raise ValueError('WP-source-state-invalid')
    # Keep changed pages and never-before-tested routes moving forward even
    # when a frequently regenerated global CSS epoch invalidates everything.
    # Independent per-company state; no same-site Chromium concurrency.
    groups=collections.Counter()
    for r in rows:
        if r['route_key'] in ledger['routes']:
            groups[(r['type'],r['language'])]+=1
    unseen=[];changed=[];retry=[]
    for r in rows:
        if r['path'] in ['/',('/'+r['language']+'/')]:continue
        record=ledger['routes'].get(r['route_key'],{})
        if not isinstance(record,dict):raise ValueError('invalid-queue-state-record')
        key=source_key(cat,r,state,bool(record.get('depends_on_listings')))
        if not record:
            unseen.append((groups[(r['type'],r['language'])],order_key(r),r,key))
            continue
        unchanged=record.get('source_key')==key
        if unchanged:
            if record.get('status') in ['certified','unsupported','native_preferred']:continue
            if now<int(record.get('retry_after',0)):continue
            retry.append((int(record.get('attempted_at',0)),order_key(r),r,key))
        else:
            changed.append((int(record.get('attempted_at',0)),order_key(r),r,key))
    # 1/4 slots to changed or cooldown-complete previously measured
    # routes; 3/4 to never-before-tested language/type coverage, when
    # both exist. Preserve negative-QA independent recheck opportunities
    # while preventing a frequently regenerated global CSS epoch from
    # monopolizing all browser time indefinitely.
    slot=int(ledger.get('scheduler_sequence',0))
    maintenance=changed+retry
    if unseen and (not maintenance or slot%4!=0):
        unseen.sort(key=lambda v:(v[0],v[1]))
        return unseen[0][2],unseen[0][3]
    if maintenance:
        maintenance.sort(key=lambda v:(v[0],v[1]))
        return maintenance[0][2],maintenance[0][3]
    if unseen:
        unseen.sort(key=lambda v:(v[0],v[1]))
        return unseen[0][2],unseen[0][3]
    return None,None
def atom(path,data):
    path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    fd,name=tempfile.mkstemp(prefix='.route-ledger-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as out:
            os.fchmod(out.fileno(),0o600)
            json.dump(data,out,indent=2,sort_keys=True);out.write('\n')
            out.flush();os.fsync(out.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)
def main():
    mode,site,catfile,statefile,ledgerfile,jobfile,*rest=sys.argv[1:]
    catalog=read(catfile);state=read(statefile)
    rows=safe_catalog(catalog,site)
    ledger=read_ledger(Path(ledgerfile),site)
    now=int(time.time())
    if mode=='select':
        route,key=choose(catalog,state,ledger,now)
        result={'contract':'mad4b.public-critical-css.job.v1','site':site,
                'catalog_generation':catalog.get('generation',0),
                'route':route,'source_key':key,'selected_at':now}
        atom(Path(jobfile),result)
        print('PUBLIC_CCSS_QUEUE',json.dumps({'discovered':len(rows),'pending':route is not None,
            'selected':{'lang':route['language'],'type':route['type'],'path':route['path']} if route else None},sort_keys=True))
        return
    if mode=='record':
        job=read(jobfile)
        if job.get('contract')!='mad4b.public-critical-css.job.v1' or job.get('site')!=site:raise ValueError('wrong-job')
        route,key=job.get('route'),job.get('source_key')
        if not isinstance(route,dict) or not isinstance(key,str):raise ValueError('empty-job')
        actual=next((r for r in rows if r['route_key']==route.get('route_key')),None)
        previous=ledger['routes'].get(route['route_key'],{})
        if actual!=route or source_key(catalog,route,state,bool(previous.get('depends_on_listings')))!=key:
            raise ValueError('stale-catalog-revision')
        if not rest or rest[0] not in ['certified','hold','unsupported','native_candidate']:raise ValueError('invalid-result')
        status=rest[0]
        # First-party capture preflight may carry a bounded machine-readable
        # HOLD code, not arbitrary log text, headers or a client-supplied
        # reason. Never promote a negative source proof to a certificate.
        hold_reason=None
        if status=='hold' and len(rest)>1:
            if len(rest)!=2 or rest[1] not in HOLD_REASONS:
                raise ValueError('untrusted-hold-reason')
            hold_reason=rest[1]
        record=ledger['routes'].get(route['route_key'],{})
        # Native CSS is terminal only after TWO separate, fully measured
        # non-benefit results under the SAME source/locale/runtime/
        # Elementor/listing dependency key. An isolated noisy measurement
        # is merely native_candidate and is rechecked after cooldown.
        if status=='native_candidate':
            prior=record.get('status')=='native_candidate'
            independent=int(job.get('selected_at',0))>int(record.get('attempted_at',0))
            after_cooldown=now>=int(record.get('retry_after',0))
            if prior and record.get('source_key')==key and independent and after_cooldown:
                status='native_preferred'
        depends=bool(record.get('depends_on_listings',False))
        if status in ('certified','native_preferred','native_candidate'):
            if len(rest)<2 or rest[1] not in ['listing','no-listing']:
                raise ValueError('certification-requires-explicit-dependency-closure')
            depends=rest[1]=='listing'
            key=source_key(catalog,route,state,depends)
        failures=0 if status in ('certified','native_preferred') else min(12,int(record.get('failures',0))+1)
        # Category unable to pass market security should not monopolize the
        # next timer; but a later route revision triggers immediate retry.
        delay=0 if status in ('certified','native_preferred') else min(86400,1800*2**min(6,failures-1))
        ledger['routes'][route['route_key']]={'source_key':key,'status':status,'failures':failures,
            'depends_on_listings':depends,'attempted_at':now,'retry_after':now+delay,
            **({'hold_reason':hold_reason} if hold_reason else {})}
        ledger['scheduler_sequence']=(int(ledger.get('scheduler_sequence',0))+1)%4
        if len(ledger['routes'])>30000:raise ValueError('ledger-memory-cap')
        atom(Path(ledgerfile),ledger)
        print('PUBLIC_CCSS_QUEUE_RECORDED',route['language'],route['path'],status,failures)
        return
    raise ValueError('unsupported-queue-operation')
if __name__=='__main__':
    try:main()
    except (KeyError,ValueError,TypeError,OSError,IndexError,json.JSONDecodeError) as e:
        print('HOLD_PUBLIC_CCSS_QUEUE',str(e),file=sys.stderr);sys.exit(4)

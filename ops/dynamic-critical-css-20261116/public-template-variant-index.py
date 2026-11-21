#!/usr/bin/env python3
"""Original public template variant index: low-cost, observation only.

This is not CSS certification, browser QA, or permission to reuse a sibling's
Critical CSS. It provides the WordPress source-family scheduler with the real
Elementor DOM document fingerprints it otherwise learns only after Chromium.
Only authenticated WordPress catalog routes, first-party anonymous HTTP 200,
server-resolved currency/market, and matching WP object body class are admitted.
"""
import fcntl,hashlib,html,importlib.util,json,pathlib,re,time,urllib.error
from pathlib import Path

BASE=Path(__file__).resolve().parent
def load(name,filename):
    spec=importlib.util.spec_from_file_location(name,BASE/filename)
    obj=importlib.util.module_from_spec(spec);spec.loader.exec_module(obj)
    return obj
queue=load('mad4b_template_index_queue','public-route-queue.py')
drift=load('mad4b_template_index_drift','public-route-live-css-drift.py')
ordered=load('mad4b_template_index_native_ordered_css','public-css-dependency-manifest.py')
origin=load('mad4b_template_index_uncached_wordpress_source','public-css-manifest-observer.py')

CONTRACT='mad4b.public-css.template-variant-index.v1'
HOSTS={'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}
ROOTS={'source':'/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content',
       'wayfa':'/opt/wayfa-rebuild-20261102/site-copy/wp-content'}
DOC_RE=re.compile(r'(?<![a-z0-9_-])elementor-(\d+)\b',re.I)
BODY_RE=re.compile(r'<body\b[^>]*\bclass=["\x27]([^"\x27]+)["\x27]',re.I)
H1_RE=re.compile(r'<h1\b[^>]*>(.{0,1200}?)</h1>',re.I|re.S)
IMG_RE=re.compile(r'<img\b[^>]*\bsrc=["\x27]([^"\x27]{1,500})["\x27]',re.I)
TAG_RE=re.compile(r'<[^>]*>')
SHA_RE=re.compile(r'[a-f0-9]{64}\Z')
MAX_OBSERVATIONS=5000
MAX_AGE_S=4*86400  # Enough scan cycles for 1,022 public routes at 8 / 30 minutes
HTTP_404_HOLD_S=3600  # Only verified fresh origin 404, never a CSS qualification

def digest(value):
    return hashlib.sha256(value.encode('utf8')).hexdigest()

def refresh_file(site,cat,state,ledger,root,families,path,*,budget=4):
    """Single-writer bounded observations shared by worker & reporting timer.

    A busy lock skips optional observation; native policy stays active and
    the last first-party index remains readable. No UA, QA or visitor data
    can grant policy activation.
    """
    if site not in HOSTS:raise ValueError('unknown-company-variant-writer')
    lockfile=Path('/run/lock/mad4b-public-template-variant-'+site+'.lock')
    with lockfile.open('a+') as fd:
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        try:index=read(path,site)
        except (OSError,ValueError,TypeError,KeyError):
            index=empty(site)
        report=families.build(site,cat,state,ledger,root,variant_index=index)
        updated=refresh(site,cat,state,ledger,root,report,index,budget=budget)
        families.queue.atom(path,updated)
        return updated

def empty(site):
    if site not in HOSTS:raise ValueError('unknown-public-company')
    return {'contract':CONTRACT,'site':site,'host':HOSTS[site],
            'cursor':0,'observations':{},'source_http_holds':{}}

def read(path,site):
    if not path.exists():return empty(site)
    if path.is_symlink() or path.stat().st_size>2200000:
        raise ValueError('unsafe-private-template-index')
    data=json.loads(path.read_text())
    if (data.get('contract')!=CONTRACT or data.get('site')!=site
        or data.get('host')!=HOSTS[site] or not isinstance(data.get('observations'),dict)
        or len(data['observations'])>MAX_OBSERVATIONS
        or not isinstance(data.get('source_http_holds',{}),dict)
        or len(data.get('source_http_holds',{}))>MAX_OBSERVATIONS
        or not isinstance(data.get('cursor'),int) or data['cursor']<0):
        raise ValueError('untrusted-template-index')
    return data

def exact_source_key(cat,route,state,ledger):
    rec=ledger.get('routes',{}).get(route['route_key'],{})
    return queue.source_key(cat,route,state,bool(rec.get('depends_on_listings')))

def current_observation(data,cat,route,state,ledger,now=None):
    if not isinstance(data,dict) or data.get('contract')!=CONTRACT or data.get('host')!=cat.get('host'):
        return None
    rec=data.get('observations',{}).get(route['route_key'])
    if not isinstance(rec,dict):return None
    if (rec.get('source_key')!=exact_source_key(cat,route,state,ledger)
        or rec.get('path')!=route['path'] or rec.get('language')!=route['language']
        or rec.get('object_id')!=route['id'] or rec.get('type')!=route['type']):
        return None
    for field in ('doc_sha','css_sha','first_image_sha'):
        if not isinstance(rec.get(field),str) or not SHA_RE.fullmatch(rec[field]):return None
    if not re.fullmatch('[A-Z]{3}',str(rec.get('currency',''))):return None
    if not re.fullmatch('(?:base|static|[A-Z]{2})',str(rec.get('market',''))):return None
    if not isinstance(rec.get('observed_at'),int):return None
    if now is not None and now-rec['observed_at']>MAX_AGE_S:return None
    return rec

def extract(site,route,response,root):
    """Extract facts, never derive performance fitness or copy CSS."""
    if site not in HOSTS or not isinstance(response,tuple) or len(response)!=3:return None
    body,currency,market=response
    if not isinstance(body,str) or not 16000<=len(body.encode('utf8'))<=6000000:return None
    if not isinstance(currency,str) or not re.fullmatch('[A-Z]{3}',currency):return None
    if not isinstance(market,str) or not re.fullmatch('(?:base|static|[A-Z]{2})',market):return None
    m=BODY_RE.search(body)
    if m is None:return None
    classes=set(m.group(1).split())
    typ=route['type']
    if typ.startswith('archive:'):
        identity=route['id']==0 and 'post-type-archive-'+typ.split(':',1)[1] in classes
    else:
        prefix='category' if typ=='category' else (
            'term' if typ in ('destinations','tour-types','property-types','tour-categories') else None)
        identity=(f'{prefix}-{route["id"]}' in classes if prefix else
                  f'postid-{route["id"]}' in classes or f'page-id-{route["id"]}' in classes)
    if not identity:return None
    start=body.lower().find('<body')
    if start<0:return None
    docs=sorted({int(x) for x in DOC_RE.findall(body[start:])})
    if not docs:return None
    # Multi-CSS can include a LiteSpeed bundle plus independent Elementor
    # files. The previous LiteSpeed-only source SHA missed those files and
    # their order. This is original-HTML observation, NOT CSS issuance.
    if ('data-mad4b-ccss-v2-critical=' in body
        or 'data-mad4b-ccss-v2-rest=' in body):
        return None
    try:
        manifest=ordered.inspect(body,HOSTS[site],root)
    except (OSError,ValueError,TypeError,KeyError,UnicodeError):
        return None
    css_sha=manifest['manifest_sha256']
    # Signals for anomaly prioritization only: NOT verified LCP assets.
    h1=H1_RE.search(body[start:])
    title=html.unescape(TAG_RE.sub('',h1.group(1)) if h1 else '')
    images=IMG_RE.findall(body[start:start+120000])[:6]
    return {'doc_sha':digest(','.join(map(str,docs))),'css_sha':css_sha,
            'css_source_contract':ordered.CONTRACT,
            'css_files_observed':len(manifest['files']),
            'css_cascade_slots_observed':len({x['slot'] for x in manifest['files']}),
            'first_image_sha':digest('\n'.join(html.unescape(x) for x in images)),
            'has_h1':bool(title.strip()),'currency':currency,'market':market}

def current_http_404_hold(index,cat,route,state,ledger,now):
    """Negative HTTP source evidence only; NEVER a CSS source/route cert."""
    rows=index.get('source_http_holds',{})
    if not isinstance(rows,dict):return False
    rec=rows.get(route['route_key'])
    if not isinstance(rec,dict):return False
    at=rec.get('observed_at');expiry=rec.get('retry_after')
    return (rec.get('status')==404 and rec.get('origin')=='BYPASS'
        and rec.get('path')==route['path']
        and rec.get('source_key')==exact_source_key(cat,route,state,ledger)
        and type(at) is int and type(expiry) is int
        and at<=now<expiry<=at+HTTP_404_HOLD_S)

def fresh_original_source(site,host,path):
    """Original company WordPress source; never a cached HTML shell.

    Advisory only: no CSS certificate issued. Both first-party sites must
    prove independent, server-reported origin bypass before a source key is
    eligible to participate in template qualification.
    """
    if site not in HOSTS or host!=HOSTS[site]:
        raise ValueError('wrong-company-original-html-observer')
    html,headers=origin.public_fetch(host,path)
    if (not origin.original_proven_fresh(host,headers)
        or headers.get('X-MAD4B-Public-CCSS-Ready')=='1'):
        return None
    return (html,headers.get('X-MAD4B-Public-CCSS-Currency',''),
            headers.get('X-MAD4B-Public-CCSS-Market',''))

def refresh(site,cat,state,ledger,root,report,index,*,budget=4,fetch=None,now=None):
    """Bounded, cheap, repeatable source observations (no Chromium)."""
    if (site not in HOSTS or cat.get('host')!=HOSTS[site]
        or state.get('site_host')!=HOSTS[site] or str(root)!=ROOTS[site]):
        raise ValueError('template-index-company-contract-mismatch')
    if root.is_symlink():raise ValueError('untrusted-template-index-site-root')
    if (index.get('contract')!=CONTRACT or index.get('site')!=site
        or index.get('host')!=HOSTS[site]):raise ValueError('foreign-template-index')
    if not isinstance(budget,int) or not 1<=budget<=8:raise ValueError('unbounded-template-scan')
    fetch=fetch or (lambda host,path:fresh_original_source(site,host,path))
    now=int(time.time()) if now is None else int(now)
    rows=queue.safe_catalog(cat,site)
    # Scan divergent cohorts first, but also discover unseen variants in
    # shared pending templates BEFORE wasting another full browser canary.
    # Do not crawl singletons or fully qualified cohorts.
    pending_status={'source_variants_need_split','source_variant_mapping_pending',
                    'source_canary_pending','source_canary_hold'}
    scope={(f.get('source_profile'),f.get('route_type'),f.get('language'),f.get('source_graph'))
           for f in report.get('source_families',[])
           if f.get('status') in pending_status and f.get('member_count',0)>1}
    # Original source-family report already contains exact published,
    # unchanged certificates: their five-viewport measured DOM graph is
    # stronger than a lightweight anonymous HTTP observation. Do not
    # spend a network probe on an already proved current route.
    browser_proven={key for fam in report.get('source_families',[])
                    for variant in fam.get('observed_source_variants',[])
                    for key in variant.get('routes',[])}
    candidates=sorted((r for r in rows
                       if (r.get('source_profile'),r['type'],r['language'],r.get('source_graph')) in scope
                       and not queue.independently_certified_home(r)
                       and r['route_key'] not in browser_proven),
                      key=lambda r:(r['type'],r['language'],r['path']))
    known_keys={r['route_key'] for r in rows}
    records={key:val for key,val in index['observations'].items() if key in known_keys}
    # A 404 is a negative *source observation*, never route admission.
    # Keep it separate so observation totals and qualified CSS remain exact.
    prior_holds=index.get('source_http_holds',{})
    if not isinstance(prior_holds,dict) or len(prior_holds)>MAX_OBSERVATIONS:
        raise ValueError('untrusted-private-http-source-holds')
    holds={k:v for k,v in prior_holds.items()
           if k in known_keys and isinstance(v,dict)
           and isinstance(v.get('retry_after'),int)
           and v['retry_after']>now}
    # When all candidate routes have fresh observations, recheck a small
    # round-robin sample at most once per TTL; no periodic Chrome tests.
    # Rotate over the *full* stable catalog order, not only pending
    # routes. A temporarily unavailable WP page must never starve the
    # next public template; every refresh gets another bounded subset.
    start=index.get('cursor',0)%len(candidates) if candidates else 0
    ordered=(candidates[start:]+candidates[:start])
    pending=[r for r in ordered if current_observation(
        {**index,'observations':records},cat,r,state,ledger,now) is None
        and not current_http_404_hold(
            {**index,'source_http_holds':holds},cat,r,state,ledger,now)]
    # Prefer revalidating an observed page after a source-key change, rather
    # than repeatedly scanning unreachable, never-observed 404 siblings.
    # Still reserve every fourth selection for unseen routes; a broken stale
    # route may not starve new templates. Budget remains 1 for WP-CLI holds.
    stale=[r for r in pending if r['route_key'] in records]
    unseen=[r for r in pending if r['route_key'] not in records]
    unseen_turn=bool(stale and unseen and index.get('cursor',0)%4==3)
    selected=(unseen+stale if unseen_turn else stale+unseen)[:budget]
    attempted=0;updated=0;new_404_holds=0
    for route in selected:
        attempted+=1
        try:
            result=fetch(HOSTS[site],route['path'])
            fact=extract(site,route,result,root)
        except urllib.error.HTTPError as exc:
            headers=exc.headers
            if (site=='wayfa' and exc.code==404 and headers is not None
                and headers.get('X-Wayfa-Origin-Cache')=='BYPASS'
                and 'no-store' in headers.get('Cache-Control','').lower()):
                holds[route['route_key']]={
                    'status':404,'origin':'BYPASS','path':route['path'],
                    'source_key':exact_source_key(cat,route,state,ledger),
                    'observed_at':now,'retry_after':now+HTTP_404_HOLD_S}
                new_404_holds+=1
            fact=None
        except (OSError,ValueError,TimeoutError,KeyError):
            fact=None
        if fact is None:continue
        holds.pop(route['route_key'],None)
        records[route['route_key']]={**fact,
           'source_key':exact_source_key(cat,route,state,ledger),
           'object_id':route['id'],'path':route['path'],
           'language':route['language'],'type':route['type'],
           'observed_at':now}
        updated+=1
    if len(records)>MAX_OBSERVATIONS:
        for key,_ in sorted(records.items(),key=lambda t:t[1].get('observed_at',0))[:len(records)-MAX_OBSERVATIONS]:
            records.pop(key,None)
    cursor=((start+max(1,attempted))%len(candidates)) if candidates else 0
    return {**index,'observations':records,'source_http_holds':holds,
            'cursor':cursor,'last_scanned':attempted,'last_updated':updated,
            'last_http_404_holds_added':new_404_holds,
            'source_http_holds_total':len(holds),'last_run_at':now}

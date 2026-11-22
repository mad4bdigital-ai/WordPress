#!/usr/bin/env python3
"""Site-owned V2 batch publishing; original Chrome source capture, no performance QA gate.

Only original 421 catalog IDs. A bulk wave is a plan, NEVER a CSS certificate.
Each exact public V2 pending certificate has its own six-hour lease and QA.
"""
import hashlib,importlib.util,json,os,re,subprocess,sys,time
from pathlib import Path
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('mad4b_bulk_plan',HERE/'bulk-release-plan.py')
planner=importlib.util.module_from_spec(spec);spec.loader.exec_module(planner)
spec=importlib.util.spec_from_file_location('mad4b_bulk_qa',HERE/'bulk-postrelease-qa.py')
qa=importlib.util.module_from_spec(spec);spec.loader.exec_module(qa)
MAX_PER_TICKET=4
MAX_ACTIVE_PENDING=12
# Source-advisory debt scheduling only. Existing 422 CSS proof is never
# replayed or rewritten: the original route publisher must rebuild it.
STALE_CERTIFIED_RETRY_SECONDS=7200
MAX_CERTIFICATE_FILES_SCAN=30000
SCOPED_LISTING_TYPES={'tours-and-activities','properties','destinations',
 'tour-types','property-types','tour-categories','category',
 'archive:tours-and-activities','archive:properties'}
def recertification_debt_route(site,plan,ordered,attempts,pending_routes,now,
                               certroot=None):
    """Pick ONE old 422-certified route for exact fresh source/market QA.

    A mismatch to the pinned catalog is a *source preflight hint*, NOT a
    public-cert revocation, a route-ready grant or a historical QA reuse.
    The original issuer still fetches a current WPML catalog and rejects
    stale source/market/422 consumer+CSS dependency bytes independently.
    """
    if (site not in ('source','wayfa')
        or type(now) is not int or now<=0 or not isinstance(plan,dict)
        or not isinstance(ordered,list) or not isinstance(attempts,dict)
        or not isinstance(pending_routes,set)):
        return None
    expected={'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}[site]
    if (plan.get('site')!=site or plan.get('host')!=expected
        or not isinstance(plan.get('global_generation'),int)
        or not isinstance(plan.get('listing_generation'),int)
        or not isinstance(plan.get('css_epoch'),int)
        or not re.fullmatch('[a-f0-9]{64}',str(plan.get('runtime_sha','')))
        or not re.fullmatch('[a-f0-9]{64}',str(plan.get('consumer_v2_sha','')))):
        return None
    wp=Path(certroot) if certroot is not None else Path(qa.runner.CONFIG[site][2])
    certs=wp/'mad4b-public-critical-css-v1/certs-v2'
    try:
        if certs.is_symlink() or not certs.is_dir():
            return None
        directory=certs.stat()
        if directory.st_uid!=0 or directory.st_mode&0o022:
            return None
        candidates=[p for p in certs.iterdir() if p.name.endswith('.json')]
        if len(candidates)>MAX_CERTIFICATE_FILES_SCAN:
            return None
    except OSError:return None
    source={(r['language'],r['path'],r['type']):r for r in ordered
       if isinstance(r,dict) and all(k in r for k in (
           'language','path','type','route_key','revision'))}
    ranks=[]
    for file in sorted(candidates,key=lambda p:p.name):
        try:
            if (file.is_symlink() or not file.is_file()
                or not re.fullmatch(r'[a-f0-9]{64}\.json',file.name)):
                continue
            stat=file.stat()
            if (stat.st_uid!=0 or stat.st_mode&0o022 or stat.st_nlink!=1
                or not 400<=stat.st_size<=180000):
                continue
            cert=json.loads(file.read_text())
            if (cert.get('contract')!='mad4b.public-critical-css.route-certificate.v2'
                or cert.get('status')!='certified'
                or cert.get('host')!=expected
                or type(cert.get('object_id')) is not int
                or cert['object_id']<0
                or not all(isinstance(cert.get(k),str) for k in (
                    'language','path','type','market_country','currency'))):
                continue
            fingerprint='|'.join(str(cert[k]) for k in ('host','language',
                 'path','market_country','currency'))
            if hashlib.sha256(fingerprint.encode()).hexdigest()!=file.stem:
                continue
            row=source.get((cert['language'],cert['path'],cert['type']))
            if (not row or row['route_key'] in pending_routes
                or not re.fullmatch('[a-f0-9]{64}',row['route_key'])
                or type(row['revision']) is not int):
                continue
            last=attempts.get(row['route_key'],0)
            if type(last) is not int or now-last<STALE_CERTIFIED_RETRY_SECONDS:
                continue
            deps=cert.get('dependencies')
            if not isinstance(deps,dict):continue
            stale=[]
            if deps.get('global_generation')!=plan['global_generation']:
                stale.append('global')
            if deps.get('route_generation')!=row['revision']:
                stale.append('route')
            islisting=(cert['type'] in SCOPED_LISTING_TYPES
                       or deps.get('contains_dynamic_listing') is True)
            if islisting and deps.get('listing_generation')!=plan['listing_generation']:
                stale.append('listing')
            if cert.get('runtime_sha')!=plan['runtime_sha']:
                stale.append('runtime')
            if cert.get('consumer_sha')!=plan['consumer_v2_sha']:
                stale.append('consumer')
            if cert.get('css_epoch')!=plan['css_epoch']:
                stale.append('epoch')
            if not stale:continue
            # Prefer the literal Listing Generation mismatch the user
            # wants repaired, then oldest not-yet-attempted exact route.
            # Historical route revisions are NEVER treated as authorized.
            score=(0 if 'listing' in stale else 1,
                   int(last),cert['language'],cert['path'],row['route_key'])
            ranks.append((score,row,tuple(stale)))
        except (OSError,TypeError,ValueError,KeyError,UnicodeError,
                json.JSONDecodeError):
            continue
    if not ranks:return None
    _,row,stale=min(ranks,key=lambda x:x[0])
    return row,stale
MAX_TICKET_SECONDS=1100
RESERVE_FOR_ONE_ROUTE=460
# Never issue new provisional public CSS faster than original postrelease QA
# can certify/revoke the older, already-visible leases.
MAX_PENDING_AGE_WITH_NEW_RELEASE=90*60
MIN_PENDING_FOR_BACKPRESSURE=4
def qa_backpressure(pending,now):
    if not isinstance(pending,list) or type(now)is not int:return True
    if len(pending)<MIN_PENDING_FOR_BACKPRESSURE:return False
    ages=[]
    for item in pending:
        if (not isinstance(item,tuple) or len(item)!=3
            or type(item[0]) is not int or not 0<item[0]<=now+300):
            return True
        ages.append(item[0])
    return bool(ages and min(ages)<=now-MAX_PENDING_AGE_WITH_NEW_RELEASE)
def atomic(path,doc):
    temp=path.with_name('.'+path.name+'.pending-'+str(os.getpid()))
    with temp.open('x') as out:
        os.fchmod(out.fileno(),0o600);json.dump(doc,out,indent=2,sort_keys=True);out.write('\n')
    os.replace(temp,path)
def history(base,site):
    path=base/'bulk-publish-attempts.json'
    if not path.exists():return {},path,0
    doc=qa.read(path,250000)
    if doc.get('contract')!='mad4b.css.v2.bulk.publish-attempts.v1' or doc.get('site')!=site:
        raise ValueError('foreign-bulk-ledger')
    routes=doc.get('routes',{})
    if not isinstance(routes,dict) or len(routes)>30000:raise ValueError('unsafe-bulk-attempts')
    cursor=doc.get('next_index',0)
    if type(cursor) is not int or not 0<=cursor<1<<24:
        raise ValueError('invalid-bulk-fair-rotation-cursor')
    return routes,path,cursor
def run(site):
    if site not in qa.runner.CONFIG:raise ValueError('unregistered-site')
    root=qa.ROOT/site
    root.mkdir(parents=True,exist_ok=True,mode=0o700)
    # Build a source-proven planned order, re-read WordPress independently
    # inside every original release-only invocation for current generation.
    plan,location=planner.write_plan(site)
    if (plan.get('site')!=site or plan.get('host')!=qa.runner.CONFIG[site][0]
        or plan.get('policy',{}).get('max_parallel_browser_on_host')!=1
        or plan.get('ready_for_public_release')!=0):
        raise ValueError('invalid-untrusted-publish-plan')
    pending=qa.pending_rows(site)
    if qa_backpressure(pending,int(time.time())):
        oldest_age=(max(0,int(time.time())-pending[0][0])//60
                    if pending else 0)
        print('BULK_CCSS_PUBLISH_PENDING_CAP',site,len(pending),
              'original_QA_backpressure_oldest_age_min',oldest_age,flush=True)
        return
    if len(pending)>=MAX_ACTIVE_PENDING:
        print('BULK_CCSS_PUBLISH_PENDING_CAP',site,len(pending));return
    attempts,p,cursor=history(root,site)
    now=int(time.time())
    remaining=MAX_ACTIVE_PENDING-len(pending)
    limit=min(MAX_PER_TICKET,remaining)
    pending_routes={x[2]['route_key'] for x in pending}
    # Keep the source-family/WPML plan order, but preserve a cursor across
    # tickets. A fixed prefix would reattempt the same pages every 30 minutes
    # and starve later catalog routes forever. The cursor never grants CSS.
    ordered=[r for wave in plan['waves'] for r in wave['routes']]
    if not ordered:print('BULK_CCSS_PUBLISH_NO_ROUTES',site);return
    cursor=cursor%len(ordered)
    # At most ONE original certificate-debt repair per WP-Cron ticket.
    # All other turns retain the existing family+language cursor fairness.
    debt=recertification_debt_route(site,plan,ordered,attempts,pending_routes,now)
    priority_key=debt[0]['route_key'] if debt else None
    indices={r['route_key']:i for i,r in enumerate(ordered)}
    visits=([('debt',indices[priority_key])] if priority_key in indices else [])
    visits.extend(('rotation',(cursor+offset)%len(ordered))
                  for offset in range(len(ordered)))
    launched=0;deferrals=0;last_reason=''
    started=time.monotonic()
    for lane,selected_index in visits:
        # Original host WordPress wake is bounded to 1,380s; leave time for
        # the last exact-market source capture, WP ACK and safe retreat.
        if (launched>=limit or launched+deferrals>=max(4,limit*3)
            or time.monotonic()-started>MAX_TICKET_SECONDS-RESERVE_FOR_ONE_ROUTE):
            break
        candidate=ordered[selected_index]
        key=candidate['route_key']
        if (key in pending_routes
            or now-int(attempts.get(key,0))<
               (STALE_CERTIFIED_RETRY_SECONDS if lane=='debt' else 1800)):
            continue
        if not re.fullmatch('[a-f0-9]{64}',key):raise ValueError('unsafe-bulk-route-key')
        # One original route CSS source capture, and release to the exact
        # source/market key. No performance QA runs in this phase.
        args=[sys.executable,str(HERE/'run-public-route.py'),site,'bulk_publish',key]
        env={**os.environ,
             'MAD4B_MULTI_CSS_V2_RELEASE_FIRST':'1',
             'MAD4B_MULTI_CSS_V2_PUBLISH':'1',
             'MAD4B_MULTI_CSS_V2_RELEASE_APPROVED':'1'}
        out=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
            text=True,env=env,timeout=640,check=False)
        lines=out.stdout.splitlines()
        verified=[x for x in lines if x.startswith('BULK_CCSS_RELEASED '+site+' '+key+' ')]
        attempts[key]=int(time.time())
        # Debt repair must not move the original coverage cursor forward
        # or starve pages that have never received a provisional release.
        next_cursor=(cursor if lane=='debt'
                     else (selected_index+1)%len(ordered))
        if lane=='debt':
            print('BULK_CCSS_STALE_CERTIFIED_SOURCE_RETRY',site,
                  key[:12],'requires_new_source_422_proof',
                  ','.join(debt[1]) if debt else 'unknown',flush=True)
        atomic(p,{'contract':'mad4b.css.v2.bulk.publish-attempts.v1',
            'site':site,'updated_at':int(time.time()),'next_index':next_cursor,
            'routes':attempts})
        if len(verified)==1 and out.returncode==0:
            launched+=1
            print(verified[0],flush=True)
        else:
            deferrals+=1
            last_reason=(out.stderr or out.stdout)[-180:].replace('\n',' ')
            print('BULK_CCSS_SOURCE_SKIPPED',site,key[:12],
                'original_runtime_or_source_not_ready',last_reason[:120],flush=True)
        # Never force Chrome after original host load/queue deferral.
        if any(x.startswith(('DEFER_PUBLIC_CCSS_RESOURCE_WINDOW',
                             'DEFER_PUBLIC_CCSS_RESOURCE_SENSOR_UNAVAILABLE',
                             'SKIP_PUBLIC_CCSS_BROWSER_BUSY',
                             'HOLD_PUBLIC_CCSS_HIGH_LOAD')) for x in lines):
            break
    print('BULK_CCSS_PUBLISH_COMPLETED',site,'released',launched,
          'attempted',launched+deferrals,'pending',len(qa.pending_rows(site)),
          'planned',plan['eligible_for_source_preflight'],flush=True)
def main():
    if len(sys.argv)!=2:raise ValueError('usage: bulk-publish.py source|wayfa')
    run(sys.argv[1])
if __name__=='__main__':
    try:main()
    except (OSError,ValueError,KeyError,TypeError,TimeoutError,
            subprocess.TimeoutExpired,json.JSONDecodeError) as e:
        print('BULK_CCSS_PUBLISH_HOLD',type(e).__name__,str(e)[:180],file=sys.stderr)
        sys.exit(4)

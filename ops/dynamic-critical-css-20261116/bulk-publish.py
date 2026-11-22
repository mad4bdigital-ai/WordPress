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
MAX_TICKET_SECONDS=1100
RESERVE_FOR_ONE_ROUTE=460
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
    launched=0;deferrals=0;last_reason=''
    started=time.monotonic()
    for offset in range(len(ordered)):
        # Original host WordPress wake is bounded to 1,380s; leave time for
        # the last exact-market source capture, WP ACK and safe retreat.
        if (launched>=limit or launched+deferrals>=max(4,limit*3)
            or time.monotonic()-started>MAX_TICKET_SECONDS-RESERVE_FOR_ONE_ROUTE):
            break
        selected_index=(cursor+offset)%len(ordered)
        candidate=ordered[selected_index]
        key=candidate['route_key']
        if (key in pending_routes
            or now-int(attempts.get(key,0))<1800):
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
        next_cursor=(selected_index+1)%len(ordered)
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

#!/usr/bin/env python3
"""No QA approval can veto policy activation or cause sibling CSS inheritance."""
import hashlib,importlib.util,json,pathlib,tempfile,time,sys,urllib.error
from pathlib import Path
D=Path(sys.argv[1])
def load(name,file):
    sp=importlib.util.spec_from_file_location(name,D/file)
    mod=importlib.util.module_from_spec(sp);sp.loader.exec_module(mod)
    return mod
f=load('family_split_policy','public-source-families.py')
v=load('index_split_policy','public-template-variant-index.py')
q=f.queue
count=0
def check(value,name):
    global count
    assert value,name
    count+=1;print('PASS',name)
sha=lambda s:hashlib.sha256(s.encode()).hexdigest()
with tempfile.TemporaryDirectory(prefix='mad4b-css-template-policy-test-') as tmp:
 root=Path(tmp)/'wp-content'
 (root/'litespeed/css').mkdir(parents=True)
 (root/'mad4b-public-critical-css-v1/certs').mkdir(parents=True)
 (root/'mad4b-public-critical-css-v1/assets').mkdir(parents=True)
 f.ROOTS['wayfa']=(str(root),'wayfatours.com')
 v.ROOTS['wayfa']=str(root)
 routes=[]
 for i in range(4):
    id=101+i
    r={'language':'en','path':f'/tours-and-activities/example-{id}/',
       'type':'tours-and-activities','id':id,'revision':0,
       'source_profile':'shared-template:tours-and-activities:default',
       'source_graph_status':'configured','source_graph':'d'*64,
       'source_listing_dependency':True}
    r['route_key']=sha('wayfa|en|'+r['path'])
    routes.append(r)
 cat={'contract':q.CATALOG,'host':'wayfatours.com','wpml_languages':['en'],
      'default_language':'en','global_generation':672,
      'listing_generation':676,'routes':routes}
 state={'site_host':'wayfatours.com','critical_css':'on','css_epoch':39,
        'native_breakpoint':767,'runtime_sha':'a'*64,
        'enabled_currencies':['USD'],'base_currency':'USD','wcml_multi_currency':False}
 ledger={'site':'wayfa','contract':q.QUEUE,'routes':{}}
 docs=['b'*64,'c'*64,'b'*64,'d'*64]
 def cert(r,doc):
    cssname=sha(r['path'])[:32]+'.css'
    content=('body{background:#abcdef}'*1200).encode()
    (root/'litespeed/css'/cssname).write_bytes(content)
    crit=('h1{font-size:32px}'*1200).encode()
    critname=hashlib.sha256(crit).hexdigest()+'.css'
    (root/'mad4b-public-critical-css-v1/assets'/critname).write_bytes(crit)
    p={'contract':f.CERT,'status':'certified','host':cat['host'],
       'type':r['type'],'object_id':r['id'],'path':r['path'],'language':r['language'],
       'market_country':'base','currency':'USD','css_epoch':39,'runtime_sha':'a'*64,
       'native_mobile_breakpoint':767,'critical_cap_px':767,
       'css_file':cssname,'css_sha':hashlib.sha256(content).hexdigest(),
       'critical_file':critname,'critical_sha':hashlib.sha256(crit).hexdigest(),
       'critical_bytes':len(crit),'elementor_doc_fingerprint':doc,
       'dependencies':{'route_generation':0,'global_generation':672,
                       'listing_generation':676,'contains_dynamic_listing':True},
       'evidence':{'decision':'passed_evidence','tested':5,
                   'viewports':[320,390,414,767,768]}}
    file=sha('wayfatours.com|en|'+r['path']+'|base|USD')+'.json'
    (root/'mad4b-public-critical-css-v1/certs'/file).write_text(json.dumps(p))
    ledger['routes'][r['route_key']]={'status':'certified','depends_on_listings':True,
       'source_key':q.source_key(cat,r,state,True),'attempted_at':100,'retry_after':0}
 cert(routes[0],docs[0]);cert(routes[1],docs[1])
 old=f.build('wayfa',cat,state,ledger,root)
 check(old['source_groups_by_status'].get('source_variants_need_split')==1,
       'old divergent source cohort blocks entire unsplit group')
 index=v.empty('wayfa')
 for r,doc in ((routes[2],docs[0]),):
    index['observations'][r['route_key']]={'source_key':q.source_key(cat,r,state,False),
      'depends_on_listings':False,'path':r['path'],'language':r['language'],
      'object_id':r['id'],'type':r['type'],'doc_sha':doc,'css_sha':'e'*64,
      'first_image_sha':'e'*64,'market':'base','currency':'USD','observed_at':int(time.time())}
 split=f.build('wayfa',cat,state,ledger,root,variant_index=index)
 check(split['policy_state']=='active','template policy always active regardless QA state')
 check(split['quality_checks']=='observational_nonblocking_for_policy',
       'quality signals are not permission to activate policy')
 check(split['source_groups_total']==3,'real Elementor variants split without per-page Chrome')
 check(split['source_groups_by_status'].get('source_variant_mapping_pending')==1,
       'unmapped page is assigned cheap background discovery, not QA blocker')
 check(split['source_groups_by_status'].get('source_canary_qualified')==1,
       'singleton exact proven variant qualified')
 check(split['source_groups_by_status'].get('source_canary_pending')==1,
       'second known variant can request original canary only for its context')
 check(split['next_canary_route_key']==routes[2]['route_key'],
       'original Chromium scheduling targets the single next measured variant')
 check(split['routes_with_exact_current_certificate']==2,
       'zero unmeasured routes inherit a sibling browser certificate')
 check(split['routes_on_native_or_unproven_css']==2,
       'unproven CSS remains explicitly visible and never misreported')
 check(all(x['policy_state']=='active' for x in split['source_families']),
       'every source variant remains governed by active policy')
 check(len({x['family_key'] for x in split['source_families']})==3,
       'content-addressed variant families are stable and distinct')
 report={'source_families':[{'source_profile':routes[0]['source_profile'],
          'route_type':routes[0]['type'],'language':'en','source_graph':'d'*64,
          'member_count':4,'status':'source_variants_need_split'}]}
 cssname=sha(routes[3]['path'])[:32]+'.css'
 (root/'litespeed/css'/cssname).write_bytes(('body{color:#1a1a1a;}'*1200).encode())
 html='<html><head><link rel="stylesheet" href="https://wayfatours.com/wp-content/litespeed/css/'+cssname+'"></head><body class="single single-tours-and-activities postid-104"><h1>Original dynamic tour</h1><div class="elementor-69052 elementor-26212"><img src="/wp-content/uploads/tour.webp"></div>'+(' original post '*1800)+'</body></html>'
 response=(html,'USD','base')
 check(v.extract('wayfa',routes[3],response,root) is not None,
       'real public HTML-style object and origin CSS source admitted')
 check(v.extract('wayfa',routes[0],response,root) is None,
       'WordPress route object mismatch rejected before variant mapping')
 check(v.extract('wayfa',routes[3],(html,'EUR','invalid'),root) is None,
       'untrusted WCML market rejected from observational index')
 # By design the fourth route is the targeted next candidate. A
 # persistent cursor prevents an earlier temporarily unavailable 404
 # from blocking later cheap source observations.
 index['cursor']=3
 checks=[]
 def fetch(host,path):
    checks.append(path);return response
 fresh=v.refresh('wayfa',cat,state,ledger,root,report,index,
                 budget=1,fetch=fetch,now=9000)
 check(fresh['last_scanned']==1 and fresh['last_updated']==1,
       'no browser: one bounded anonymous public template observation')
 check(len(checks)==1,'light sampler does not fetch every route or run Chromium')
 check(len(fresh['observations'])==2,'observational index append-only for current source')
 check(v.current_observation(fresh,cat,routes[3],state,ledger,now=9001) is not None,
       'valid WordPress source observation can be reused cheaply')
 changed={**cat,'global_generation':673}
 check(v.current_observation(fresh,changed,routes[3],state,ledger,now=9001) is None,
       'dependency change automatically invalidates old observation')
 check(v.current_observation(fresh,cat,routes[3],state,ledger,now=9000+4*86400+1) is None,
       'four-day rotating integrity refresh does not require Chromium')
 pending_report={'source_families':[{
     'source_profile':routes[0]['source_profile'],
     'route_type':routes[0]['type'],'language':'en','source_graph':'d'*64,
     'member_count':4,'status':'source_canary_pending'}]}
 hits=[]
 def unavailable(host,path):
     hits.append(path)
     return None
 pending_idx=v.refresh('wayfa',cat,state,ledger,root,pending_report,v.empty('wayfa'),
                       budget=1,fetch=unavailable,now=9000)
 check(pending_idx['last_scanned']==1 and pending_idx['last_updated']==0,
       'pending shared source template sampled without browser')
 check(pending_idx['cursor']==1 and len(hits)==1,
       'failed anonymous observation cannot block next source route')
 check(f.build('wayfa',cat,state,ledger,root,variant_index=pending_idx)['policy_state']=='active',
       'unavailable public HTML never deactivates template policy')
 # 'No QA' is not a gate for the policy; no fake CSS certificate ever appears.
 noqa={**ledger,'routes':{}}
 emptyreport=f.build('wayfa',cat,state,noqa,root,variant_index=fresh)
 check(emptyreport['policy_state']=='active',
       'policy activated with no successful Chromium certificates')
 check(emptyreport['routes_with_exact_current_certificate']==0,
       'unverified routes never become visitor-facing certificates')
 # Under a reserved WP-CLI slot, prefer source-key-drift recovery while
 # keeping a bounded unseen turn. This never grants CSS.
 stale_index=v.empty('wayfa')
 stale_index['observations'][routes[1]['route_key']]={
     'source_key':'old-source-key','path':routes[1]['path']}
 stale_hits=[]
 def stale_fetch(host,path):
     stale_hits.append(path)
     return None
 checked=v.refresh('wayfa',cat,state,ledger,root,report,stale_index,
                   budget=1,fetch=stale_fetch,now=9000)
 check(stale_hits==[routes[1]['path']] and checked['last_scanned']==1,
       'reserved one-probe fallback prioritizes stale source-key evidence')
 stale_index['cursor']=3
 unseen_hits=[]
 def unseen_fetch(host,path):
     unseen_hits.append(path)
     return None
 v.refresh('wayfa',cat,state,ledger,root,report,stale_index,
           budget=1,fetch=unseen_fetch,now=9000)
 check(len(unseen_hits)==1 and unseen_hits[0]!=routes[1]['path'],
       'every fourth attempt preserves unseen route discovery')
 original=v.origin.public_fetch
 try:
     v.origin.public_fetch=lambda host,path:(html,{
         'X-Wayfa-Origin-Cache':'BYPASS','Cache-Control':'private, no-store',
         'X-MAD4B-Public-CCSS-Ready':'0',
         'X-MAD4B-Public-CCSS-Market':'base',
         'X-MAD4B-Public-CCSS-Currency':'USD'})
     check(v.fresh_original_source('wayfa','wayfatours.com',routes[3]['path'])==response,
           'Wayfa observations use uncached first-party WordPress HTML')
     v.origin.public_fetch=lambda host,path:(html,{
         'X-Wayfa-Origin-Cache':'HIT','Cache-Control':'public, max-age=1800',
         'X-MAD4B-Public-CCSS-Ready':'0',
         'X-MAD4B-Public-CCSS-Market':'base',
         'X-MAD4B-Public-CCSS-Currency':'USD'})
     check(v.fresh_original_source('wayfa','wayfatours.com',routes[3]['path']) is None,
           'public FastCGI HIT cannot become a template source proof')
     v.origin.public_fetch=lambda host,path:(html,{
         'X-Wayfa-Origin-Cache':'BYPASS','Cache-Control':'private, no-store',
         'X-MAD4B-Public-CCSS-Ready':'1',
         'X-MAD4B-Public-CCSS-Market':'base',
         'X-MAD4B-Public-CCSS-Currency':'USD'})
     check(v.fresh_original_source('wayfa','wayfatours.com',routes[3]['path']) is None,
           'already transformed CSS HTML cannot be indexed as native CSS')
 finally:
     v.origin.public_fetch=original
 # A verified uncached WordPress 404 is a temporary negative observation,
 # never a source sample. The page must be retried on expiry/source change.
 four=v.empty('wayfa');calls=[]
 def missing_origin(host,path):
     calls.append(path)
     raise urllib.error.HTTPError('https://wayfatours.com'+path,404,'Not Found',
          {'X-Wayfa-Origin-Cache':'BYPASS',
           'Cache-Control':'private, no-store'},None)
 first=v.refresh('wayfa',cat,state,ledger,root,report,four,
                 budget=1,fetch=missing_origin,now=12000)
 check(first['last_http_404_holds_added']==1
       and first['last_updated']==0 and len(first['observations'])==0
       and first['source_http_holds_total']==1,
       'uncached 404 records negative source hold, never CSS evidence')
 check(v.current_observation(first,cat,routes[0],state,ledger,12001) is None,
       '404 hold never qualifies a source template variant')
 check(v.current_http_404_hold(first,cat,routes[0],state,ledger,12001),
       'exact route and source-key 404 hold suspends repeated origin fetch')
 second_calls=[]
 def second_fetch(host,path):
     second_calls.append(path);return None
 second=v.refresh('wayfa',cat,state,ledger,root,report,first,
                  budget=1,fetch=second_fetch,now=12001)
 check(second_calls==[routes[1]['path']],
       '404 cooldown skips rejected route and advances to next sample')
 check(not v.current_http_404_hold(first,cat,routes[0],state,ledger,15601),
       '404 hold expires automatically after one hour')
 changed_source={**cat,'global_generation':cat['global_generation']+1}
 check(not v.current_http_404_hold(first,changed_source,routes[0],state,ledger,12001),
       'changed WordPress source key immediately reopens 404 observation')
 def cached_404(host,path):
     raise urllib.error.HTTPError('https://wayfatours.com'+path,404,'Not Found',
          {'X-Wayfa-Origin-Cache':'HIT','Cache-Control':'public, max-age=1800'},None)
 cached=v.refresh('wayfa',cat,state,ledger,root,report,v.empty('wayfa'),
                  budget=1,fetch=cached_404,now=12000)
 check(cached['source_http_holds_total']==0,
       'FastCGI cached 404 cannot suppress a public route')
 print('TEMPLATE_VARIANT_POLICY_REGRESSIONS',count)

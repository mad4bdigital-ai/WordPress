#!/usr/bin/env python3
"""A failed readback can nominate source re-test, never resurrect a certificate."""
import hashlib,importlib.util,json,pathlib,tempfile,time,sys
from pathlib import Path
D=Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('mod',D/'public-source-families.py')
f=importlib.util.module_from_spec(spec);spec.loader.exec_module(f)
now=2400000000
sha=lambda x:hashlib.sha256(x.encode()).hexdigest()
checks=0
def check(expr,label):
 global checks
 assert expr,label
 checks+=1;print('PASS',label)
with tempfile.TemporaryDirectory(prefix='mad4b-wayfa-verifier-negative-retry-') as td:
 root=Path(td)/'wp-content';folder=root/'mad4b-public-critical-css-v1'/'certs-v2'
 folder.mkdir(mode=0o700,parents=True)
 f.ROOTS['wayfa']=(str(root),'wayfatours.com')
 state={'site_host':'wayfatours.com','critical_css':'on','css_epoch':252,
        'runtime_sha':'a'*64,'base_currency':'USD','native_breakpoint':767}
 catalog={'host':'wayfatours.com','global_generation':679,'listing_generation':1436}
 routes=[]; ledger={'site':'wayfa','routes':{}}
 for i,name in enumerate(['beni-suef','el-minya','port-said','hurghada-city']):
  path='/destination/africa-containent/egypt/'+name+'/'
  route={'route_key':sha(path),'path':path,'id':120+i,'language':'en',
         'type':'destinations','revision':0}
  routes.append(route)
  key=f.queue.source_key(catalog,route,state,False)
  ledger['routes'][route['route_key']]={
      'status':'hold','source_key':key,'depends_on_listings':False,
      'attempted_at':now-100,'retry_after':now-1,'failures':1,
      'qa_policy_sha':'a'*64}
  context='wayfatours.com|en|'+path+'|base|USD'
  cert={'contract':f.v2.CONTRACT,'status':'reverted_post_release_qa',
        'host':'wayfatours.com','type':'destinations','object_id':route['id'],
        'path':path,'language':'en','market_country':'base','currency':'USD',
        'css_epoch':252,'runtime_sha':'a'*64,'released_at':now-300,
        'evidence':{'decision':('failed_post_release' if name=='hurghada-city'
                     else 'failed_public_post_release_readback'),'verified_at':now-100},
        'dependencies':{'route_generation':0,'global_generation':679,
                        'listing_generation':1436}}
  (folder/(sha(context)+'.json')).write_text(json.dumps(cert))
 def inspect(rows=routes,led=ledger,site='wayfa',cat=catalog,now_at=now):
  return f.verifier_only_recheck_candidates(site,cat,state,led,root,rows,now=now_at)
 check(inspect()==[r['route_key'] for r in routes[:3]],
       'only three readback failures retry while real LCP remains on same QA policy')
 check(inspect(site='source')==[], 'source staging never inherits Wayfa retry lane')
 upgraded_state={**state,'qa_policy_sha':'b'*64}
 original_state=state
 state=upgraded_state
 check(set(inspect())=={r['route_key'] for r in routes},
       'new signed QA implementation restores only a fresh LCP recheck slot')
 state={**original_state,'qa_policy_sha':'a'*64}
 check(inspect()==[r['route_key'] for r in routes[:3]],
       'same QA implementation never retries true LCP regression automatically')
 changed=json.loads(json.dumps(ledger));changed['routes'][routes[0]['route_key']]['source_key']='0'*64
 check(routes[0]['route_key'] not in inspect(led=changed),
       'changed WP route dependency cannot reuse old negative evidence')
 consumed=json.loads(json.dumps(ledger));consumed['routes'][routes[0]['route_key']]['attempted_at']=now-20
 check(routes[0]['route_key'] not in inspect(led=consumed),
       'an earlier failure is consumed after a new source attempt')
 cooling=json.loads(json.dumps(ledger));cooling['routes'][routes[0]['route_key']]['retry_after']=now+500
 check(routes[0]['route_key'] not in inspect(led=cooling),
       'original source retry-after is non-overridable')
 blocked=json.loads(json.dumps(ledger));blocked['routes'][routes[0]['route_key']]['failures']=3
 check(routes[0]['route_key'] not in inspect(led=blocked),
       'third negative QA failure disables automatic retries')
 folder_files=list(folder.glob('*.json'))
 tampered=next(p for p in folder_files if 'beni-suef' in p.read_text())
 original=tampered.read_text();data=json.loads(original);data['path']='/destination/africa-containent/egypt/different/'
 tampered.write_text(json.dumps(data))
 check(routes[0]['route_key'] not in inspect(),
       'certificate pathname tamper cannot nominate a new route')
 tampered.write_text(original)
 check(inspect()==[r['route_key'] for r in routes[:3]],
       'restoring attested negative source receipt restores only nomination')
print('PASS',checks,'source-owned negative verifier retry safeguards')

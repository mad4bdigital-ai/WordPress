#!/usr/bin/env python3
"""Pure source-family bounded-negative proof revalidation across QA updates."""
import importlib.util,pathlib,tempfile,hashlib
D=pathlib.Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116'
def load(name,file):
 spec=importlib.util.spec_from_file_location(name,D/file)
 module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
 return module
q=load('ccss_public_queue','public-route-queue.py')
f=load('ccss_source_families','public-source-families.py')
runner=load('ccss_original_runner','run-public-route.py')
# Test the candidate QA source, not the unrelated currently running live checkout.
runner.TOOLS=D
check_n=0
def check(x,name):
 global check_n
 if not x:raise AssertionError(name)
 check_n+=1;print('PASS',name)
source=runner.qa_policy_sha()
check(len(source)==64 and source==runner.qa_policy_sha(),'exact first-party QA source digest repeatable')
h='c'*64
routes=[{'path':'/destination/example-%d/'%i,'type':'destinations','language':'en',
  'id':i+100,'revision':0,'route_key':hashlib.sha256(('example-%d'%i).encode()).hexdigest(),
  'source_profile':'taxonomy:destinations','source_graph_status':'configured',
  'source_graph':h,'source_listing_dependency':True} for i in range(5)]
catalog={'contract':q.CATALOG,'host':'wayfatours.com','routes':routes,
 'wpml_languages':['en'],'default_language':'en','global_generation':23,'listing_generation':25}
state={'site_host':'wayfatours.com','css_epoch':39,'native_breakpoint':767,
 'critical_css':'on','enabled_currencies':['USD'],'base_currency':'USD','wcml_multi_currency':False,
 'runtime_sha':'1'*64,'qa_policy_sha':source}
ledger={'contract':q.QUEUE,'site':'wayfa','routes':{}}
old='f'*64
for r in routes[:4]:
 ledger['routes'][r['route_key']]={'status':'hold','source_key':q.source_key(catalog,r,state),
  'attempted_at':1111,'retry_after':0,'qa_policy_sha':old,'depends_on_listings':False}
with tempfile.TemporaryDirectory(prefix='mad4b-public-qa-policy-') as tmp:
 root=pathlib.Path(tmp)
 f.ROOTS['wayfa']=(str(root),'wayfatours.com')
 oldstate=dict(state,qa_policy_sha=old)
 old_report=f.build('wayfa',catalog,oldstate,ledger,root)
 changed=f.build('wayfa',catalog,state,ledger,root)
 check(old_report['source_groups_by_status'].get('source_canary_hold',0)==1,'old exact-QA four negative attempts block further repetitive canaries')
 check(old_report['next_canary_route_key'] != routes[4]['route_key'],'same-policy four-canary negative cap prevents trying a fifth unmeasured route')
 check(changed['source_groups_by_status'].get('source_canary_hold',0)==0,'changed verifier removes outdated negative cap')
 check(changed['next_canary_route_key']==routes[4]['route_key'],'changed verifier admits the fifth real WordPress-owned destination canary')
 check(q.source_key(catalog,routes[0],oldstate)==q.source_key(catalog,routes[0],state),'new verifier does not invalidate route CSS source certificate identity')
 check(q.optimizer_source_key(catalog,routes[0],oldstate)==q.optimizer_source_key(catalog,routes[0],state),'new verifier does not invalidate original optimizer source identity')
 rec=ledger['routes'][routes[0]['route_key']]
 rec['qa_policy_sha']=source
 half=f.build('wayfa',catalog,state,ledger,root)
 check(half['next_canary_route_key'] in {r['route_key'] for r in routes},'one fresh failure cannot count all historical failures toward cap')
check("state['qa_policy_sha']=qa_policy_sha()" in (D/'run-public-route.py').read_text(),'live certification snapshots original QA implementation')
check("state['qa_policy_sha']=runner.qa_policy_sha()" in (D/'refresh-source-families.py').read_text(),'read-only source-family report uses same original verifier fingerprint')
check("'qa_policy_sha':qa" in (D/'public-route-queue.py').read_text(),'original queue records QA identity for new attempts')
print('TOTAL',check_n)

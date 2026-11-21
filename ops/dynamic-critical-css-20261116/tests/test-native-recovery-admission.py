"""Original source-family queue only; no publisher, site access or CSS change."""
import copy,hashlib,importlib.util,time
from pathlib import Path
root=Path(__file__).resolve().parents[1]
sp=importlib.util.spec_from_file_location('original_site_family',root/'public-source-families.py')
f=importlib.util.module_from_spec(sp);sp.loader.exec_module(f)
now=int(time.time())
route={'type':'tours-and-activities','language':'en','path':'/tours-and-activities/example-day-tour/',
       'id':1234,'revision':17,'route_key':'a'*64,
       'source_graph_status':'configured','source_graph':'b'*64}
state={'site_host':'wayfatours.com','enabled_currencies':['USD'],'base_currency':'USD',
       'wcml_multi_currency':False,'css_epoch':252,'runtime_sha':'c'*64,
       'consumer_v2_sha':'d'*64,'native_breakpoint':767,'critical_css':'on'}
catalog={'contract':f.queue.CATALOG,'site':'wayfa','host':'wayfatours.com','global_generation':679,
         'listing_generation':1508,'routes':[route], 'default_language':'en','wpml_languages':['en']}
oldcat={**catalog,'listing_generation':1505}
rec={'status':'certified','depends_on_listings':True,'attempted_at':now-20,
     'source_key':f.queue.source_key(oldcat,route,state,True)}
ledger={'contract':f.queue.QUEUE,'site':'wayfa','routes':{route['route_key']:rec},'scheduler_sequence':4}
cert={'contract':f.v2.CONTRACT,'status':'certified','host':'wayfatours.com',
      'type':route['type'],'language':'en','path':route['path'],'object_id':route['id'],
      'market_country':'base','currency':'USD','css_epoch':252,
      'runtime_sha':state['runtime_sha'],'consumer_sha':state['consumer_v2_sha'],
      'native_mobile_breakpoint':767,'critical_cap_px':767,'certified_at':now-10,
      'dependencies':{'global_generation':679,'route_generation':17,
        'contains_dynamic_listing':True,'listing_generation':1505},
      'evidence':{'decision':'passed_evidence','tested':5,
         'viewports':[320,390,414,767,768],
         'performance_source':'real-original-css-urls','qa_sha256':'e'*64}}
pub={('en',route['path'],'base','USD'):cert}
def lane(c=catalog,s=state,l=ledger,p=pub,r=[route]):
 return f.recoverable_listing_generation_keys('wayfa',c,s,l,p,r)
assert lane()==[route['route_key']]
print('PASS genuine previous Chrome and old WordPress source key are eligible for RE-TEST only')
assert lane(c=oldcat)==[]
# Repeated tour edits may move the mutable queue source key after a successful
# intermediate Chrome certification, while the older published v2 certificate
# still proves a previous generation. Recovery is for NEW QA only.
latercat={**catalog,'listing_generation':1516}
intermediate=copy.deepcopy(ledger)
intermediate['routes'][route['route_key']]['source_key']=f.queue.source_key(
    catalog,route,state,True)
intermediate['routes'][route['route_key']]['attempted_at']=now-5
assert lane(c=latercat,l=intermediate)==[route['route_key']]
assert lane(c=latercat)==[route['route_key']]
assert lane(c={**catalog,'listing_generation':1606})==[]
unrelated=copy.deepcopy(intermediate)
unrelated['routes'][route['route_key']]['source_key']='f'*64
assert lane(c=latercat,l=unrelated)==[]
unrelated['routes'][route['route_key']]['status']='hold'
assert lane(c=latercat,l=unrelated)==[]
print('PASS bounded intermediate certified listing-source lineage only schedules new QA')
assert lane(c={**catalog,'global_generation':680})==[]
assert lane(s={**state,'css_epoch':253})==[]
assert lane(s={**state,'consumer_v2_sha':'f'*64})==[]
print('PASS listing/global/epoch/consumer changes invalidate stale new eligibility')
l=copy.deepcopy(ledger);l['routes'][route['route_key']]['source_key']='f'*64
assert lane(l=l)==[]
l['routes'][route['route_key']]['status']='hold'
assert lane(l=l)==[]
print('PASS unrelated old source key and failed QA history cannot use priority lane')
for k,v in [('decision','failed_post_release'),('tested',4),('qa_sha256','unsafe')]:
 p=copy.deepcopy(pub);p[('en',route['path'],'base','USD')]['evidence'][k]=v
 assert lane(p=p)==[]
print('PASS negative/missing actual 5-viewport real-original Chrome evidence held')
for k,v in [('market_country','DE'),('native_mobile_breakpoint',768),('critical_cap_px',768)]:
 p=copy.deepcopy(pub);p[('en',route['path'],'base','USD')][k]=v
 assert lane(p=p)==[]
print('PASS currency/market and breakpoint native safety retained')
r=[dict(route,revision=18)]
assert lane(r=r)==[]
print('PASS changed exact WordPress object requires new original source QA')
report={'next_canary_route_key':'1'*64,'next_canary_route_keys':['1'*64,'2'*64,'3'*64],
        'near_qualified_route_keys':['2'*64],
        'next_individual_css_route_key':'4'*64,'next_individual_css_route_keys':['4'*64],
        'next_verifier_only_recheck_route_key':'5'*64,
        'recoverable_listing_generation_route_keys':[route['route_key']]}
out=[f.plan_next_css_issuance(report,i) for i in range(80)]
assert out[0]=='5'*64 and out[8]=='5'*64
assert all(out[i]=='4'*64 for i in range(3,80,4))
assert out[5]==route['route_key']
assert len(set(out).intersection(set(['1'*64,'2'*64,'3'*64])))==3
print('PASS bounded native recovery shares rotation without starving new templates')
print('NATIVE_RECOVERY_ADMISSION_TESTS_PASS')

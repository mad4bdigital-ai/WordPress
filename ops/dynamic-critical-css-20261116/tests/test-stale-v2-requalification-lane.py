#!/usr/bin/env python3
"""Regression: published, newly stale V2 routes need bounded re-issue priority.

No stale certificate is reactivated. The original catalog, true source key,
browser five-viewports QA and WordPress V2 consumer remain authoritative.
"""
import copy,hashlib,importlib.util,pathlib,sys,time
D=pathlib.Path(sys.argv[1])
s=importlib.util.spec_from_file_location('family_priority',D/'public-source-families.py')
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
ok=0
def check(text,condition):
 global ok
 assert condition,text
 ok+=1
 print('PASS',text)
key=lambda n:f'{n:064x}'
def route(n):
 return {'route_key':key(n),'language':'en','type':'tours-and-activities','id':n,
         'path':f'/tours-and-activities/{n}/','revision':0}
a,b,c=route(10),route(20),route(30)
cat={'host':'wayfatours.com','global_generation':6,'listing_generation':109}
state={'runtime_sha':'a'*64,'consumer_v2_sha':'b'*64,'css_epoch':12,
       'enabled_currencies':['USD'],'base_currency':'USD','wcml_multi_currency':False}
cert={'contract':m.v2.CONTRACT,'status':'certified','host':cat['host'],
      'type':a['type'],'object_id':a['id'],'path':a['path'],
      'language':a['language'],'market_country':'base','currency':'USD',
      'runtime_sha':state['runtime_sha'],'consumer_sha':state['consumer_v2_sha'],
      'css_epoch':12,'dependencies':{'global_generation':6,'route_generation':0,
                'listing_generation':106,'contains_dynamic_listing':True}}
published={('en',a['path'],'base','USD'):cert}
ledger={'routes':{a['route_key']:{'status':'certified','source_key':'f'*64,
                    'depends_on_listings':True,'attempted_at':100000}}}
eligible=lambda c=cat,s=state,l=ledger,p=published,rows=[a]:m.recoverable_listing_generation_keys('wayfa',c,s,l,p,rows)
check('only old certified exact route with listing-generation drift nominated',
      eligible()==[a['route_key']])
check('new current listing generation immediately removes recovery candidacy',
      eligible(c={**cat,'listing_generation':106})==[])
check('a changed runtime cannot inherit old certificate',eligible(s={**state,'runtime_sha':'0'*64})==[])
check('changed V2 consumer cannot inherit old certificate',eligible(s={**state,'consumer_v2_sha':'0'*64})==[])
check('new release epoch cannot inherit old certificate',eligible(s={**state,'css_epoch':13})==[])
check('changed global generation cannot inherit old certificate',eligible(c={**cat,'global_generation':7})==[])
check('changed route generation cannot inherit old certificate',eligible(rows=[{**a,'revision':1}])==[])
check('uncertified or held queue record not nominated',eligible(l={'routes':{a['route_key']:{**ledger['routes'][a['route_key']],'status':'hold'}}})==[])
check('non-listing-dependent former CSS has no special lane',
      eligible(l={'routes':{a['route_key']:{**ledger['routes'][a['route_key']],'depends_on_listings':False}}})==[])
check('current exact queue key not repeated',eligible(l={'routes':{a['route_key']:{**ledger['routes'][a['route_key']],'source_key':m.queue.source_key(cat,a,state,True)}}})==[])
check('broken market currency not nominated',eligible(s={**state,'enabled_currencies':['EUR']})==[])
check('generation changes over 100 require normal original lane',eligible(c={**cat,'listing_generation':207})==[])
check('bad certificate contract not trusted',eligible(p={('en',a['path'],'base','USD'):{**cert,'contract':'foreign'}})==[])
try:m.recoverable_listing_generation_keys('source',cat,state,ledger,published,[a])
except ValueError:check('cross-company source boundary fails closed',True)
else:raise AssertionError('cross-company boundary allowed')
r={'next_canary_route_key':b['route_key'],
   'next_canary_route_keys':[b['route_key'],c['route_key']],
   'near_qualified_route_keys':[c['route_key']],
   'next_individual_css_route_key':key(40),
   'next_individual_css_route_keys':[key(40)],
   'recoverable_listing_generation_route_keys':[a['route_key']]}
selected=[m.plan_next_css_issuance(r,i) for i in range(32)]
check('recently invalidated route gets bounded independent re-qualification',
     all(selected[i]==a['route_key'] for i in (5,13,21,29)))
check('already near-qualified families keep their alternate recovery slots',
     all(selected[i]==c['route_key'] for i in (1,9,17,25)))
check('existing individually-certified-route lane is not stolen',
     all(selected[i]==key(40) for i in (3,7,11,15,19,23,27,31)))
check('fresh family lane still exercises multiple real route keys',
     {selected[i] for i in (2,4,6,10,12,14,18,20)}=={b['route_key'],c['route_key']})
r_no_near={**r,'near_qualified_route_keys':[]}
check('recovery lane works when all original near-finish canaries expired',
     all(m.plan_next_css_issuance(r_no_near,i)==a['route_key'] for i in (1,5,9,13)))
r_legacy={k:v for k,v in r.items() if k!='recoverable_listing_generation_route_keys'}
check('existing family lane unaffected without recovery proposal',
     m.plan_next_css_issuance(r_legacy,5)==c['route_key'])
for invalid in ('bad',[key(9),key(9)],['garbage'],[key(i) for i in range(65)]):
 try:m.plan_next_css_issuance({**r,'recoverable_listing_generation_route_keys':invalid},5)
 except (ValueError,TypeError):pass
 else:raise AssertionError('unsafe regression lane accepted')
check('malformed, duplicate or oversized candidate lane is rejected',True)
print('PASS ALL',ok,'bounded recovery lane invariants')

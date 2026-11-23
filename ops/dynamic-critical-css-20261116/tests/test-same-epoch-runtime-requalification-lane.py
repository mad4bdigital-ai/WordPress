#!/usr/bin/env python3
"""Formerly valid source proof after native runtime change is QA queue ONLY."""
import copy,importlib.util,pathlib,sys,time
D=pathlib.Path(sys.argv[1])
sp=importlib.util.spec_from_file_location('runtime_family',D/'public-source-families.py')
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
counter=0
def check(label,ok):
 global counter
 assert ok,label
 counter+=1
 print('PASS',label)
key=lambda n:f'{n:064x}'
r={'route_key':key(10),'language':'en','type':'tours-and-activities',
   'id':10,'path':'/tours-and-activities/10/','revision':0}
cat={'host':'wayfatours.com','global_generation':6,'listing_generation':109}
state={'runtime_sha':'a'*64,'consumer_v2_sha':'b'*64,'css_epoch':12,
       'enabled_currencies':['USD'],'base_currency':'USD','wcml_multi_currency':False}
oldstate={**state,'runtime_sha':'d'*64}
certified_at=int(time.time())-3600
c={'contract':m.v2.CONTRACT,'status':'certified','host':cat['host'],
   'type':r['type'],'object_id':r['id'],'path':r['path'],'language':r['language'],
   'market_country':'base','currency':'USD','runtime_sha':oldstate['runtime_sha'],
   'consumer_sha':state['consumer_v2_sha'],'css_epoch':12,
   'native_mobile_breakpoint':767,'critical_cap_px':767,'certified_at':certified_at,
   'evidence':{'decision':'passed_evidence','tested':5,'viewports':[320,390,414,767,768],
               'performance_source':'real-original-css-urls','qa_sha256':'e'*64},
   'dependencies':{'global_generation':6,'route_generation':0,'listing_generation':106,
                   'contains_dynamic_listing':True}}
published={(r['language'],r['path'],'base','USD'):c}
original_key=m.queue.source_key({**cat,'listing_generation':106},r,oldstate,True)
rec={'status':'certified','source_key':original_key,'depends_on_listings':True,
     'attempted_at':certified_at+9}
ledger={'routes':{r['route_key']:rec}}
eligible=lambda cat=cat,state=state,ledger=ledger,published=published,route=r:(
 m.recoverable_same_epoch_runtime_keys('wayfa',cat,state,ledger,published,[route]))
mutate_cert=lambda **changes:{(r['language'],r['path'],'base','USD'):{**c,**changes}}
check('prior five-width V2 proof nominates fresh runtime QA without certificate transfer',
      eligible()==[r['route_key']])
check('current runtime with prior native cert has no special lane',eligible(state=oldstate)==[])
check('old CSS epoch never gets runtime-only priority',
      eligible(published=mutate_cert(css_epoch=11))==[])
check('old or unauthorized consumer never gets priority',
      eligible(published=mutate_cert(consumer_sha='0'*64))==[])
check('missing current original consumer fails closed',
      eligible(state={**state,'consumer_v2_sha':None})==[])
check('wrong previous version reconstruction fails closed',
      eligible(ledger={'routes':{r['route_key']:{**rec,'source_key':'f'*64}}})==[])
check('current WP source key is never re-enqueued',
      eligible(ledger={'routes':{r['route_key']:{**rec,
      'source_key':m.queue.source_key(cat,r,state,True)}}})==[])
check('held route never silently qualified',eligible(ledger={'routes':{
 r['route_key']:{**rec,'status':'hold'}}})==[])
check('no false cross-market WCML eligibility',
      eligible(state={**state,'enabled_currencies':['EUR']})==[])
check('certificate supplied for foreign host rejected',
      eligible(published=mutate_cert(host='foreign.example'))==[])
check('wrong route revision cannot reuse old source key',
      eligible(route={**r,'revision':1})==[])
check('global source generation changed requires ordinary QA lane',
      eligible(cat={**cat,'global_generation':7})==[])
check('very old original listing generation needs ordinary QA lane',
      eligible(cat={**cat,'listing_generation':207})==[])
check('failed original evidence not nominated',
      eligible(published=mutate_cert(evidence={**c['evidence'],'decision':'failed_post_release'}))==[])
check('missing native QA signature not nominated',
      eligible(published=mutate_cert(evidence={**c['evidence'],'qa_sha256':'bad'}))==[])
check('partial browser QA never nominated',
      eligible(published=mutate_cert(evidence={**c['evidence'],'tested':4}))==[])
check('untrusted old certificate contract rejected',
      eligible(published=mutate_cert(contract='foreign'))==[])
check('old contract mobile breakpoint cannot transfer to 767',
      eligible(published=mutate_cert(native_mobile_breakpoint=768))==[])
check('falsified certified-at age rejects outdated proof',
      eligible(published=mutate_cert(certified_at=certified_at-16*86400))==[])
check('attempt to link old certificate to unrelated queue record rejected',
      eligible(ledger={'routes':{r['route_key']:{**rec,
      'attempted_at':certified_at+86401}}})==[])
try:m.recoverable_same_epoch_runtime_keys('source',cat,state,ledger,published,[r])
except ValueError:check('cross-company source domain rejected',True)
else:raise AssertionError('foreign scope accepted')
rpt={'next_canary_route_key':key(20),'next_canary_route_keys':[key(20),key(21)],
     'near_qualified_route_keys':[key(21)],
     'recoverable_listing_generation_route_keys':[key(30)],
     'recoverable_same_epoch_runtime_route_keys':[key(40)],
     'next_individual_css_route_key':key(50),
     'next_individual_css_route_keys':[key(50)]}
check('existing near-qualified lane retains one of four slots',
      m.plan_next_css_issuance(rpt,1)==key(21))
check('existing listing regression retains an alternating progress slot',
      m.plan_next_css_issuance(rpt,5)==key(30))
check('new old-runtime retry only enters the shared bounded completion slot',
      m.plan_next_css_issuance(rpt,9)==key(40))
check('one in four individual route asset issuance remains unchanged',
      all(m.plan_next_css_issuance(rpt,i)==key(50) for i in (3,7,11,15,19)))
check('fresh unseen family still owns its rotation slots',
      set(m.plan_next_css_issuance(rpt,i) for i in range(50) if i%4 in (0,2))==
      {key(20),key(21)})
for bad in ('not-a-list',[key(40),key(40)],['bad'],[key(i) for i in range(65)]):
 try:m.plan_next_css_issuance({**rpt,'recoverable_same_epoch_runtime_route_keys':bad},9)
 except (ValueError,TypeError):pass
 else:raise AssertionError('unsafe former-runtime route lane admitted')
check('malformed duplicate oversized runtime requalification lane rejected',True)
wide={**rpt,'next_canary_route_keys':[key(100+i) for i in range(55)],
      'next_individual_css_route_keys':[key(401)],
      'near_qualified_route_keys':[key(100)],
      'recoverable_listing_generation_route_keys':[key(301),key(302),key(303)],
      'recoverable_same_epoch_runtime_route_keys':[key(501),key(502),key(503),key(504)]}
chosen=[m.plan_next_css_issuance(wide,i) for i in range(2000)]
check('all 55 original family canaries still reached after 2000 opportunities',
      set(wide['next_canary_route_keys']).issubset(set(chosen)))
check('all 4 prior-runtime signed pages eventually get an independent turn',
      set(wide['recoverable_same_epoch_runtime_route_keys']).issubset(set(chosen)))
check('existing three stale listing pages keep independent turns',
      set(wide['recoverable_listing_generation_route_keys']).issubset(set(chosen)))
print('PASS',counter,'same-epoch runtime source-bound requalification invariants')

#!/usr/bin/env python3
"""No live writes; old signed 422 can nominate only a new original QA route."""
import importlib.util,hashlib,json,shutil,sys,tempfile
from pathlib import Path
D=Path(sys.argv[1])
GIT_WP=Path(sys.argv[2]) if len(sys.argv)>2 else D.parent.parent/'wp-content'
sp=importlib.util.spec_from_file_location('safe_source_422_requal',D/'public-source-families.py')
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
assert hashlib.sha256((GIT_WP/'mu-plugins/421-mad4b-public-critical-css-coverage.php').read_bytes()).hexdigest()==m.v2.ORDERED_421_SHA
checks=0
def ok(label,valid):
 global checks
 assert valid,label
 checks+=1;print('PASS',label)
def sha(*values):return hashlib.sha256('|'.join(values).encode()).hexdigest()
with tempfile.TemporaryDirectory() as folder:
 root=Path(folder);mu=root/'mu-plugins';mu.mkdir()
 firstparty=GIT_WP/'mu-plugins/421-mad4b-public-critical-css-coverage.php'
 shutil.copy2(firstparty,mu/firstparty.name)
 m.ROOTS['wayfa']=(str(root),'wayfatours.com')
 route={'route_key':sha('original','route','evidence'),'path':'/tours-and-activities/demo/',
        'language':'en','type':'tours-and-activities','id':60627,'revision':0}
 old=106;cat={'host':'wayfatours.com','global_generation':6,'listing_generation':109}
 state={'runtime_sha':'a'*64,'consumer_v2_sha':m.v2.ORDERED_422_SHA,'css_epoch':12,
   'enabled_currencies':['USD'],'base_currency':'USD','wcml_multi_currency':False}
 cert={'contract':m.v2.CONTRACT,'status':'certified','host':cat['host'],
   'language':'en','path':route['path'],'type':route['type'],'object_id':route['id'],
   'market_country':'base','currency':'USD','runtime_sha':state['runtime_sha'],
   'consumer_sha':m.v2.LEGACY_COMPANION_SHA,'css_epoch':12,
   'native_mobile_breakpoint':767,'critical_cap_px':767,
   'evidence':{'decision':'passed_evidence','tested':5,
    'viewports':[320,390,414,767,768],'performance_source':'real-original-css-urls','qa_sha256':'c'*64},
   'dependencies':{'global_generation':6,'route_generation':0,
      'listing_generation':old,'contains_dynamic_listing':True}}
 published={('en',route['path'],'base','USD'):cert}
 prior=m.queue.source_key({**cat,'listing_generation':old},route,state,True)
 ledger={'routes':{route['route_key']:{'status':'certified','depends_on_listings':True,
       'attempted_at':1795410100,'source_key':prior}}}
 def eligible(c=cat,s=state,l=ledger,p=published,r=route):
  return m.recoverable_listing_generation_keys('wayfa',c,s,l,p,[r])
 ok('original certified V2 legacy receipt can NOMINATE signed requalification',eligible()==[route['route_key']])
 ok('new original same-cert V2 receipt still nominates old listing source',
    eligible(p={('en',route['path'],'base','USD'):{**cert,'consumer_sha':state['consumer_v2_sha']}})==[route['route_key']])
 ok('unknown companion certificate SHA never gets recovery lane',
    eligible(p={('en',route['path'],'base','USD'):{**cert,'consumer_sha':'b'*64}})==[])
 (mu/firstparty.name).write_bytes(b'mutated-421-source')
 ok('modified 421 refuses prior SHA grandfathering',eligible()==[])
 shutil.copy2(firstparty,mu/firstparty.name)
 ok('global CSS dependencies changed => no recovery',eligible(c={**cat,'global_generation':7})==[])
 ok('an outdated CSS epoch cannot requalify from old source proof',eligible(s={**state,'css_epoch':13})==[])
 ok('a changed actual runtime SHA cannot requalify',eligible(s={**state,'runtime_sha':'f'*64})==[])
 ok('the exact tour revision changing cannot reuse old proof',
    eligible(r={**route,'revision':1})==[])
 ok('a missing original WordPress source key is never inferred',
    eligible(l={'routes':{route['route_key']:{**ledger['routes'][route['route_key']],'source_key':'f'*64}}})==[])
 ok('pending or reverted old QA lacks certified first-party ledger',
    eligible(l={'routes':{route['route_key']:{**ledger['routes'][route['route_key']],'status':'hold'}}})==[])
 ok('unchanged current listing requires no requalification',
    eligible(c={**cat,'listing_generation':old})==[])
 ok('listing drift over original 100-event bound denied',
    eligible(c={**cat,'listing_generation':old+101})==[])
 ok('unverified five-width browser evidence denied',
    eligible(p={('en',route['path'],'base','USD'):{**cert,'evidence':{**cert['evidence'],'tested':4}}})==[])
 ok('published market/currency drift cannot borrow country variant',
    eligible(s={**state,'enabled_currencies':['EUR']})==[])
 ok('no new CSS certificate is created by this recovery selector',
    not (root/'mad4b-public-critical-css-v1').exists())
 print('PASS',checks,'original strict legacy 422 requalification source-only lane')

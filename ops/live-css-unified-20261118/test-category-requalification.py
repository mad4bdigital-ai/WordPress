#!/usr/bin/env python3
"""Original source queue regression: category false holds retry, others stay."""
import importlib.util,hashlib
from pathlib import Path
p=Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116/public-route-queue.py'
spec=importlib.util.spec_from_file_location('category_queue',p)
q=importlib.util.module_from_spec(spec);spec.loader.exec_module(q)
def check(ok,label):
 assert ok,label
 print('PASS',label)
host='wayfatours.com'
state={'site_host':host,'css_epoch':7,'critical_css':'on','runtime_sha':'1'*64,
       'native_breakpoint':767,'source_optimizer_sha':'2'*64,
       'enabled_currencies':['USD'],'wcml_multi_currency':False}
cat={'contract':q.CATALOG,'host':host,'wpml_languages':['en'],'default_language':'en',
     'global_generation':4,'listing_generation':3,'routes':[]}
def route(kind,path,index):
 return {'type':kind,'path':path,'language':'en','route_key':hashlib.sha256((kind+path).encode()).hexdigest(),
         'revision':7,'id':index,'source_graph':'3'*64,'source_graph_status':'configured',
         'source_profile':'shared-template:'+kind+':default'}
r=route('category','/category/interests/',2943);cat['routes']=[r]
deps=[host,'en',r['path'],r['route_key'],'7','4','3','7','767','on','1'*64]
original=hashlib.sha256('|'.join(deps).encode()).hexdigest()
updated=q.source_key(cat,r,state)
check(original!=updated,'category false hold changes exact queue source identity')
ledger={'contract':q.QUEUE,'site':'wayfa','routes':{r['route_key']:{
 'status':'hold','source_key':original,'retry_after':9999999999,'attempted_at':1000
}}}
chosen,_=q.choose(cat,state,ledger,2000,r['route_key'])
check(chosen is r,'historical category HOLD retry reopened despite 4-hour cooldown')
check(ledger['routes'][r['route_key']]['status']=='hold','original ledger untouched until independent Chromium')
for kind in ['destinations','page','post','tours-and-activities','properties','archive:tours-and-activities']:
 x=route(kind,'/'+kind.replace(':','-')+'/',123)
 deps=[host,'en',x['path'],x['route_key'],'7','4','3' if kind not in ('page','post') else '','7','767','on','1'*64]
 expected=hashlib.sha256('|'.join(deps).encode()).hexdigest()
 check(q.source_key(cat,x,state)==expected,kind+' original source identity unchanged')
old=q.optimizer_source_key(cat,r,state)
check(bool(old),'category source family fingerprint is configured')
check(old!=q.optimizer_source_key(cat,{**r,'type':'destinations'},state),
      'category negative proof cannot be reused as custom-taxonomy proof')
ledger['routes'][r['route_key']]['source_key']=updated
unchanged,_=q.choose(cat,state,ledger,2000,r['route_key'])
check(unchanged is None,'new category HOLD still respects cooldown after one retry')

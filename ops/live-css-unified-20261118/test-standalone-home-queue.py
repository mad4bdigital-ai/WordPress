#!/usr/bin/env python3
"""Original root and translated Home never enter per-route CSS queue."""
import importlib.util,pathlib,hashlib
d=pathlib.Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116'
def load(name,path):
 spec=importlib.util.spec_from_file_location(name,d/path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
q=load('q','public-route-queue.py');family=load('f','public-source-families.py')
def route(lang,path,i,kind='page'):
 return dict(language=lang,path=path,id=i,type=kind,route_key=hashlib.sha256((lang+path).encode()).hexdigest(),revision=0)
pages=[route('en','/',11),route('es','/es/',12),
       route('en','/contact-us/',13),route('es','/es/contact-us/',14),
       route('en','/category/interests/',15,'category')]
catalog=dict(contract=q.CATALOG,host='wayfatours.com',default_language='en',wpml_languages=['en','es'],routes=pages,global_generation=3,listing_generation=4)
state=dict(site_host='wayfatours.com',critical_css='on',css_epoch=39,source_optimizer_sha='1'*64,
 enabled_currencies=['USD'],runtime_sha='2'*64,native_breakpoint=767)
ledger={'contract':q.QUEUE,'site':'wayfa','routes':{}}
def check(b,name):
 if not b:raise AssertionError(name)
 print('PASS',name)
check(q.independently_certified_home(pages[0]),'default Home is independent')
check(q.independently_certified_home(pages[1]),'WPML translated Home is independent')
check(not q.independently_certified_home(pages[2]),'other published page is eligible')
check(not q.independently_certified_home(pages[4]),'WordPress category remains eligible')
check(q.choose(catalog,state,ledger,1,pages[0]['route_key'])==(None,None),'stale preferred Home cannot reach cache release')
check(q.choose(catalog,state,ledger,1,pages[1]['route_key'])==(None,None),'stale translated Home cannot reach cache release')
candidate,key=q.choose(catalog,state,ledger,1)
check(candidate is not None and not q.independently_certified_home(candidate),'ordinary source queue never chooses Home')
check(candidate['path']=='/contact-us/','other general pages continue normally')
check('catalogrows=[r for r in queue.safe_catalog(catalog,site)' in (d/'public-source-families.py').read_text(),'family coordinator filters independent Home')
check('if not queue.independently_certified_home(r)' in (d/'public-source-families.py').read_text(),'family proposal cannot nominate Home')
print('TOTAL 10/10')
check("raise ValueError('home-owned-by-independent-first-paint-certifier')" in (d/'public-route-queue.py').read_text(),
      'home cannot be recorded as per-route certified even by a stale queued job')
print('RECORD_GUARD 1/1')

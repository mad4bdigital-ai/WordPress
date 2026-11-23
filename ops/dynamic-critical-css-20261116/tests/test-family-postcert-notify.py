#!/usr/bin/env python3
"""A successful original CSS certification may refresh only monitoring.
Never issue a certificate, bypass original queue, or cross company boundaries.
"""
import ast,importlib.util,pathlib,sys
from pathlib import Path
root=Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('family_progress',root/'public-source-families.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
route={'route_key':'a'*64,'language':'en','path':'/tours-and-activities/test/','id':12,'type':'tours-and-activities','revision':0}
cat={'routes':[route],'host':'wayfatours.com'}
state={'css_epoch':10}
ledger={'contract':m.queue.QUEUE,'site':'wayfa','routes':{route['route_key']:{
        'status':'certified','source_key':'x'*64,'depends_on_listings':True}}}
report=m.PROGRESS_REPORT_ROOT/'wayfa'/'source-families-latest.json'
wp=Path(m.ROOTS['wayfa'][0])
saved=(m.queue.safe_catalog,m.queue.read_ledger,m.queue.source_key,m.queue.atom,m.build)
observed=[]
m.queue.safe_catalog=lambda c,s:c['routes']
m.queue.read_ledger=lambda p,s:ledger
m.queue.source_key=lambda c,r,s,d:'x'*64
m.queue.atom=lambda path,doc:observed.append((path,doc))
m.build=lambda site,c,s,l,wp,variant_index=None:{
    'contract':m.CONTRACT,'source_groups_total':22,
    'source_canary_qualified_groups':2,
    'routes_with_exact_current_certificate':4}
def check(label,fn,ok):
    before=len(observed)
    err=None
    try: fn()
    except ValueError as exc:err=str(exc)
    assert ((err is None)==ok), (label,err)
    if not ok:assert len(observed)==before,(label,'mutated on failure')
    print('PASS',label)
def valid():
    return m.rollup_after_certified_event(
        'wayfa',cat,state,Path('/tmp/fake-private-ledger.json'),wp,route,{},report)
try:
    x=valid()
    assert x=={'families':22,'qualified':2,'current':4}
    assert len(observed)==1 and observed[0][0]==report
    assert observed[0][1]['refresh_trigger']=='original_certified_route_event'
    print('PASS certified original queue event updates only allowed private family report')
    check('refuses cross site path',lambda:m.rollup_after_certified_event(
        'wayfa',cat,state,Path('test'),wp,route,{},m.PROGRESS_REPORT_ROOT/'source'/'source-families-latest.json'),False)
    check('refuses source company root from Wayfa',lambda:m.rollup_after_certified_event(
        'wayfa',cat,state,Path('test'),Path(m.ROOTS['source'][0]),route,{},report),False)
    check('refuses unregistered route',lambda:m.rollup_after_certified_event(
        'wayfa',cat,state,Path('test'),wp,{**route,'route_key':'b'*64},{},report),False)
    ledger['routes'][route['route_key']]['status']='hold'
    check('never counts original hold as certified',valid,False)
    ledger['routes'][route['route_key']]['status']='certified'
    ledger['routes'][route['route_key']]['source_key']='other'
    check('never accepts changed original queue source key',valid,False)
    ledger['routes'][route['route_key']]['source_key']='x'*64
    ledger['routes'][route['route_key']]['depends_on_listings']='maybe'
    check('never accepts untrusted dependency flag',valid,False)
    ledger['routes'][route['route_key']]['depends_on_listings']=True
    check('exact source identity remains sufficient for report observation',valid,True)
    assert len(observed)==2
    src=(root/'run-public-route.py').read_text()
    ast.parse(src)
    assert src.count("if result=='certified':")>=1
    assert src.index('print(run(args,timeout=25))')<src.index("if result=='certified':")
    print('PASS original queue record completes before optional progress update')
    assert 'SOURCE_FAMILY_AFTER_CERTIFIED_REFRESH' in src
    assert 'OBSERVE_FAMILY_POST_CERT_REFRESH_DEFERRED' in src
    print('PASS telemetry errors fail open without changing CSS release result')
finally:
    (m.queue.safe_catalog,m.queue.read_ledger,m.queue.source_key,m.queue.atom,m.build)=saved
print('PASS 10/10 post-certified event-only family telemetry invariants')

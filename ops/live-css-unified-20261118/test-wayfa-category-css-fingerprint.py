#!/usr/bin/env python3
"""Negative-proof invalidation limited to original WordPress category."""
import importlib.util,pathlib
p=pathlib.Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116/public-route-queue.py'
spec=importlib.util.spec_from_file_location('m',p);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
digest='4'*64
state={'source_optimizer_sha':digest,'runtime_sha':digest,
       'enabled_currencies':['USD'],'wcml_multi_currency':False,
       'css_epoch':39,'native_breakpoint':767,'critical_css':'on'}
cat={'host':'wayfatours.com','global_generation':3,'listing_generation':4}
route={'type':'category','route_key':'a'*64,'language':'en','path':'/category/interests/',
       'source_profile':'taxonomy:category','source_graph':digest,
       'source_graph_status':'configured','revision':0}
changed=dict(state,source_category_css_policy_sha='7'*64)
source_key=lambda c,r,s:m.source_key(c,r,s)
opt_key=lambda c,r,s:m.optimizer_source_key(c,r,s)
checks=[
 ('category page triggers exact source-key renewal',source_key(cat,route,state)!=source_key(cat,route,changed)),
 ('category source negative proof triggers retry',opt_key(cat,route,state)!=opt_key(cat,route,changed)),
 ('other tours retain their sources',source_key(cat,{**route,'type':'tours-and-activities'},state)==source_key(cat,{**route,'type':'tours-and-activities'},changed)),
 ('other tours retain negative proofs',opt_key(cat,{**route,'type':'tours-and-activities'},state)==opt_key(cat,{**route,'type':'tours-and-activities'},changed)),
 ('destinations retain source and negative proofs',all((fn(cat,{**route,'type':'destinations'},state)==fn(cat,{**route,'type':'destinations'},changed)) for fn in (source_key,opt_key))),
 ('staging source category untouched',all(fn({**cat,'host':'tours.dev.mad4b.com'},route,state)==fn({**cat,'host':'tours.dev.mad4b.com'},route,changed) for fn in (source_key,opt_key))),
 ('unchanged file identity never requeues',source_key(cat,route,changed)==source_key(cat,route,dict(changed))),
 ('removed or missing plugin changes source identity',source_key(cat,route,state)!=source_key(cat,route,changed)),
]
for name,ok in checks:
 if not ok:raise AssertionError(name)
 print('PASS',name)
print('CATEGORY_FINGERPRINT_TESTS 8/8')

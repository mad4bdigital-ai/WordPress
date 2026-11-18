#!/usr/bin/env python3
"""Requeue only affected Wayfatours destination layout on exact MU source change."""
from pathlib import Path
import importlib.util
d=Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116'
spec=importlib.util.spec_from_file_location('original_public_route_queue',d/'public-route-queue.py')
q=importlib.util.module_from_spec(spec);spec.loader.exec_module(q)
C={'host':'wayfatours.com','global_generation':672,'listing_generation':676}
state={'runtime_sha':'c'*64,'css_epoch':39,'critical_css':'on','native_breakpoint':767,
       'source_optimizer_sha':'d'*64,'enabled_currencies':['USD'],'wcml_multi_currency':False}
route={'type':'destinations','language':'en','path':'/destination/africa-containent/egypt/aswan-gov/aswan-city/',
       'route_key':'a'*64,'revision':0,'source_graph':'b'*64,
       'source_profile':'taxonomy:destinations','source_graph_status':'configured'}
before=dict(state,source_destination_css_policy_sha='1'*64,source_category_css_policy_sha='1'*64)
after=dict(before,source_destination_css_policy_sha='2'*64)
def check(ok,label):
    if not ok:raise AssertionError(label)
    print('PASS',label)
check(q.source_key(C,route,before)!=q.source_key(C,route,after),'destination exact CSS source key changes when 427 changes')
check(q.optimizer_source_key(C,route,before)!=q.optimizer_source_key(C,route,after),'destination negative canary proof is requalified after 427 source update')
cat={**route,'type':'category','path':'/category/interests/'}
check(q.source_key(C,cat,before)==q.source_key(C,cat,after),'unmodified category independent source key retained')
check(q.optimizer_source_key(C,cat,before)==q.optimizer_source_key(C,cat,after),'unmodified category negative proof retained')
for kind in ('page','post','tours-and-activities','tour-types','properties'):
 r={**route,'type':kind}
 check(q.source_key(C,r,before)==q.source_key(C,r,after),f'{kind} exact source key untouched')
 check(q.optimizer_source_key(C,r,before)==q.optimizer_source_key(C,r,after),f'{kind} negative proof untouched')
stage={**C,'host':'tours.dev.mad4b.com'}
check(q.source_key(stage,route,before)==q.source_key(stage,route,after),'staging destination source key untouched')
check(q.optimizer_source_key(stage,route,before)==q.optimizer_source_key(stage,route,after),'staging negative source proof untouched')
check(q.source_key(C,route,after)==q.source_key(C,route,dict(after)),'stable source digest causes no recurring invalidation')
print('DESTINATION_CSS_SOURCE_FINGERPRINT 17/17')

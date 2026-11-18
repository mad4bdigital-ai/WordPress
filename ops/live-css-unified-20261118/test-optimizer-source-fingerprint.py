#!/usr/bin/env python3
"""Source 424 + Wayfa 378 invalidate only their actual negative QA claims."""
from __future__ import annotations
import hashlib
import importlib.util
from pathlib import Path
qpath=Path(__file__).resolve().parent.parent/'dynamic-critical-css-20261116/public-route-queue.py'
spec=importlib.util.spec_from_file_location('css_queue',qpath)
q=importlib.util.module_from_spec(spec);spec.loader.exec_module(q)
digest='1'*64
base={
 'source_optimizer_sha':digest,'runtime_sha':digest,'enabled_currencies':['USD'],
 'css_epoch':5,'native_breakpoint':767,'wcml_multi_currency':False
}
route={
 'type':'tours-and-activities','language':'en',
 'source_profile':'shared-template:tours-and-activities:default',
 'source_graph':digest,'source_graph_status':'configured'
}
source={'host':'tours.dev.mad4b.com','global_generation':10}
wayfa={'host':'wayfatours.com','global_generation':10}
def key(cat,r,st):
 return q.optimizer_source_key(cat,r,st)
def verify(ok,name):
 if not ok:raise AssertionError(name)
 print('PASS',name)
s1={**base,'source_private_css_policy_sha':'2'*64}
verify(key(source,route,base)!=key(source,route,s1),'source new CSS bundler triggers source retry')
verify(key(wayfa,route,base)==key(wayfa,route,s1),'source new bundler leaves Wayfa alone')
verify(key(source,{**route,'type':'page'},base)!=key(source,{**route,'type':'page'},s1),'public pages receive same Source optimizer fingerprint')
w1={**base,'source_tour_css_policy_sha':'3'*64}
verify(key(wayfa,route,base)!=key(wayfa,route,w1),'Wayfa tour veto change rechecks tour negative proof')
verify(key(wayfa,{**route,'type':'destinations'},base)==key(wayfa,{**route,'type':'destinations'},w1),'Wayfa destination certificate unaffected by tour-only source')
verify(key(wayfa,route,base)==key(wayfa,route,base),'no source drift avoids unneeded retries')
verify(key(source,{**route,'source_graph_status':'ambiguous'},s1) is None,'ambiguous template source cannot authorize negative source proof')

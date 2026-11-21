#!/usr/bin/env python3
"""Historical published cert files are not live delivery or family proof."""
from pathlib import Path
import importlib.util,sys
D=Path(sys.argv[1]);s=importlib.util.spec_from_file_location('css_family_drift',D/'public-source-families.py')
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
r={'type':'tours-and-activities','id':12,'path':'/tours-and-activities/demo/','language':'en','revision':5}
cat={'routes':[r],'global_generation':10,'listing_generation':160}
state={'runtime_sha':'a'*64,'css_epoch':42}
cert={'contract':m.v2.CONTRACT,'status':'certified','css_epoch':42,'runtime_sha':'a'*64,
      'dependencies':{'global_generation':10,'listing_generation':100,
                      'route_generation':5,'contains_dynamic_listing':True}}
rows={('en',r['path'],'base','USD'):cert}
report=m.published_v2_generation_drift(cat,state,rows)
assert report['certified_file_count']==1
assert report['reasons']=={'listing_generation_changed':1}
assert report['generation_aligned_is_not_delivery_proof'] is True
print('PASS changed global listing generation invalidates immutable cert attribution')
assert m.published_v2_generation_drift(cat,{**state,'css_epoch':43},rows)['reasons']=={'runtime_or_css_epoch_changed':1}
print('PASS CSS epoch or source runtime invalidates independently')
assert m.published_v2_generation_drift(cat,state,{('en','/missing/','base','USD'):cert})['reasons']=={'route_removed_or_unpublished':1}
print('PASS missing or unpublished route is never called current')
cert2={**cert,'dependencies':{**cert['dependencies'],'listing_generation':160}}
assert m.published_v2_generation_drift(cat,state,{('en',r['path'],'base','USD'):cert2})['reasons']=={'generation_aligned_not_live_readback':1}
print('PASS even generation-aligned immutable certificate requires live readback')
assert m.published_v2_generation_drift(cat,state,{('en',r['path'],'base','USD'):{**cert,'status':'reverted_post_release_qa'}})['certified_file_count']==0
print('PASS revoked certificate excluded')
print('PASS 5/5 source generation drift evidentiary invariants')

#!/usr/bin/env python3
import importlib.util,hashlib,json
from pathlib import Path
p=Path(__file__).with_name('js-cpu-policy.py');spec=importlib.util.spec_from_file_location('cpu_policy',p);mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
id={'site':'https://tours.dev.mad4b.com','env':'staging','uuid':mod.UUID,'epoch':6,'guest':'1','defer':'0','mobile_cache':'1','js_options':'min=1;combine=0','asset_digest':'a'*64}
profile=[]
for mobile in (False,True):
 profile.append({'mobile':mobile,'status':200,'path':'/','navs':2,'errors':[],'samples':1800,'top':[
 {'url':'https://scripts.clarity.ms/0.8.1/clarity.js','ms':200 if not mobile else 144},
 {'url':'https://tours.dev.mad4b.com/wp-includes/js/jquery/jquery.min.js','ms':81},
 {'url':'https://www.youtube.com/s/player/iframe_api','ms':103}]})
a=mod.plan(id,'/' if False else profile,path='/')
assert a['runtime_mode']=='native' and not a['auto_apply']
assert a['devices']['mobile']['samples']==1 and a['devices']['desktop']['samples']==1
assert a['devices']['desktop']['experiments'][0]['experiment']=='test_post_first_paint_telemetry'
print('PASS independent device-scoped CPU experiments')
h=a['devices']['desktop']['source_fingerprint']
b=mod.plan(id,profile,[{'fingerprint':h,'experiment':'test_post_first_paint_telemetry','result':'HOLD'}]);assert not any(x['experiment']=='test_post_first_paint_telemetry' for x in b['devices']['desktop']['experiments']);assert any(x['experiment']=='test_post_first_paint_telemetry' for x in b['devices']['mobile']['experiments'])
print('PASS prior failed trial blocked only on matching device/source')
id2=dict(id,epoch=7);c=mod.plan(id2,profile,[{'fingerprint':h,'experiment':'test_post_first_paint_telemetry','result':'HOLD'}]);assert any(x['experiment']=='test_post_first_paint_telemetry' for x in c['devices']['desktop']['experiments'])
print('PASS changed source restores experiment eligibility')
try:mod.plan(dict(id,env='production'),profile)
except ValueError:print('PASS Production fail closed')
else:raise AssertionError('Production accepted')
try:mod.plan(id,profile,path='/checkout/')
except ValueError:print('PASS sensitive route prohibited')
else:raise AssertionError('checkout accepted')
print('mad4b.js.cpu-policy.contract.v1 PASS')

#!/usr/bin/env python3
"""Hermetic Desktop/Mobile state-isolation and fail-closed contracts."""
import importlib.util,tempfile,json,hashlib,sys
from pathlib import Path
p=Path(__file__).with_name('policy-engine.py');spec=importlib.util.spec_from_file_location('device_policy',p)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
with tempfile.TemporaryDirectory(prefix='mad4b-device-policy-') as tmp:
 q=Path(tmp);m.QA=q;m.POLICY=q/'policy.json';m.SITE=q/'site';m.scope=lambda:None;m.epoch=lambda:4;m.guest_mode=lambda:'1'
 m.POLICY.write_text(json.dumps({**m.EXPECTED,'routes':{}}))
 css='/wp-content/plugins/fluentform/assets/css/fluent-forms-elementor-widget.css'
 f=m.SITE/css.lstrip('/');f.parent.mkdir(parents=True);f.write_text('a{color:black}')
 def scout(device):
  (q/'scout-result.json').write_text(json.dumps({'path':'/','device':device,'success':True,'eligible':{'fluentform-elementor-widget':{'relpath':css,'tier':'idle'}},'samples':[{'route':'/','status':200,'mobile':device=='mobile'}]}))
 def rows(device,slow=False,nav=False):
  out=[]
  for n in range(5):
   for mode in ('native-v1','canary-v1'):
    cand=mode=='canary-v1';delta=(1100 if slow else -90) if cand else 0
    out.append({'round':n,'mobile':device=='mobile','mode':mode,'status':200,'path':'/','allReady':True,'failures':[],'tags':int(cand),'lcp':3500+delta,'fcp':3300+delta,'ttfb':1000,'cls':.004,'navigations':2 if nav and cand else 1,'guestVary':1 if nav and cand else 0,'pageErrors':0,'top':[None]*5})
  return out
 def result(device,slow=False,nav=False,complete=True):
  v=rows(device,slow,nav)
  (q/'benchmark-result.json').write_text(json.dumps({'path':'/','device':device,'complete':complete,'planned':10,'observations':v if complete else v[:6]}))
 scout('desktop');assert m.stage('/','desktop')==0
 result('desktop');assert m.verify('/','desktop')==0
 print('PASS independent Desktop certification')
 scout('mobile');assert m.stage('/','mobile')==0
 result('mobile',slow=True);assert m.verify('/','mobile')==5
 d=m.manifest()['routes'][m.routekey('/')]['devices']
 assert d['desktop']['status']=='certified' and d['mobile']['status']=='hold'
 print('PASS Mobile rejection does not revoke Desktop certification')
 assert m.stage('/','mobile')==2
 print('PASS Mobile previous failure retained as no-repeat trial')
 f.write_text('a{color:white}')
 scout('mobile');assert m.stage('/','mobile')==0
 result('mobile',nav=True);assert m.verify('/','mobile')==5
 print('PASS source change makes new candidate eligible; Guest reload rejects it')
 f.write_text('a{color:red}')
 scout('mobile');assert m.stage('/','mobile')==0
 result('mobile',complete=False);assert m.verify('/','mobile')==5
 print('PASS incomplete ten-observation device evidence cannot certify')
 f.write_text('a{color:blue}')
 scout('mobile');assert m.stage('/','mobile')==0
 result('mobile')
 m.epoch=lambda:5
 try:m.verify('/','mobile')
 except RuntimeError as exc:
  assert 'changed during A/B' in str(exc)
 else:raise AssertionError('epoch drift admitted')
 print('PASS WordPress source drift prevents device certification')
 assert m.routekey('/')!=m.routekey('/ar/')
 print('PASS language routes remain independent')
print('mad4b.device-css.policy.v2 PASS')

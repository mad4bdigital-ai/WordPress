#!/usr/bin/env python3
import importlib.util,tempfile,json,time,sys
from pathlib import Path
p=Path(__file__).with_name('policy-engine.py');spec=importlib.util.spec_from_file_location('css_policy_module',p);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
with tempfile.TemporaryDirectory(prefix='mad4b-css-test-') as d:
 q=Path(d);m.QA=q;m.POLICY=q/'policy.json';m.scope=lambda:None
 m.POLICY.write_text(json.dumps({**m.EXPECTED,'routes':{}}))
 css='/wp-content/plugins/fluentform/assets/css/fluent-forms-elementor-widget.css'
 scout={'path':'/','success':True,'eligible':{'fluentform-elementor-widget':{'relpath':css,'tier':'idle'}},'samples':[{'route':'/','status':200},{'route':'/','status':200}]}
 (q/'scout-result.json').write_text(json.dumps(scout))
 assert m.stage('/')==0;print('PASS candidate staging')
 def observations(slow=False):
  rows=[]
  for round in range(3):
   for mobile in (False,True):
    for mode in ('native-v1','canary-v1'):
     active=mode=='canary-v1';t=3500+200*mobile
     rows.append({'round':round,'mobile':mobile,'mode':mode,'status':200,'path':'/','allReady':True,'failures':[],'tags':1 if active else 0,'lcp':t+(1200 if slow and active else (-90 if active else 0)), 'fcp':t-200+(1200 if slow and active else (-90 if active else 0)), 'cls':.005,'top':[None]*5})
  return rows
 (q/'benchmark-result.json').write_text(json.dumps({'path':'/','observations':observations()}))
 assert m.verify('/')==0 and list(m.manifest()['routes'].values())[0]['status']=='certified';print('PASS automatic certification')
 assert m.stage('/')==0;print('PASS recurring recertification')
 (q/'benchmark-result.json').write_text(json.dumps({'path':'/','observations':observations(True)}))
 assert m.verify('/')==5 and list(m.manifest()['routes'].values())[0]['status']=='hold';print('PASS automatic rollback on regression')
 assert m.stage('/')==2 and list(m.manifest()['routes'].values())[0]['status']=='hold';print('PASS remembers bad subset and reverts to native')
 assert m.routekey('/') != m.routekey('/fr/');print('PASS locale separation')
print('mad4b.autonomous-css.local-contract.v1 PASS')

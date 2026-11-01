#!/usr/bin/env python3
import importlib.util,tempfile,json,time,sys
from pathlib import Path
p=Path(__file__).with_name('policy-engine.py');spec=importlib.util.spec_from_file_location('css_policy_module',p);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
with tempfile.TemporaryDirectory(prefix='mad4b-css-test-') as d:
 q=Path(d);m.QA=q;m.POLICY=q/'policy.json';m.SITE=q/'site';m.scope=lambda:None;m.epoch=lambda:1;m.guest_mode=lambda:'0'
 m.POLICY.write_text(json.dumps({**m.EXPECTED,'routes':{}}))
 css='/wp-content/plugins/fluentform/assets/css/fluent-forms-elementor-widget.css'
 cssfile=m.SITE/css.lstrip('/');cssfile.parent.mkdir(parents=True,exist_ok=True);cssfile.write_text('.fixture{display:block}')
 scout={'path':'/','success':True,'eligible':{'fluentform-elementor-widget':{'relpath':css,'tier':'idle'}},'samples':[{'route':'/','status':200},{'route':'/','status':200}]}
 (q/'scout-result.json').write_text(json.dumps(scout))
 assert m.stage('/')==0;print('PASS candidate staging')
 def observations(slow=False):
  rows=[]
  for round in range(5):
   for mobile in (False,True):
    for mode in ('native-v1','canary-v1'):
     active=mode=='canary-v1';t=3500+200*mobile
     rows.append({'round':round,'mobile':mobile,'mode':mode,'status':200,'path':'/','allReady':True,'failures':[],'tags':1 if active else 0,'lcp':t+(1200 if slow and active else (-90 if active else 0)), 'fcp':t-200+(1200 if slow and active else (-90 if active else 0)), 'cls':.005,'ttfb':1000,'pageErrors':0,'navigations':1,'guestVary':0,'top':[None]*5})
  return rows
 (q/'benchmark-result.json').write_text(json.dumps({'path':'/','complete':True,'planned':20,'observations':observations()}))
 assert m.verify('/')==0 and list(m.manifest()['routes'].values())[0]['status']=='certified';print('PASS automatic certification')
 assert m.stage('/')==0;print('PASS recurring recertification')
 # An incomplete A/B must not pass, even if the available samples are unusually fast.
 partial=observations()[:8];(q/'benchmark-result.json').write_text(json.dumps({'path':'/','complete':False,'planned':20,'observations':partial}))
 assert m.verify('/')==5;print('PASS partial samples force HOLD')
 assert m.stage('/')==2;print('PASS incomplete evidence cannot be silently restaged')
 # Reset only the isolated fixture for the next explicit full failure-case test.
 m.POLICY.write_text(json.dumps({**m.EXPECTED,'routes':{}}))
 assert m.stage('/')==0
 (q/'benchmark-result.json').write_text(json.dumps({'path':'/','complete':True,'planned':20,'observations':observations(True)}))
 assert m.verify('/')==5 and list(m.manifest()['routes'].values())[0]['status']=='hold';print('PASS automatic rollback on regression')
 assert m.stage('/')==2 and list(m.manifest()['routes'].values())[0]['status']=='hold';print('PASS remembers bad subset and reverts to native')
 assert m.routekey('/') != m.routekey('/fr/');print('PASS locale separation')
 m.POLICY.write_text(json.dumps({**m.EXPECTED,'routes':{}}))
 assert m.stage('/')==0
 rows=observations()
 next(x for x in rows if x['mobile'] and x['mode']=='canary-v1')['navigations']=2
 next(x for x in rows if x['mobile'] and x['mode']=='canary-v1')['guestVary']=1
 (q/'benchmark-result.json').write_text(json.dumps({'path':'/','complete':True,'planned':20,'observations':rows}))
 assert m.verify('/')==5
 print('PASS guest vary/extra navigation cannot certify CSS')

print('mad4b.autonomous-css.local-contract.v1 PASS')

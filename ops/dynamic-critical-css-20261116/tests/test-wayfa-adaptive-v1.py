#!/usr/bin/env python3
import hashlib,importlib.util,json,os,pathlib,subprocess,sys,tempfile
D=pathlib.Path(sys.argv[1])
h=lambda b:hashlib.sha256(b).hexdigest()
native=D/'wayfa-native-adaptive767-candidate.php'
assert native.is_file()
with tempfile.TemporaryDirectory(prefix='mad4b-cap767-publisher-') as td:
 root=pathlib.Path(td)/'wp-content'
 out=pathlib.Path(td)/'batch'
 for p in (root/'mu-plugins',root/'litespeed/css',root/'mad4b-critical-css-v1',out):p.mkdir(parents=True,exist_ok=True)
 consumer=root/'mu-plugins/415-mad4b-critical-css-orchestrator.php'
 consumer.write_bytes(native.read_bytes())
 original=b'.css-original{display:block}'*2000
 css=b'.css-critical{display:block}'*2000
 fn=h(b'unique-packet')[:32]+'.css'
 crit=h(css)+'.css'
 (root/'litespeed/css'/fn).write_bytes(original)
 (out/crit).write_bytes(css)
 row={'lang':'en','route':'/','source_sha':h(original),'source_bytes':len(original),
      'css_file':fn,'critical_sha':h(css),'critical_bytes':len(css),
      'critical_file':crit,'device':'mobile','widths':[320,390,414,768]}
 inv={'contract':'mad4b.mobile-critical.candidates.v1','host':'wayfatours.com','errors':[],'candidates':[row]}
 gates={'en':{'decision':'passed_evidence','widths':[320,390,414,768],
       'cssPriority':'low','fullCssPreload':False,'adaptiveMaxWidth':767,
       'hero900':False,'deferCity':False}}
 qa={'contract':'mad4b.mobile-critical.qa.v1','tested':4,
       'gates':gates,'results':[{'lang':'en','width':w,'pass':True,'fcpGain':1800 if w<768 else -156,'lcpReg':32} for w in [320,390,414,768]]}
 selected={'site':'wayfa','routes':[{'locale':'en','css_sha':h(original),'route':'/','status':'needs_native_browser_qa'}]}
 spec=importlib.util.spec_from_file_location('admission',D/'qa-admission.py')
 m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
 def dumps(fn,obj):(out/fn).write_text(json.dumps(obj))
 def admission_for(value):
  g={**qa,'gates':{'en':{**gates['en'],'adaptiveMaxWidth':value}}}
  return m.evaluate('wayfa',['en'],inv,g,selected)
 report,subset=admission_for(767)
 assert report['accepted']==['en']
 print('PASS adaptive 767 four-width QA admitted independently')
 bad,_=admission_for(599)
 assert bad['accepted']==[]
 print('PASS unapproved 599 cap rejected on Wayfa')
 dumps('candidates.json',inv);dumps('admitted-candidates.json',subset)
 dumps('locale-admission.json',report);dumps('verification.json',qa)
 dumps('manifest-before-promotion.json',{})
 (root/'mad4b-critical-css-v1/manifest.json').write_text(json.dumps({'version':1,'site':'wayfatours.com','entries':{}}))
 env={**os.environ,'MAD4B_WP_CONTENT':str(root),'MAD4B_OUTPUT':str(out),
   'MAD4B_TARGET_HOST':'wayfatours.com','MAD4B_CSS_EPOCH':'39',
   'MAD4B_DRY_RUN':'1','MAD4B_CSS_NATIVE_V1_COMPAT':'1',
   'MAD4B_CSS_ADMITTED_CANDIDATES':'admitted-candidates.json',
   'MAD4B_CSS_LEGACY_CONSUMER_SHA256':h(consumer.read_bytes())}
 def run():
  return subprocess.run([sys.executable,str(D/'tools/publish.py')],env=env,
      capture_output=True,text=True,timeout=15)
 ok=run()
 assert ok.returncode==0,(ok.stdout,ok.stderr)
 assert [x['language'] for x in json.loads(ok.stdout)['promoted']]==['en']
 print('PASS cap767 native consumer exact SHA and original low/no-preload publisher')
 # Tamper cannot turn a failed 768 into a certificate.
 qa2={**qa,'gates':{'en':{**gates['en'],'decision':'hold'}},
      'results':[dict(x,**{'pass':False}) if x['width']==768 else x for x in qa['results']]}
 dumps('verification.json',qa2)
 assert run().returncode!=0
 print('PASS failed 768px evidence cannot be published')
 dumps('verification.json',qa)
 (consumer).write_text(consumer.read_text().replace('media="(min-width:768px)" fetchpriority="auto"','media="(min-width:767px)" fetchpriority="auto"'))
 env['MAD4B_CSS_LEGACY_CONSUMER_SHA256']=h(consumer.read_bytes())
 assert run().returncode!=0
 print('PASS changed or unsupported native media contract denied')
 print('PASS 5/5 Wayfa 767px adaptive native publisher constraints')

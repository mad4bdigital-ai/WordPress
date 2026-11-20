#!/usr/bin/env python3
"""Publisher refuses any uncertified locale even when its sibling passed."""
import hashlib,json,os,pathlib,subprocess,sys,tempfile
d=pathlib.Path(sys.argv[1]);pub=d/'tools/publish.py'
h=lambda x:hashlib.sha256(x).hexdigest()
with tempfile.TemporaryDirectory() as t:
 root=pathlib.Path(t)/'wp-content';out=pathlib.Path(t)/'reports'
 for p in [root/'mu-plugins',root/'litespeed/css',root/'mad4b-critical-css-v1',out]:p.mkdir(parents=True)
 consumer=root/'mu-plugins/415-mad4b-critical-css-orchestrator.php'
 consumer.write_text('private static function verified_variant( mad4b.mobile-critical-css.v2 elementor_doc_fingerprint')
 manifest={'version':2,'contract':'mad4b.mobile-critical-css.v2','site':'tours.dev.mad4b.com','entries':{}}
 (root/'mad4b-critical-css-v1/manifest.json').write_text(json.dumps(manifest))
 rows=[]
 for lang in ['en','es']:
  source=(lang*11000).encode()
  crit=(lang*8500).encode()
  sourcefile=h(('bundle'+lang).encode())[:32]+'.css'
  criticalfile=h(crit)+'.css'
  (root/'litespeed/css'/sourcefile).write_bytes(source)
  (out/criticalfile).write_bytes(crit)
  rows.append({'lang':lang,'route':'/' if lang=='en' else '/es/',
    'device':'mobile','widths':[320,390,414,768],
    'css_file':sourcefile,'source_sha':h(source),'source_bytes':len(source),
    'critical_file':criticalfile,'critical_sha':h(crit),'critical_bytes':len(crit),
    'page_id':123 if lang=='en' else 124,'dom_sha':h(lang.encode())})
 cand={'contract':'mad4b.mobile-critical.candidates.v1','host':'tours.dev.mad4b.com','candidates':rows,'errors':[]}
 qa={'contract':'mad4b.mobile-critical.qa.v1','tested':10,'results':[],
     'gates':{lang:{'decision':'passed_evidence' if lang=='en' else 'hold',
                     'widths':[320,390,414,767,768], 'adaptiveMaxWidth':0,
                     'cssPriority':'high','fullCssPreload':True,
                     'hero900':False,'deferCity':False,
                     'testedAt':'2026-11-17T00:00:00Z',
                     'minimumFcpGainMs':200,'worstLcpRegMs':100,'worstCls':0.01}
               for lang in ['en','es']}}
 admission={'contract':'mad4b.mobile-critical.locale-admission.v1','site':'source',
            'accepted':['en'],'held':[{'locale':'es'}]}
 def dump(name,value):(out/name).write_text(json.dumps(value))
 dump('candidates.json',cand);dump('verification.json',qa)
 dump('admitted-candidates.json',{**cand,'candidates':[rows[0]]})
 dump('locale-admission.json',admission)
 env={**os.environ,'MAD4B_WP_CONTENT':str(root),'MAD4B_OUTPUT':str(out),
      'MAD4B_TARGET_HOST':'tours.dev.mad4b.com','MAD4B_CSS_EPOCH':'129',
      'MAD4B_DRY_RUN':'1','MAD4B_CSS_NATIVE_V1_COMPAT':'0',
      'MAD4B_CSS_ADMITTED_CANDIDATES':'admitted-candidates.json'}
 cases=[]
 def check(label,should_pass):
  r=subprocess.run([sys.executable,str(pub)],env=env,capture_output=True,text=True,timeout=10)
  if should_pass:
   assert r.returncode==0,(label,r.stdout,r.stderr)
   stat=json.loads(r.stdout)
   assert [x['language'] for x in stat['promoted']]==['en'] and not stat['holds'],stat
  else:assert r.returncode!=0,(label,r.stdout,r.stderr)
  cases.append(label);print('PASS',label)
 check('only fully passing language is promoted',True)
 dump('admitted-candidates.json',{**cand,'candidates':[rows[0],rows[1]]})
 check('uncertified sibling cannot ride on passing QA',False)
 dump('admitted-candidates.json',{**cand,'candidates':[dict(rows[0],critical_sha=h(b'fake'))]})
 check('candidate content SHA tamper rejected',False)
 dump('admitted-candidates.json',{**cand,'candidates':[rows[0]]})
 dump('locale-admission.json',{**admission,'accepted':['es']})
 check('admission locale tamper rejected',False)
 dump('locale-admission.json',admission)
 env['MAD4B_CSS_ADMITTED_CANDIDATES']='../../arbitrary-file.json'
 check('path traversal refused by exact admission filename',False)
 env['MAD4B_CSS_ADMITTED_CANDIDATES']='admitted-candidates.json'
 env['MAD4B_CSS_NATIVE_V1_COMPAT']='1'
 check('legacy native v1 cannot use adaptive subset publication',False)
 print('PASS %d/%d publisher source and QA admission constraints'%(len(cases),len(cases)))

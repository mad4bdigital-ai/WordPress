import importlib.util,tempfile,pathlib,json,sys
p=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location("gate",p/"event-gate.py")
gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
with tempfile.TemporaryDirectory() as t:
 t=pathlib.Path(t);(t/'mad4b-critical-css-v1').mkdir()
 mf=t/'mad4b-critical-css-v1/manifest.json';cp=t/'consumer.php'
 mf.write_text('{"version":2,"site":"tours.dev.mad4b.com"}');cp.write_text("<?php // stable consumer")
 s={"site_host":"tours.dev.mad4b.com","environment":"staging","critical_css":"on","css_epoch":129,
    "wpml_languages":["en","es","de"],"default_language":"en","native_breakpoint":767,
    "last_home_impact_at":1794830000,"critical_css_dirty_generation":9}
 key=gate.digest(s,mf,cp,"source")
 checks=0
 def ok(label,result):
  global checks
  assert result,label
  checks+=1;print("PASS",label)
 ok("first run requires inspection",gate.decide(key,{},10000)[0]=="inspect")
 old={"key":key,"status":"ready","checked_at":9000}
 ok("same source skips redundant Chromium",gate.decide(key,old,10000)[0]=="skip")
 ok("daily fingerprint integrity check skips browser",
   gate.decide(key,old,9000+86401)[0]=="skip")
 ok("bounded weekly browser nonregression",
   gate.decide(key,old,9000+7*86400+1)[0]=="inspect")
 ok("new CSS epoch invalidates old ready",gate.decide(gate.digest(dict(s,css_epoch=130),mf,cp,"source"),old,10000)[0]=="inspect")
 ok("new active WPML language invalidates old ready",gate.digest(dict(s,wpml_languages=s['wpml_languages']+['fr']),mf,cp,"source")!=key)
 ok("new content dependency generation invalidates ready",gate.digest(dict(s,critical_css_dirty_generation=10),mf,cp,"source")!=key)
 mf.write_text('{"version":2,"site":"tours.dev.mad4b.com","generation":5}')
 ok("new manifest invalidates old ready",gate.digest(s,mf,cp,"source")!=key)
 # Independent content addressing of LiteSpeed CSS bytes, even if plugin
 # forgets to update an epoch. This must trigger a new browser QA.
 (t/'litespeed/css').mkdir(parents=True)
 css=t/'litespeed/css'/('a'*32+'.css')
 css.write_bytes(b'body{color:red}')
 manifest={'version':2,'site':'tours.dev.mad4b.com',
    'entries':{'z'*64:{'bundle_file':'a'*32+'.css'}}}
 mf.write_text(json.dumps(manifest))
 csskey=gate.digest(s,mf,cp,'source')
 css.write_bytes(b'body{color:blue}')
 ok("changed LiteSpeed CSS bytes invalidate unchanged manifest",
    gate.digest(s,mf,cp,'source')!=csskey)
 ok("changed Elementor runtime version invalidates prior key",
    gate.digest(dict(s,runtime_versions={'elementor':'3.4'}),mf,cp,'source')!=
    gate.digest(dict(s,runtime_versions={'elementor':'3.5'}),mf,cp,'source'))
 ok("failed run cooldown",gate.decide(key,dict(old,status="hold"),9050)[0]=="skip")
 try:gate.digest(s,mf,cp,"wayfa")
 except ValueError:ok("cross-company impersonation rejected",True)
 else:raise AssertionError
 gate.write(t/"ledger.json",{"contract":gate.CONTRACT,"site":"source","key":key,"status":"ready","checked_at":10000})
 ok("atomic ledger committed",gate.read(t/"ledger.json","source")["key"]==key)
 original_root=gate.QA_ROOT
 fake=t/'qa';fake.mkdir()
 for name in gate.QA_SOURCES:
  target=fake/name;target.parent.mkdir(parents=True,exist_ok=True)
  target.write_bytes(('native-qa-v1:'+name).encode())
 try:
  gate.QA_ROOT=fake
  before=gate.digest(s,mf,cp,'source')
  (fake/'safe-cap-fallback.py').write_bytes(b'native-qa-v2:accepted-599')
  after=gate.digest(s,mf,cp,'source')
  ok("new 599px admission logic forces immediate fresh browser qualification",after!=before)
  wbefore=gate.digest(dict(s,site_host='wayfatours.com',environment='production'),mf,cp,'wayfa')
  (fake/'safe-cap-fallback.py').write_bytes(b'unrelated-source-only-policy')
  ok("source-only cap update does not invalidate Wayfa PHP home certification",
   gate.digest(dict(s,site_host='wayfatours.com',environment='production'),mf,cp,'wayfa')==wbefore)
  source_key_before_wayfa_change=gate.digest(s,mf,cp,'source')
  (fake/'wayfa-home-preflight.py').write_bytes(b'new wayfa first-party cache versioning proof')
  ok("Wayfa HTML stabilization fix invalidates its old QA hold immediately",
   gate.digest(dict(s,site_host='wayfatours.com',environment='production'),mf,cp,'wayfa')!=wbefore)
  ok("Wayfa-only cache behavior does not unnecessarily invalidate source QA",
   gate.digest(s,mf,cp,'source')==source_key_before_wayfa_change)
  (fake/'tools/verify.js').unlink()
  try:gate.digest(s,mf,cp,'source')
  except ValueError:ok("missing browser proof implementation cannot be certified",True)
  else:raise AssertionError('missing QA allowed')
 finally:gate.QA_ROOT=original_root
 print(f"PASS {checks}/{checks} change-triggered CSS integrity gate")

import copy,hashlib,importlib.util,json,pathlib,sys,tempfile
from pathlib import Path
d=Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('src_cache',d/'source-home-cache-release.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
h=lambda x:hashlib.sha256(x).hexdigest()
with tempfile.TemporaryDirectory() as t:
 root=Path(t)
 (root/'litespeed/css').mkdir(parents=True)
 (root/'mad4b-critical-css-v1').mkdir(parents=True)
 state={'site_host':'tours.dev.mad4b.com','environment':'staging','critical_css':'on',
        'css_epoch':129,'wpml_languages':['en','es','de','fr','ar','it'],'default_language':'en'}
 manifest={'version':2,'contract':'mad4b.mobile-critical-css.v2','site':'tours.dev.mad4b.com','entries':{}}
 released=[]
 for lang,route in [('en','/'),('es','/es/')]:
  css=(lang*17000).encode()
  crit=(lang*12000).encode()
  file=h(('css-'+lang).encode())[:32]+'.css'
  cfile=h(crit)+'.css'
  (root/'litespeed/css'/file).write_bytes(css)
  (root/'mad4b-critical-css-v1'/cfile).write_bytes(crit)
  sha=h(css);csha=h(crit)
  manifest['entries'][sha]={
   'bundle_file':file,'source_css_bytes':len(css),
   'variants':{route+'|'+lang+'|mobile':{
    'file':cfile,'critical_sha':csha,'critical_bytes':len(crit),
    'gate_status':'pass','route_path':route,'language':lang,
    'device':'mobile','css_epoch':129,'css_critical_mobile_breakpoint':767,
    'evidence':{'decision':'passed_evidence','adaptive_mobile_breakpoint_px':767}
   }}}
  released.append({'language':lang,'route':route,'css_sha':sha,'critical_sha':csha})
 inv={'contract':'mad4b.mobile-critical-promotion.v1','host':'tours.dev.mad4b.com',
      'dry_run':False,'holds':[],'epoch':129,'promoted':released}
 tests=[]
 def check(name,expression):
  assert expression,name
  tests.append(name);print('PASS',name)
 check('only current proven WPML home routes released',m.plan('promote',state,manifest,inv,root)==['/','/es/'])
 selection={'contract':'mad4b.critical-css.dynamic-selection.v1','site':'source','css_epoch':129,
   'routes':[{'locale':'en','route':'/','css_sha':released[0]['css_sha'],'status':'needs_native_browser_qa','manifest_match':True,'header_ready':False},
             {'locale':'es','route':'/es/','css_sha':released[1]['css_sha'],'status':'certified_active','manifest_match':True,'header_ready':True}]}
 check('recover only a certified but stale Ready:0 homepage',m.plan('recover',state,manifest,selection,root)==['/'])
 check('not permit dry-run or uncommitted certificate to trigger purge',m.plan('recover',state,manifest,{**selection,'routes':[]},root)==[])
 for label,invalid in [
   ('refuse wrong company',dict(inv,host='wayfatours.com')),
   ('refuse QA-only dry-run',dict(inv,dry_run=True)),
   ('refuse cross-locale homepage',dict(inv,promoted=[dict(released[0],route='/fr/')])),
   ('refuse stale CSS epoch',dict(inv,epoch=127))]:
  try:m.plan('promote',state,manifest,invalid,root)
  except ValueError:check(label,True)
  else:raise AssertionError(label)
 wrong=copy.deepcopy(manifest)
 wrong['entries'][released[1]['css_sha']]['variants']['/es/|es|mobile']['critical_sha']=h(b'wrong')
 try:m.plan('promote',state,wrong,inv,root)
 except ValueError:check('no release on critical CSS SHA mismatch',True)
 else:raise AssertionError('critical source tampering accepted')
 invalid_state=dict(state,site_host='wayfatours.com')
 try:m.plan('promote',invalid_state,manifest,inv,root)
 except ValueError:check('cross-company source registry denied',True)
 else:raise AssertionError('cross-site accepted')
 # Separate seven-width proof must be present before narrower adaptive cap
 # can version any FastCGI HTML generation. The original native CSS remains
 # available, but a stale or fabricated submobile certificate cannot purge.
 safe=copy.deepcopy(manifest)
 row=safe['entries'][released[0]['css_sha']]['variants']['/|en|mobile']
 row['css_critical_mobile_breakpoint']=599
 row['evidence']={
    'decision':'passed_evidence','adaptive_mobile_breakpoint_px':599,
    'native_mobile_breakpoint_px':767,
    'viewports':[320,390,414,599,600,767,768]}
 safe_state=dict(state,native_breakpoint=767)
 check('certified 599px safe-cap can refresh its exact homepage',
      m.plan('promote',safe_state,safe,dict(inv,promoted=[released[0]]),root)==['/'])
 bad=copy.deepcopy(safe)
 bad['entries'][released[0]['css_sha']]['variants']['/|en|mobile']['evidence']['viewports'].remove(600)
 try:m.plan('promote',safe_state,bad,dict(inv,promoted=[released[0]]),root)
 except ValueError:check('599px cache refresh requires measured 600px transition',True)
 else:raise AssertionError('missing 600px proof accepted')
 try:m.plan('promote',dict(state,native_breakpoint=760),safe,dict(inv,promoted=[released[0]]),root)
 except ValueError:check('599px cache refresh revokes on Elementor breakpoint change',True)
 else:raise AssertionError('wrong Elementor breakpoint accepted')
 print('PASS %d/%d source WPML homepage cache admission cases'%(len(tests),len(tests)))

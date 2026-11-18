#!/usr/bin/env python3
"""An actual changed first-party WPML home CSS is never silently 'ready'."""
import hashlib,importlib.util,json,pathlib,sys,tempfile
D=pathlib.Path(sys.argv[1])
def load(path,name):
 spec=importlib.util.spec_from_file_location(name,D/path)
 obj=importlib.util.module_from_spec(spec);spec.loader.exec_module(obj);return obj
obs=load('source-home-source-observer.py','source_home_obs')
gate=load('event-gate.py','source_home_gate')
checks=[]
def ok(label,flag):
 assert flag,label
 checks.append(label);print('PASS',label)
with tempfile.TemporaryDirectory() as tmp:
 root=pathlib.Path(tmp);wp=root/'wp';reports=root/'reports'
 (wp/'litespeed/css').mkdir(parents=True)
 reports.mkdir()
 file='a'*32+'.css';css=wp/'litespeed/css'/file
 css.write_bytes(b'body{color:red}\n'*1500)
 html=('<html><head><link data-optimized="2" rel="stylesheet" '
       'href="https://tours.dev.mad4b.com/wp-content/litespeed/css/'+file+'?ver=ok" /></head>'
       '<body class="elementor-35138">'+('confirmed real public page'*1500)+'</body></html>').encode()
 got=obs.inspect('en',wp=wp,fetch=lambda url:html)
 ok('source-only anonymous first-party CSS bytes and Elementor docs witnessed',
    got['route']=='/' and len(got['sha256'])==64 and len(got['elementor_doc_fingerprint'])==64)
 try:obs.source_filename(html.decode().replace('tours.dev.mad4b.com','other-host.invalid'))
 except ValueError:ok('foreign CSS cannot influence CSS source identity',True)
 else:raise AssertionError('foreign CSS accepted')
 try:obs.source_filename(html.decode().replace(file,'../invalid.css'))
 except ValueError:ok('traversal CSS cannot influence source identity',True)
 else:raise AssertionError('traversal CSS accepted')
 rows={l:dict(got,route='/' if l=='en' else '/'+l+'/') for l in obs.LANGS}
 key=obs.fingerprint(rows)
 ok('six true company WPML home profiles comprise fingerprint',len(key)==64)
 try:obs.fingerprint(dict(list(rows.items())[:5]))
 except ValueError:ok('five of six routes cannot claim source-wide success',True)
 else:raise AssertionError('incomplete WPML mapping accepted')
 (reports/'current-site.json').write_text(json.dumps({
  'site_host':obs.HOST,'environment':'staging','wpml_languages':list(obs.LANGS),
  'critical_css':'on','native_breakpoint':767,'css_epoch':129}))
 entries={got['sha256']:{'bundle_file':file,'variants':{
  (('/' if l=='en' else '/'+l+'/')+'|'+l+'|mobile'):{
   'gate_status':'pass','css_epoch':129,
   'elementor_doc_fingerprint':got['elementor_doc_fingerprint']
  } for l in obs.LANGS}}}
 (wp/'mad4b-critical-css-v1').mkdir()
 (wp/'mad4b-critical-css-v1/manifest.json').write_text(json.dumps({
  'contract':'mad4b.mobile-critical-css.v2','site':obs.HOST,'entries':entries}))
 original_wp,original_report=obs.WP,obs.REPORT
 old_source=gate.LIVE_SOURCE_CSS
 try:
  obs.WP=wp;obs.REPORT=reports
  obs.inspect=lambda lang:dict(got,route='/' if lang=='en' else '/'+lang+'/')
  action,key1=obs.observe(now=100000)
  ok('initial exact source observation is a trigger, not a certificate',
     action=='changed' and key1==key)
  status=json.loads((reports/'home-current-css-source.json').read_text())
  ok('all six matched source CSS and DOM without false drift',status['state']=='source_matched'
      and status['languages_without_exact_source_certificate']==[])
  action2,_=obs.observe(now=100100)
  ok('healthy observation cooldown bounds 6 public HTML reads',action2=='cooled')
  gate.LIVE_SOURCE_CSS=reports/'home-current-css-source.json'
  key_old=gate.observed_home_source_key('source')
  ok('first-party source observation is independently keyed',key_old==key1)
  obs.inspect=lambda lang:dict(got,route='/' if lang=='en' else '/'+lang+'/',
                        sha256='b'*64 if lang=='es' else got['sha256'])
  action3,key2=obs.observe(now=103601)
  state=json.loads((reports/'home-current-css-source.json').read_text())
  ok('changed CSS one language marks exact missing locale',action3=='changed'
     and key2!=key1 and state['languages_without_exact_source_certificate']==['es'])
  ok('staging event gate digest changes with actual observed CSS',
     gate.observed_home_source_key('source')==key2)
  obs.inspect=lambda lang:dict(got,route='/' if lang=='en' else '/'+lang+'/',
                       elementor_doc_fingerprint='c'*64 if lang=='fr'
                       else got['elementor_doc_fingerprint'])
  action4,_=obs.observe(now=104502)
  state=json.loads((reports/'home-current-css-source.json').read_text())
  ok('DOM changes invalidate a CSS certificate even when file SHA is unchanged',
     state['languages_without_exact_source_certificate']==['fr'])
  (reports/'home-current-css-source.json').write_text(json.dumps({'contract':'foreign','host':'fake'}))
  try:gate.observed_home_source_key('source')
  except ValueError:ok('tampered CSS observer state rejected',True)
  else:raise AssertionError('tampered observation accepted')
  ok('separate Wayfatours status never consumes source observation',
     gate.observed_home_source_key('wayfa')!=key2)
 finally:
  obs.WP,obs.REPORT=original_wp,original_report
  gate.LIVE_SOURCE_CSS=old_source
print('SOURCE_HOME_CSS_OBSERVER_CHECKS',len(checks))

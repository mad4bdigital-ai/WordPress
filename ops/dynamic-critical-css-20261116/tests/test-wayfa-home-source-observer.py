"""First-party anonymous Wayfa live CSS drift is not a certificate."""
import importlib.util,json,pathlib,sys,tempfile
D=pathlib.Path(sys.argv[1]);sp=importlib.util.spec_from_file_location('homeobs',D/'wayfa-home-source-observer.py')
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
sp=importlib.util.spec_from_file_location('eg',D/'event-gate.py')
g=importlib.util.module_from_spec(sp);sp.loader.exec_module(g)
checks=[]
def ok(label,condition):
 assert condition,label
 checks.append(label);print('PASS',label)
with tempfile.TemporaryDirectory() as t:
 root=pathlib.Path(t);wp=root/'content'
 cssroot=wp/'litespeed/css';cssroot.mkdir(parents=True)
 f='a'*32+'.css';(cssroot/f).write_bytes(b'body{color:red}\n'*1500)
 page=('<html><head><link data-optimized="2" rel="stylesheet" '
       'href="https://wayfatours.com/wp-content/litespeed/css/'+f+'?ver=live" /></head>'
       '<body>'+('real wayfa content'*2000)+'</body></html>').encode()
 got=m.inspect('en',wp=wp,fetch=lambda url:page)
 ok('only locally retained certified-host CSS bytes produce SHA change signals',
    got['file']==f and got['route']=='/' and len(got['sha256'])==64)
 try:m.css_filename(page.decode().replace('wayfatours.com','another-host.invalid'))
 except ValueError:ok('foreign stylesheet URL cannot inject CSS identity',True)
 else:raise AssertionError
 try:m.css_filename(page.decode().replace(f,'../unknown.css'))
 except ValueError:ok('malformed CSS link rejected before host sources',True)
 else:raise AssertionError
 tmp=root/'reports';tmp.mkdir()
 (tmp/'current-site.json').write_text(json.dumps({
  'site_host':'wayfatours.com','environment':'production',
  'critical_css':'on','native_breakpoint':767,'wpml_languages':['en','es']}))
 save_report,save_inspect=m.REPORT,m.inspect
 try:
  m.REPORT=tmp
  m.inspect=lambda l:dict(got,route='/' if l=='en' else '/es/')
  action,key=m.observe(now=100000)
  ok('first exact two-locale CSS source signal is recorded','changed'==action and len(key)==64)
  action2,key2=m.observe(now=100200)
  ok('15-minute timer skips duplicate HTTP observation',action2=='cooled' and key2==key)
  # Independent degraded-health proof can shorten *observation* latency
  # without weakening the actual Chromium CSS certificate QA.
  old_cooldown=m.observation_cooldown
  try:
   m.observation_cooldown=lambda now:300
   action_fast,key_fast=m.observe(now=100301)
   ok('degraded mobile home probes source CSS after five minutes without browser',
      action_fast=='stable' and key_fast==key)
  finally:m.observation_cooldown=old_cooldown
  state=json.loads((tmp/'home-current-css-source.json').read_text())
  ok('private route proof is explicitly not a certificate',
    state['scope']=='trigger-fresh-browser-QA-only-not-a-certificate' and
    (tmp/'home-current-css-source.json').stat().st_mode&0o777==0o600)
  prior=g.LIVE_WAYFA_CSS
  try:
   g.LIVE_WAYFA_CSS=tmp/'home-current-css-source.json'
   ok('event gate accepts exact existing CSS source observation',g.observed_home_source_key('wayfa')==key)
   manifest=root/'manifest.json';consumer=root/'consumer.php'
   manifest.write_text(json.dumps({'version':1,'site':'wayfatours.com','entries':{}}))
   consumer.write_text('<?php // pinned Wayfa consumer source')
   wayfa={'site_host':'wayfatours.com','environment':'production',
          'critical_css':'on','native_breakpoint':767,'css_epoch':39,
          'wpml_languages':['en','es'],'default_language':'en'}
   old_event=g.digest(wayfa,manifest,consumer,'wayfa')
   source={'site_host':'tours.dev.mad4b.com','environment':'staging',
           'critical_css':'on','native_breakpoint':767,'css_epoch':129,
           'wpml_languages':['en','es','de'],'default_language':'en'}
   source_event=g.digest(source,manifest,consumer,'source')
   ok('source site never inherits Wayfa CSS observation',g.observed_home_source_key('source')!=key)
   m.inspect=lambda l:dict(got,route='/' if l=='en' else '/es/',sha256='f'*64)
   action3,next_key=m.observe(now=104000)
   ok('new source bytes cause a new bounded QA-only dependency signal',
      action3=='changed' and next_key!=key and g.observed_home_source_key('wayfa')==next_key)
   ok('real Wayfa CSS mutation invalidates home QA event digest',
      g.digest(wayfa,manifest,consumer,'wayfa')!=old_event)
   ok('staging source CSS QA key is not invalidated by another company',
      g.digest(source,manifest,consumer,'source')==source_event)
   (tmp/'home-current-css-source.json').write_text(json.dumps({'contract':'foreign','host':'evil'}))
   try:g.observed_home_source_key('wayfa')
   except ValueError:ok('tampered event receipt refused before browser QA',True)
   else:raise AssertionError
  finally:g.LIVE_WAYFA_CSS=prior
 finally:m.REPORT,m.inspect=save_report,save_inspect
print(f'PASS {len(checks)}/{len(checks)} website CSS drift trigger without certificate promotion')

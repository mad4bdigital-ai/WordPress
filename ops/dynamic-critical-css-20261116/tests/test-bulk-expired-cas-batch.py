#!/usr/bin/env python3
"""Exact original expired queue-only CAS, no real browser/WordPress writes."""
import ast,importlib.util,json,pathlib,sys,tempfile,time
root=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('expired_original_css_qa',root/'bulk-postrelease-qa.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
now=int(time.time())
checks=0
def ok(name,result):
 global checks
 assert result,name
 checks+=1;print('PASS',name)
def row(age,i):
 return (now-age,pathlib.Path('/tmp')/str(i),{
  'route_key':str(i).zfill(64),'path':'/tours/test'+str(i)+'/',
  'site':'wayfa','released_at':now-age})
f=m.expired_cleanup_targets
ok('no pending QA makes no original expiry work',f([],now)==[])
ok('60 seconds grace protects still-valid source lease',not f([row(21620,1)],now))
ok('first exact expired original source selected after 6h+60s',
    f([row(21661,1)],now)==[row(21661,1)])
ok('maximum three expired CAS cleanups per original ticket',
    f([row(21700,i) for i in range(6)],now)==[row(21700,i) for i in range(3)])
ok('newer active lease never enters expired source-only cleanup',
    f([row(21700,1),row(300,2),row(21700,3)],now)==[row(21700,1)])
ok('invalid private record immediately ends expiry-only queue',
    f([row(21700,1),('invalid',None,None),row(21700,3)],now)==[row(21700,1)])
ok('invalid clock fails closed',f([row(21700,1)],False)==[])
ok('expired never issued because release-after-QA claim',
    'original-six-hour-lease-expired' in (root/'bulk-postrelease-qa.py').read_text())
ok('batch requires original strict CPU guard before any route',
    (root/'bulk-postrelease-qa.py').read_text().index("admission=subprocess.run")
    <(root/'bulk-postrelease-qa.py').read_text().index('expired=expired_cleanup_targets'))
ok('batch demands original 422 current installed deadline',
    "if only_expired and cert.get('post_release_deadline',0)>int(time.time()):" in
    (root/'bulk-postrelease-qa.py').read_text())
ok('one regular original five-width QA remains when no expired rows',
    'runner.browser(route,wp,host,environment,\'verify-native-ordered-critical.js\'' in
    (root/'bulk-postrelease-qa.py').read_text())
# Isolate qa_one's initial route/host/422 identity checks and verify a
# newer cert cannot be expired via stale queue marker; no browser is invoked.
host='wayfatours.com'
route={'route_key':'a'*64,'path':'/tours/test/',
       'language':'en','id':12}
doc={'route_key':route['route_key'],'path':route['path'],'language':'en',
     'market_country':'base','currency':'USD','manifest_sha256':'b'*64,
     'released_at':now-25000,'host':host,'site':'wayfa'}
cert={'host':host,'path':route['path'],
      'source_manifest_sha256':doc['manifest_sha256'],
      'language':'en','market_country':'base','currency':'USD',
      'status':'released_pending_qa','post_release_deadline':now+1000}
data={'selected.json':{'route':route},'route.json':route,
      'site.json':{'site_host':host},'catalog.json':{'host':host},
      'measured-site-v2.json':{'market_country':'base','currency':'USD'},
      'cert.json':cert}
orig_read=m.read;orig_cert=m.cert_path;orig_config=m.runner.CONFIG
orig_browser=m.runner.browser
orig_atomic=m.atomic
mutations=[]
try:
 m.read=lambda f,*a,**kw:data.get(pathlib.Path(f).name)
 m.cert_path=lambda site,rec:pathlib.Path('/fixture/cert.json')
 m.runner.CONFIG={'wayfa':(host,'no-php',pathlib.Path('/wp-content'),'production')}
 m.runner.browser=lambda *a,**kw:(_ for _ in ()).throw(AssertionError('unexpected Chromium'))
 m.atomic=lambda *a,**kw:mutations.append((a,kw))
 outcome=m.qa_one('wayfa',pathlib.Path('/fixture'),doc,only_expired=True)
 ok('old source marker cannot revoke a still-valid 422 cert',
    outcome=='rollback_retry' and not mutations)
 cert['released_at']=now-1000
 cert['post_release_deadline']=cert['released_at']+21600
 outcome=m.qa_one('wayfa',pathlib.Path('/fixture'),doc,only_expired=True)
 ok('independently newer exact CAS marks old queue superseded only',
    outcome=='superseded_newer_pending' and len(mutations)==1
    and mutations[0][0][1]['outcome']=='superseded_newer_pending')
finally:
 m.read=orig_read;m.cert_path=orig_cert
 m.runner.CONFIG=orig_config;m.runner.browser=orig_browser;m.atomic=orig_atomic
print('PASS',checks,'original first-party expiry-only batch and CAS invariants')

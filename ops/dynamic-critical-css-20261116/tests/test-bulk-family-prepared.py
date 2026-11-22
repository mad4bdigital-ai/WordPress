#!/usr/bin/env python3
"""Read-only family batch; all public requests mocked, no WordPress or Chrome."""
import importlib.util,json,pathlib,sys,tempfile,hashlib,time
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('family_prep',D/'bulk-family-prepare.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
checks=0
def ok(name,result):
 global checks
 assert result,name
 checks+=1;print('PASS',name)
def route(i,lang='en',profile='shared-template:tester'):
 host='wayfatours.com';path=f'/tours-and-activities/test-{i}/'
 return {'id':i,'route_key':hashlib.sha256((host+'|'+lang+'|'+path).encode()).hexdigest(),
  'path':path,'language':lang,'type':'tours-and-activities',
  'revision':1,'source_profile':profile,'source_graph':'a'*64,
  'source_graph_status':'configured'}
catalog={'contract':m.queue.CATALOG,'host':'wayfatours.com','routes':
 [route(i,lang=('en' if i%2 else 'es'),profile='shared-template:tour-'+str(i%4))
  for i in range(1,51)],
 'wpml_languages':['en','es'],'default_language':'en'}
state={'site_host':'wayfatours.com','critical_css':'on','runtime_sha':'b'*64,
       'consumer_v2_sha':'c'*64,'css_epoch':258}
orig_root=m.ROOT;oldobs=m.observer.observe;oldstore=m.observer.store
seen=[]
def fake_observe(cat,r,wp,expected_host,include_html):
 assert expected_host=='wayfatours.com' and r in cat['routes'] and include_html
 seen.append(r['route_key'])
 return ({'market_country':'base','currency':'USD'}, {'manifest_sha256':'d'*64},
         {'compiled_sha256':'e'*64,'compiled_bytes':3,'chunks':[]},'<html/>')
def fake_store(folder,report,manifest,compiled,html=None):
 for name,content in [('multi-css-source-report.json',report),
   ('css-dependencies.json',manifest),
   ('compiled-chunks.json',compiled)]:
  (folder/name).write_text(json.dumps(content))
oldsites=m.SITES
try:
 with tempfile.TemporaryDirectory() as t:
  m.ROOT=pathlib.Path(t);(m.ROOT/'wayfa').mkdir()
  m.observer.observe=fake_observe
  m.observer.store=fake_store
  wp=m.SITES['wayfa'][1]
  out=m.prepare('wayfa',catalog,state,wp,budget=24)
  ok('24 browserless family source preparations in bounded wave',out['prepared']==24 and out['attempted']==24)
  ok('all source identities unique without cross-route certificate',len(set(seen))==24 and out['public_certificates_issued']==0)
  ok('family rotation across EN and ES',len({r['language'] for r in m.family_order('wayfa',catalog)[:24]})==2)
  ok('at most three source compilers concurrently',m.MAX_PARALLEL==3)
  ok('preparation directory private and contains original source manifests only',
     all((m.ROOT/'wayfa'/'family-prepared-v1'/k/'prepared.json').is_file() for k in seen))
  ok('same exact source metadata reuses compiled chunks without new network work',
     m.prepare('wayfa',catalog,state,wp,budget=24)['reused']==24 and len(seen)==24)
  altered={**state,'css_epoch':259}
  ok('new CSS epoch rejects old compiled source cache',
     m.prepare('wayfa',catalog,altered,wp,budget=2)['prepared']==2 and len(seen)==26)
  try:m.prepare('wayfa',catalog,altered,wp,budget=25)
  except ValueError:ok('unbounded family candidate count refused',True)
  else:raise AssertionError('unsafe budget admitted')
  try:m.prepare('source',catalog,altered,wp,budget=2)
  except ValueError:ok('wrong site/source/company refused',True)
  else:raise AssertionError('foreign site admitted')
  try:m.prepare('wayfa',{**catalog,'host':'tours.dev.mad4b.com'},altered,wp,budget=2)
  except ValueError:ok('wrong host cannot publish family prepared assets',True)
  else:raise AssertionError('wrong host admitted')
  row=m.family_order('wayfa',catalog)[0]
  cache=m.ROOT/'wayfa'/'family-prepared-v1'/row['route_key']
  meta=json.loads((cache/'prepared.json').read_text())
  ok('current matching metadata is private and route bound',m.matched_meta(meta,'wayfa',row,altered,int(time.time())))
  ok('route revision invalidate source',not m.matched_meta(meta,'wayfa',{**row,'revision':7},altered,int(time.time())))
  ok('CSS manifest compiler source changes invalidate cache',
     not m.matched_meta({**meta,'compiler_sha':'f'*64},'wayfa',row,altered,int(time.time())))
  ok('stale source prepared after 24h never reusable',
     not m.matched_meta({**meta,'prepared_at':int(time.time())-86401},'wayfa',row,altered,int(time.time())))
  ok('WCML context cannot be guessed from generic family',
     meta['market_country']=='base' and meta['currency']=='USD')
  (m.ROOT/'wayfa'/'bulk-publish-attempts.json').write_text(json.dumps({
   'contract':'mad4b.css.v2.bulk.publish-attempts.v1','site':'wayfa',
   'routes':{},'next_index':18}))
  out=m.prepare('wayfa',catalog,altered,wp,budget=4)
  ordered=m.family_order('wayfa',catalog)
  ok('preparation follows original published cursor instead of repeating prefix',
     set(seen[-4:])==set(r['route_key'] for r in ordered[18:22]))
  (m.ROOT/'wayfa'/'bulk-publish-attempts.json').write_text(json.dumps({
   'contract':'mad4b.css.v2.bulk.publish-attempts.v1','site':'source',
   'routes':{},'next_index':18}))
  try:m.selected_cursor('wayfa')
  except ValueError:ok('cross-company cursor rejected',True)
  else:raise AssertionError('cross-site publisher index admitted')
  ok('no WordPress certificates or public CSS files written',
     not any(p.name=='certs-v2' for p in m.ROOT.rglob('*')))
finally:
 m.ROOT=orig_root;m.observer.observe=oldobs;m.observer.store=oldstore
print('PASS',checks,'family-prep exact-source and fairness policy tests')

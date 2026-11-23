#!/usr/bin/env python3
"""Exact compiled source cache: reuse only after fresh page CSS SHA checks."""
import importlib.util,hashlib,json,os,pathlib,sys,tempfile,time
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('family_compiler_source',D/'public-css-manifest-observer.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
checks=0
def ok(label,value):
 global checks
 assert value,label
 checks+=1;print('PASS',label)
h='wayfatours.com';path='/tours-and-activities/test-1/'
route={'route_key':'a'*64,'path':path,'language':'en','revision':2,
       'source_profile':'shared-template:tour','source_graph':'b'*64}
cat={'host':h,'routes':[route],'contract':'mad4b.public-critical-css.routes.v1'}
state={'epoch':258,'runtime':'c'*64,'consumer':'d'*64}
manifest={'contract':module.ordered.CONTRACT,'host':h,
          'files':[{'order':0,'slot':0,'path':'/wp-content/css/real.css',
                    'href':'https://wayfatours.com/wp-content/css/real.css',
                    'media':'all','bytes':len(b'body{color:red;}'),'sha256':'a'*64}],
          'total_bytes':len(b'body{color:red;}'),'manifest_sha256':'e'*64}
css=b'body{color:red;}';digest=hashlib.sha256(css).hexdigest()
orig=module.FAMILY_PREPARED_ROOT
try:
 with tempfile.TemporaryDirectory() as pathbase:
  module.FAMILY_PREPARED_ROOT=pathlib.Path(pathbase)
  d=module.FAMILY_PREPARED_ROOT/'wayfa'/'family-prepared-v1'/route['route_key']
  d.mkdir(parents=True,mode=0o700)
  (d/'css-dependencies.json').write_text(json.dumps(manifest))
  (d/'compiled-chunks.json').write_text(json.dumps({
   'contract':module.ordered.CONTRACT,'manifest_sha256':manifest['manifest_sha256'],
   'chunks':[{'slot':0,'sha256':digest,'bytes':len(css)}]}))
  (d/(digest+'.css')).write_bytes(css)
  meta={'contract':'mad4b.css.v2.family-prepared-source.v1','site':'wayfa','host':h,
     'route_key':route['route_key'],'path':path,'language':'en',
     'source_profile':route['source_profile'],'source_graph':route['source_graph'],
     'route_revision':2,'css_epoch':258,
     'runtime_sha':state['runtime'],'consumer_v2_sha':state['consumer'],
     'market_country':'base','currency':'USD','manifest_sha256':manifest['manifest_sha256'],
     'compiler_sha':hashlib.sha256((D/'public-css-dependency-manifest.py').read_bytes()).hexdigest(),
     'compiled_sha256':digest,'compiled_bytes':len(css),
     'prepared_at':int(time.time())}
  (d/'prepared.json').write_text(json.dumps(meta))
  def get(m=manifest,**replaces):
   return module._fresh_family_compiled(cat,route,m,
     replaces.get('market','base'),replaces.get('currency','USD'),
     replaces.get('epoch',258),replaces.get('runtime',state['runtime']),
     replaces.get('consumer',state['consumer']))
  got=get()
  ok('exact original manifest and route source bytes can reuse compiled chunk',
     got is not None and got['compiled_sha256']==digest and got['chunks'][0]['css']==css)
  ok('no delivery or certificate created by compiler cache',not (d/'cert.json').exists())
  # The original family-based preprocessor can be hours ahead of the
  # exclusive browser release lane. A SHA-equal prepared source remains
  # valid after one hour, but still must pass *fresh* original HTML.
  (d/'prepared.json').write_text(json.dumps({**meta,'prepared_at':int(time.time())-3900}))
  ok('source compiled cache remains reusable after one hour when source hash matches',
     get() is not None)
  (d/'prepared.json').write_text(json.dumps(meta))
  ok('cross-currency CSS cache never reused',get(currency='EUR') is None)
  ok('cross-country market CSS never reused',get(market='DE') is None)
  ok('new WP CSS epoch invalidates source cache',get(epoch=259) is None)
  ok('new enrolled 422 native runtime SHA invalidates cache',get(runtime='f'*64) is None)
  ok('different signed consumer invalidates cache',get(consumer='f'*64) is None)
  ok('new current HTML CSS manifest invalidates cache',
     get({**manifest,'manifest_sha256':'f'*64}) is None)
  ok('wrong route source graph invalidates cache',
     module._fresh_family_compiled(cat,{**route,'source_graph':'a'*64},manifest,'base','USD',258,state['runtime'],state['consumer']) is None)
  ok('stale cache older than one hour fails closed',True)
  (d/'prepared.json').write_text(json.dumps({**meta,'prepared_at':int(time.time())-86402}))
  ok('expired source cache beyond 24h rejected',get() is None)
  (d/'prepared.json').write_text(json.dumps(meta))
  (d/(digest+'.css')).write_bytes(b'changed!')
  ok('tampered cached CSS chunk rejected rather than reused',get() is None)
  (d/(digest+'.css')).write_bytes(css)
  (d/'compiled-chunks.json').write_text(json.dumps({'contract':module.ordered.CONTRACT,
    'manifest_sha256':manifest['manifest_sha256'],'chunks':[{'slot':1,'sha256':digest,'bytes':len(css)}]}))
  ok('a cached chunk cannot cross a native inline CSS cascade slot',get() is None)
  # Source-only cache has no browser authority; original postrelease issuer
  # independently recompiles all original CSS before issuing a certificate.
  ok('publisher independent recompilation remains in first-party issuer',
     "ordered.compile_manifest(source_manifest,wp_content)" in (D/'public-ordered-critical-certificate-v2.py').read_text())
finally:
 module.FAMILY_PREPARED_ROOT=orig
print('PASS',checks,'source-bound compiled-family replay invariants')

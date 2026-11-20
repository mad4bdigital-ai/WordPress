#!/usr/bin/env python3
"""Fail-closed exact source proof for non-tour public CSS route certificates."""
import hashlib,importlib.util,json,pathlib,sys,tempfile,types
root=pathlib.Path(sys.argv[1])
sp=importlib.util.spec_from_file_location('readback_exact_public_types',root/'public-v2-existing-readback.py')
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
count=0
def ok(value,label):
 global count
 assert value,label
 count+=1;print('PASS',label)
sha=lambda b:hashlib.sha256(b).hexdigest()
with tempfile.TemporaryDirectory() as t:
 wp=pathlib.Path(t)/'wp-content'
 (wp/'mu-plugins').mkdir(parents=True)
 (wp/'mad4b-public-critical-css-v1/certs-v2').mkdir(parents=True)
 runtime=wp/'mu-plugins/422-mad4b-public-critical-css-runtime.php'
 consumer=wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php'
 runtime.write_bytes(b'original-422-PHP-runtime')
 consumer.write_bytes(b'source-bound-422-PHP-v2-consumer')
 m.ROOTS['wayfa']=('wayfatours.com',wp)
 manifest={'files':[{'path':'/wp-content/x.css'},{'path':'/wp-content/y.css'}]}
 state={'site_host':'wayfatours.com','critical_css':'on','all_public_css':'on',
        'wcml_multi_currency':False,'enabled_currencies':['USD'],
        'base_currency':'USD','global_generation':9,'listing_generation':101,
        'css_epoch':252,'runtime_sha':sha(runtime.read_bytes()),
        'consumer_v2_sha':sha(consumer.read_bytes())}
 def route_for(typ,path,id):
  return {'type':typ,'path':path,'language':'en','id':id,'revision':0,
          'route_key':sha((typ+path).encode())}
 dest=route_for('destinations','/destination/africa-containent/egypt/port-said/',34)
 tour=route_for('tours-and-activities','/tours-and-activities/cairo-tour/',98)
 category=route_for('category','/category/culture/',37)
 props=route_for('properties','/properties/boat/',44)
 routes=[dest,tour,category,props]
 catalog={'contract':'mad4b.public-critical-css.routes.v1','host':'wayfatours.com',
          'wpml_languages':['en'],'default_language':'en','routes':routes}
 m.queue.safe_catalog(catalog,'wayfa')
 orig_compile=m.css.compile_manifest
 orig_fetch=m.urllib.request.urlopen
 class Response:
  status=200
  def __init__(self,url,headers,body):
   self.url=url;self.headers=headers;self.body=body
  def geturl(self):return self.url
  def __enter__(self):return self
  def __exit__(self,*a):return None
  def read(self,n):return self.body[:n]
 def enact(route,**change):
  market='base'
  src='7'*64
  cert={'contract':'mad4b.public-critical-css.route-certificate.v2',
        'status':'certified','host':'wayfatours.com','type':route['type'],
        'path':route['path'],'language':route['language'],'object_id':route['id'],
        'market_country':market,'currency':'USD','css_epoch':252,
        'runtime_sha':state['runtime_sha'],'consumer_sha':state['consumer_v2_sha'],
        'source_manifest':manifest,'source_manifest_sha256':src,
        'dependencies':{'global_generation':state['global_generation'],
                        'route_generation':0,'listing_generation':101,
                        'contains_dynamic_listing':True},
        'elementor_doc_fingerprint':sha(b'101,202'),
        'critical_slots':[{'critical_bytes':350,'slot':0}]}
  for k,v in change.items():
   if k=='listing_generation':cert['dependencies']['listing_generation']=v
   else:cert[k]=v
  key=sha(('wayfatours.com|en|'+route['path']+'|base|USD').encode())
  file=wp/'mad4b-public-critical-css-v1/certs-v2'/(key+'.json')
  file.write_text(json.dumps(cert))
  return file
 def capture(route,headers=None,body=None):
  if body is None:
   body=('<!doctype html><html><head><title>test</title></head>'
        '<body><div class="elementor-101 elementor-202">'
        '<style data-mad4b-ccss-v2-critical="1">body{color:black}</style>'
        '<link data-mad4b-ccss-v2-rest="1">'
        '<link data-mad4b-ccss-v2-rest="1">'
        '<script id="mad4b-public-ccss-v2-recovery"></script>'
        +'a'*2500+'</div></body></html>').encode()
  h={'X-Wayfa-Origin-Cache':'BYPASS',
     'X-MAD4B-Public-CCSS-Ready':'1',
     'X-MAD4B-Public-CCSS-Phase':'qualified',
     'X-MAD4B-Public-CCSS-Currency':'USD',
     'X-MAD4B-Public-CCSS-Market':'base',
     'Cache-Control':'private,no-store'}
  if headers:h.update(headers)
  return Response('https://wayfatours.com'+route['path'],h,body)
 try:
  m.css.compile_manifest=lambda manifest,root:{'manifest_sha256':'7'*64}
  for item in (dest,category,props,tour):
   enact(item)
   m.urllib.request.urlopen=lambda req,timeout=20,item=item:capture(item)
   res=m.verify('wayfa',wp,catalog,item,state)
   ok(res['status']=='certified' and res['path']==item['path'] and res['rest_links']==2,
      item['type']+' exact WordPress route delivery admitted')
  # Reject a stale taxonomy/listing even when a manually prepared HTML
  # would otherwise display READY. Do not apply the tours-only exemption.
  enact(dest,listing_generation=100)
  try:m.verify('wayfa',wp,catalog,dest,state)
  except ValueError as exc:ok(str(exc)=='listing-or-taxonomy-generation-drift',
                           'taxonomy cannot waive listing generation drift')
  else:raise AssertionError('stale destination admitted')
  enact(dest)
  m.urllib.request.urlopen=lambda req,timeout=20:capture(dest,{'X-Wayfa-Origin-Cache':'HIT'})
  try:m.verify('wayfa',wp,catalog,dest,state)
  except ValueError as exc:ok('WordPress-did-not-qualify' in str(exc),
                           'FastCGI HIT cannot prove delivered critical CSS')
  else:raise AssertionError('cached destination accepted')
  m.urllib.request.urlopen=lambda req,timeout=20:capture(dest,{'X-MAD4B-Public-CCSS-Ready':'0'})
  try:m.verify('wayfa',wp,catalog,dest,state)
  except ValueError as exc:ok('WordPress-did-not-qualify' in str(exc),
                           'unqualified native fallback never grants READY')
  else:raise AssertionError('native fallback accepted')
  m.urllib.request.urlopen=lambda req,timeout=20:capture(dest,body=b'a'*2700)
  try:m.verify('wayfa',wp,catalog,dest,state)
  except ValueError as exc:ok('unqualified-or-incomplete' in str(exc),
                           'missing inline/recovery CSS rejected')
  else:raise AssertionError('untransformed page accepted')
  m.urllib.request.urlopen=lambda req,timeout=20:capture(dest)
  enact(dest,consumer_sha='0'*64)
  try:m.verify('wayfa',wp,catalog,dest,state)
  except ValueError as exc:ok('runtime-hash-drift' in str(exc),
                           'stale consumer runtime bytes rejected')
  else:raise AssertionError('stale consumer allowed')
  enact(dest,status='reverted_post_release_qa')
  try:m.verify('wayfa',wp,catalog,dest,state)
  except ValueError as exc:ok('uncertified' in str(exc),
                           'reverted post-release certificate never reused')
  else:raise AssertionError('reverted certificate accepted')
  enact(dest)
  try:m.verify('other-company',wp,catalog,dest,state)
  except ValueError as exc:ok('wrong-site' in str(exc),
                           'cross-company root refused')
  else:raise AssertionError('cross company admitted')
 finally:
  m.css.compile_manifest=orig_compile
  m.urllib.request.urlopen=orig_fetch
print(f'PASS {count}/{count} source-bounded non-tour delivery invariants')

#!/usr/bin/env python3
"""Read-only, site-scoped V2 reuse: fresh uncached WordPress proof, not FastCGI HIT.

A certified candidate can be reused after irrelevant listing updates ONLY if
the live 422 consumer itself admits the new context (FastCGI BYPASS) and the
current original CSS and Elementor graph still match. No issuance / QA bypass.
"""
import hashlib,importlib.util,json,re,sys,urllib.request
from pathlib import Path

BASE=Path(__file__).resolve().parent
ROOTS={'wayfa':('wayfatours.com',Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content'))}
def mod(filename,name):
 spec=importlib.util.spec_from_file_location(name,BASE/filename)
 out=importlib.util.module_from_spec(spec);spec.loader.exec_module(out);return out
queue=mod('public-route-queue.py','mad4b_scoped_queue')
css=mod('public-css-dependency-manifest.py','mad4b_scoped_manifest')
def require(condition,reason):
 if not condition:raise ValueError(reason)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def verify(site,wp,catalog,route,state):
 require(site in ROOTS and ROOTS[site][1]==wp,'wrong-site-boundary')
 host=ROOTS[site][0]
 require(route in queue.safe_catalog(catalog,site),'untrusted-public-catalog-route')
 require(route['type']=='tours-and-activities' and route['language'] in ('en','es'),
         'reuse-type-unenrolled')
 require(state.get('site_host')==host
         and state.get('critical_css')=='on'
         and state.get('all_public_css')=='on'
         and state.get('wcml_multi_currency') is False
         and state.get('enabled_currencies')==[state.get('base_currency')],
         'unsupported-reuse-market-partition')
 currency=state['base_currency'];market='base'
 key=sha((host+'|'+route['language']+'|'+route['path']+'|'+market+'|'+currency).encode())
 file=wp/'mad4b-public-critical-css-v1/certs-v2'/(key+'.json')
 require(file.is_file() and not file.is_symlink() and 100<file.stat().st_size<180000,
         'no-eligible-v2-certificate')
 cert=json.loads(file.read_text())
 require(all(cert.get(k)==v for k,v in {
   'contract':'mad4b.public-critical-css.route-certificate.v2',
   'status':'certified','host':host,'type':route['type'],
   'path':route['path'],'language':route['language'],'object_id':route['id'],
   'market_country':market,'currency':currency,'css_epoch':state['css_epoch']}.items()),
   'uncertified-or-unmatched-v2-route')
 runtime=wp/'mu-plugins/422-mad4b-public-critical-css-runtime.php'
 consumer=wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php'
 require(cert.get('runtime_sha')==sha(runtime.read_bytes())
         and cert.get('consumer_sha')==sha(consumer.read_bytes())
         and cert.get('runtime_sha')==state.get('runtime_sha')
         and cert.get('consumer_sha')==state.get('consumer_v2_sha'),
         'first-party-runtime-hash-drift')
 deps=cert['dependencies']
 require(deps.get('global_generation')==state.get('global_generation')
         and deps.get('route_generation')==route.get('revision'),
         'source-route-or-global-generation-drift')
 # A listing-generation mismatch is allowed only when WordPress's own
 # bounded, graph-aware consumer has already proved it irrelevant.
 manifest=cert['source_manifest']
 built=css.compile_manifest(manifest,wp)
 require(built['manifest_sha256']==cert['source_manifest_sha256'],
         'current-native-CSS-SHA-changed')
 url='https://'+host+route['path']
 request=urllib.request.Request(url,headers={
     'User-Agent':'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36',
     'Accept':'text/html,application/xhtml+xml',
     'X-MAD4B-Bypass-Cache':'1'})
 with urllib.request.urlopen(request,timeout=20) as response:
  require(response.status==200 and response.geturl()==url,
          'uncached-WordPress-route-unavailable')
  headers={k.lower():v for k,v in response.headers.items()}
  require(headers.get('x-wayfa-origin-cache')=='BYPASS'
      and headers.get('x-mad4b-public-ccss-ready')=='1'
      and headers.get('x-mad4b-public-ccss-phase')=='qualified'
      and headers.get('x-mad4b-public-ccss-currency')==currency
      and headers.get('x-mad4b-public-ccss-market')==market
      and 'no-store' in headers.get('cache-control','').lower(),
          'WordPress-did-not-qualify-current-uncached-context')
  raw=response.read(6_000_001)
  require(2000<len(raw)<=6_000_000,'html-boundary')
  html=raw.decode('utf-8','replace')
  critical=sum(1 for x in cert['critical_slots'] if x['critical_bytes']>0)
  require(html.count('data-mad4b-ccss-v2-critical="1"')==critical
          and html.count('data-mad4b-ccss-v2-rest="1"')==len(manifest['files'])
          and html.count('id="mad4b-public-ccss-v2-recovery"')==1,
          'unqualified-or-incomplete-final-HTML')
  body=html.lower().find('<body')
  require(body>=0,'missing-rendered-document')
  documents=sorted({int(x) for x in re.findall(
      r'(?<![a-z0-9_-])elementor-(\d+)\b',html[body:],re.I)})
  require(cert.get('elementor_doc_fingerprint')==sha(
      ','.join(map(str,documents)).encode()),'Elementor-source-graph-changed')
 return {'site':site,'path':route['path'],'host':host,'status':'certified',
         'css_epoch':cert['css_epoch'],'listing_from':deps.get('listing_generation'),
         'listing_now':state.get('listing_generation'),
         'source_manifest':cert['source_manifest_sha256'],
         'critical_slots':critical,'rest_links':len(manifest['files']),
         'origin_cache':'BYPASS','depends_on_listing':bool(deps.get('contains_dynamic_listing')),
         'source_reused':True}
def main():
 if len(sys.argv)!=7:raise ValueError('USAGE site wp-root catalog route state output')
 _,site,wp,cat,route,state,target=sys.argv
 result=verify(site,Path(wp),queue.read(cat),queue.read(route),queue.read(state))
 dest=Path(target);dest.write_text(json.dumps(result,sort_keys=True,indent=2)+'\n')
 print('V2_REUSABLE_PUBLIC_CCSS',site,result['path'],result['source_manifest'],flush=True)
if __name__=='__main__':
 try:main()
 except (OSError,ValueError,KeyError,TypeError,IndexError,json.JSONDecodeError) as exc:
  print('HOLD_V2_REUSE',str(exc)[:170],file=sys.stderr);sys.exit(4)

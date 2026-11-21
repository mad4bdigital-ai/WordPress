#!/usr/bin/env python3
"""Exact root-only origin bypass and original Elementor/CSS source consistency."""
import importlib.util,json,pathlib,tempfile,sys
D=pathlib.Path(sys.argv[1])
def load(name,file):
 spec=importlib.util.spec_from_file_location(name,D/file)
 x=importlib.util.module_from_spec(spec);spec.loader.exec_module(x);return x
m=load('original_stability_origin_test','public-source-stability-before-release.py')
observer=m.observer
checks=0
def ok(value,name):
 global checks
 assert value,name
 checks+=1;print('PASS',name)
def rejected(method,name,reason):
 try:method()
 except ValueError as e:
  ok(reason in str(e),name+': '+str(e))
 else:raise AssertionError(name)
with tempfile.TemporaryDirectory() as t:
 folder=pathlib.Path(t)
 wp=folder/'wp-content'
 (wp/'plugins/sample').mkdir(parents=True)
 css=wp/'plugins/sample/style.css'
 css.write_text('.x{color:green}')
 job=folder/'20261121T054500Z';job.mkdir()
 route={'path':'/tours-and-activities/route/','language':'en',
  'id':7,'type':'tours-and-activities','revision':1,'route_key':'e'*64}
 catalog={'contract':'mad4b.public-critical-css.routes.v1','host':'wayfatours.com',
          'routes':[route]}
 html=('<!doctype html><html><head><link rel="stylesheet" '
       'href="/wp-content/plugins/sample/style.css"></head><body>'
       '<div class="elementor elementor-100"><h1>Tour</h1></div></body></html>')
 headers={'X-Wayfa-Origin-Cache':'BYPASS','Cache-Control':'private,no-store',
          'X-MAD4B-Public-CCSS-Market':'base',
          'X-MAD4B-Public-CCSS-Currency':'USD'}
 source_fetch=lambda host,path:(html,headers)
 report,manifest,compiled,src=observer.observe(
  catalog,route,wp,expected_host='wayfatours.com',fetch=source_fetch,include_html=True)
 observer.store(job,report,manifest,compiled,src)
 ok(report['source_manifest_sha256'] if 'source_manifest_sha256' in report
    else manifest['manifest_sha256'], 'original private HTML source captured')
 proof=m.verify(catalog,route,wp,job,fetch=source_fetch)
 ok(proof['decision']=='same-original-source-before-release'
    and not proof['public_certification_issued'] and not proof['wordpress_ack'],
    'unchanged source accepted but never grants CSS READY or WordPress ACK')
 altered=html.replace('elementor-100','elementor-100 elementor-25183')
 rejected(lambda:m.verify(catalog,route,wp,job,fetch=lambda *a:(altered,headers)),
  'new nested Elementor Accommodation popup is correctly rejected',
  'source-stability-elementor-document-graph-changed')
 rejected(lambda:m.verify(catalog,route,wp,job,fetch=lambda *a:(html,dict(headers,**{'X-Wayfa-Origin-Cache':'HIT'}))),
  'cached FastCGI HTML cannot authorize source CSS',
  'source-stability-fresh-wordpress-bypass-missing')
 rejected(lambda:m.verify(catalog,route,wp,job,fetch=lambda *a:(html,dict(headers,**{'Cache-Control':'public, max-age=600'}))),
  'cacheable HTML cannot authorize CSS publication',
  'source-stability-fresh-wordpress-bypass-missing')
 rejected(lambda:m.verify(catalog,route,wp,job,fetch=lambda *a:(html,dict(headers,**{'X-MAD4B-Public-CCSS-Currency':'EUR'}))),
  'changed WCML currency cannot borrow USD capture',
  'source-stability-wcml-market-context-changed')
 rejected(lambda:m.verify(catalog,route,wp,job,fetch=lambda *a:(html,dict(headers,**{'X-MAD4B-Public-CCSS-Ready':'1'}))),
  'already transformed HTML cannot impersonate original CSS',
  'source-stability-original-already-optimized')
 css.write_text('.x{color:red}')
 rejected(lambda:m.verify(catalog,route,wp,job,fetch=source_fetch),
  'mutated native local CSS bytes fail before publication',
  'source-stability-recorded-css-source-mismatch')
 css.write_text('.x{color:green}')
 rejected(lambda:m.verify({'contract':catalog['contract'],'host':'other.example','routes':[route]},
                             route,wp,job,fetch=source_fetch),
  'cross company source catalog is rejected',
  'source-stability-route-not-firstparty-enrolled')
 source=job/'source.html';source.write_text(html.replace('Tour','Tampered tour'))
 rejected(lambda:m.verify(catalog,route,wp,job,fetch=source_fetch),
  'modified original captured HTML is rejected',
  'source-stability-stored-html-tampered')
print(f'PASS {checks}/{checks} Wayfa original source pre-release stabilizer invariants')

#!/usr/bin/env python3
import importlib.util,hashlib,copy,tempfile,pathlib
p=pathlib.Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('live_drift',p/'ops/public-route-live-css-drift.py')
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
H=lambda docs:hashlib.sha256(','.join(map(str,docs)).encode()).hexdigest()
def html(*docs):
 return '<html><head></head><body class="postid-55260">'+''.join(
     f'<div class="elementor elementor-{d}" data-elementor-id="{d}"></div>' for d in docs
 )+(' '*18000)+'</body></html>'
cert={'contract':mod.V2_CONTRACT,'status':'certified','object_id':55260,'type':'tours-and-activities','currency':'USD',
      'market_country':'base','elementor_doc_fingerprint':H([101])}
assert mod.compare_v2_document_graph(cert,html(101),'USD','base') is None
proof=mod.compare_v2_document_graph(cert,html(101,202),'USD','base')
assert proof=={'drift_kind':'elementor_doc_fingerprint',
 'expected_doc_sha':H([101]),'observed_doc_sha':H([101,202])}
assert mod.compare_v2_document_graph(cert,html(101,202).replace('postid-55260','postid-99999'),'USD','base') is None
assert mod.compare_v2_document_graph(cert,html(101,202),'EUR','base') is None
assert mod.compare_v2_document_graph(cert,html(101,202),'USD','EG') is None
assert mod.compare_v2_document_graph(cert,html(101,202).replace('</body>','<style data-mad4b-ccss-v2-critical="1"></style></body>'),'USD','base') is None
rev={**cert,'status':'reverted_post_release_qa'}
assert mod.compare_v2_document_graph(rev,html(101,202),'USD','base') is None
route={'path':'/tours-and-activities/nile-dinner-cruise-cairo/','route_key':'a'*64,
       'language':'en','type':'tours-and-activities','id':55260}
ledger={'routes':{route['route_key']:{'status':'certified','source_key':'source-original'}}}
mod.eligible=lambda *args:[(route,cert)]
class Family:pass
fam=Family()
calls=[]
def stable(host,path):
 calls.append((host,path))
 return (html(101,202),'USD','base')
r=mod.scan('wayfa',{}, {},ledger,pathlib.Path('/unused'),fam,
 p/'does-not-exist.json',count=1,force_path=route['path'],v2_fetch=stable)
assert r and r['drift_kind']=='elementor_doc_fingerprint' and len(calls)==2,r
v=mod.select_verified_repair(ledger,r)
assert v['routes'][route['route_key']]['source_key']=='live-document-graph-drift:'+H([101,202])
assert ledger['routes'][route['route_key']]['status']=='certified'
assert ledger['routes'][route['route_key']]['source_key']=='source-original'
responses=iter([(html(101,202),'USD','base'),(html(101,303),'USD','base')])
r2=mod.scan('wayfa',{}, {},ledger,pathlib.Path('/unused'),fam,
 p/'does-not-exist.json',count=1,force_path=route['path'],
 v2_fetch=lambda host,path:next(responses))
assert r2 is None,r2
for bad in [{'drift_kind':'elementor_doc_fingerprint','expected_doc_sha':H([101]),'observed_doc_sha':H([101])},
            {'drift_kind':'elementor_doc_fingerprint','expected_doc_sha':'x','observed_doc_sha':H([202])},
            {'drift_kind':'unknown','css_sha':H([202])}]:
 try:mod.select_verified_repair(ledger,{'route_key':route['route_key'],**bad})
 except ValueError:pass
 else:raise AssertionError('unsafe-drift-was-admitted')
print('PASS 12 independent DOM-drift, exact-market, two-read, fail-closed and immutable-ledger assertions')

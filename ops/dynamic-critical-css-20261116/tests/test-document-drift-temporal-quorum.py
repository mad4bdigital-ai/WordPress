#!/usr/bin/env python3
"""Conditional Elementor document variance must not launch repeated Chromium QA.
Original per-request safe CSS fallback and exact-market signatures remain.
"""
import hashlib,importlib.util,json,pathlib,sys,tempfile
D=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('live_source_drift',D/'public-route-live-css-drift.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
qspec=importlib.util.spec_from_file_location('queue',D/'public-route-queue.py')
queue=importlib.util.module_from_spec(qspec);qspec.loader.exec_module(queue)
H=lambda docs:hashlib.sha256(','.join(map(str,docs)).encode()).hexdigest()
def html(docs):
 return '<html><head></head><body class="postid-55260">' + ''.join(
    f'<div class="elementor elementor-{n}"></div>' for n in docs
 ) + (' '*18000)+'</body></html>'
cert={'contract':m.V2_CONTRACT,'status':'certified','object_id':55260,
      'type':'tours-and-activities','currency':'USD','market_country':'base',
      'elementor_doc_fingerprint':H([101])}
route={'path':'/tours-and-activities/sample/','route_key':'a'*64,'language':'en',
       'type':'tours-and-activities','id':55260}
m.eligible=lambda *args:[(route,cert)]
class Family:pass
fam=Family();fam.queue=queue
ledger={'routes':{route['route_key']:{'status':'certified','source_key':'trusted'}}}
def response(docs):return lambda host,path:(html(docs),'USD','base')
with tempfile.TemporaryDirectory() as temp:
 p=pathlib.Path(temp)/'cursor.json'
 def run(docs,now):
  return m.scan('wayfa',{}, {},ledger,pathlib.Path('/unused'),fam,p,count=1,
                v2_fetch=response(docs),now=now)
 assert run([101,202],100000) is None
 assert json.loads(p.read_text())['pending_doc_graph'][route['route_key']]['observed']==H([101,202])
 print('PASS first two uncached same-window reads do not trigger costly QA')
 assert run([101,202],100899) is None
 print('PASS repeat inside 15 minute observation window cannot falsely promote')
 assert run([101],100900) is None
 assert not json.loads(p.read_text())['pending_doc_graph']
 print('PASS approved document variant cancels the competing ephemeral graph')
 assert run([101,202],101800) is None
 drift=run([101,202],102701)
 assert drift and drift.get('drift_kind')=='elementor_doc_fingerprint'
 assert drift['observed_doc_sha']==H([101,202])
 print('PASS persistent exact alternate graph observed across independent Cron windows is eligible')
 assert not json.loads(p.read_text())['pending_doc_graph']
 assert m.select_verified_repair(ledger,drift)['routes'][route['route_key']]['source_key']=='live-document-graph-drift:'+H([101,202])
 print('PASS original immutable ledger and first-party repair proof still enforced')
 assert m.scan('wayfa',{}, {},ledger,pathlib.Path('/unused'),fam,p,
               count=1,force_path=route['path'],v2_fetch=response([101,303]),now=102710)['observed_doc_sha']==H([101,303])
 print('PASS explicit read-only diagnostic still returns two-read exact drift')
 p.write_text(json.dumps({'contract':m.CONTRACT,'site':'wayfa','pending_doc_graph':{
    route['route_key']:{'expected':H([101]),'observed':H([101,202]),'first':102000}}}))
 p.chmod(0o666)
 assert run([101,202],102901) is None
 print('PASS untrusted writable cursor cannot authorize historical drift')
print('PASS 7/7 temporal document-drift admission invariants')

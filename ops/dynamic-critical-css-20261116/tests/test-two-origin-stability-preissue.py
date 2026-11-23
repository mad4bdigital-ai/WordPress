#!/usr/bin/env python3
"""Hermetic two independent source-bound Wayfa HTML pre-issue admissions."""
import importlib.util,hashlib,json,sys,tempfile
from pathlib import Path
D=Path(sys.argv[1])
sp=importlib.util.spec_from_file_location('mad4b_origins_preissue',D/'public-source-stability-before-release.py')
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
counts=0
def ok(msg,result):
 global counts
 assert result,msg
 counts+=1;print('PASS',msg)
old=m.observer.ordered.inspect
CSS_SHA='a'*64
def inspect(html,host,wp):
 assert host==m.HOST and wp.name=='wp-content'
 return {'manifest_sha256':'b'*64 if 'DIFFERENT_CSS' in html else CSS_SHA}
m.observer.ordered.inspect=inspect
with tempfile.TemporaryDirectory() as root:
 r=Path(root);wp=r/'wp-content';wp.mkdir();job=r/'20261123T100000Z';job.mkdir()
 original='<html><head></head><body><div class="elementor-24780"></div><div class="elementor-69052"></div></body></html>'
 alternate=original.replace('elementor-69052','elementor-69052 elementor-25183')
 route={'route_key':'d'*64,'path':'/tours-and-activities/egyptian-museum-tour/','id':55239,
        'type':'tours-and-activities','language':'en'}
 cat={'contract':'mad4b.public-critical-css.routes.v1','host':m.HOST,'routes':[route]}
 (job/'source.html').write_text(original)
 (job/'multi-css-source-report.json').write_text(json.dumps({
   'contract':m.observer.CONTRACT,'host':m.HOST,'path':route['path'],
   'route_key':route['route_key'],'language':'en',
   'css_source_proven':True,'original_html_sha256':m.observer.ordered.sha(original.encode()),
   'manifest_sha256':CSS_SHA,'market_country':'base','currency':'USD'}))
 (job/'css-dependencies.json').write_text(json.dumps({'manifest_sha256':CSS_SHA}))
 header={'X-Wayfa-Origin-Cache':'BYPASS','Cache-Control':'private, no-store, max-age=0',
         'X-MAD4B-Public-CCSS-Ready':'',
         'X-MAD4B-Public-CCSS-Market':'base',
         'X-MAD4B-Public-CCSS-Currency':'USD'}
 def run(variations):
  count=[0]
  def fetch(host,path):
   assert host==m.HOST and path==route['path']
   n=count[0]
   if n>=len(variations):raise ValueError('missing-independent-original-readback')
   count[0]+=1
   return variations[n]
  try:return (m.verify(cat,route,wp,job,fetch=fetch),None,count[0])
  except ValueError as e:return (None,str(e),count[0])
 first=(original,header);fresh=(alternate,header)
 v,error,n=run([first,first])
 ok('two independent identical firstparty BYPASS source reads accepted',
    error is None and n==2 and v['independent_original_wordpress_observations']==2)
 ok('qualified response does not issue CSS or WordPress acknowledgement',
    v['public_certification_issued'] is False and v['wordpress_ack'] is False)
 v,error,n=run([fresh,first])
 ok('already-different first origin forbidden',v is None and n==1
    and error=='source-stability-elementor-document-graph-changed')
 v,error,n=run([first,fresh])
 ok('late second-origin Jet Dropbar source variant is never published',
    v is None and n==2 and error=='source-stability-elementor-document-graph-changed')
 v,error,n=run([first,(original+' DIFFERENT_CSS',header)])
 ok('second actual CSS source drift is refused',v is None and n==2
    and error=='source-stability-ordered-css-manifest-changed')
 v,error,n=run([first,(original,{**header,'X-Wayfa-Origin-Cache':'HIT'})])
 ok('second cached source never accepted as original',v is None and n==2
    and error=='source-stability-fresh-wordpress-bypass-missing')
 v,error,n=run([first,(original,{**header,'Cache-Control':'public,max-age=120'})])
 ok('second source lacking original private no-store is rejected',v is None and n==2
    and error=='source-stability-fresh-wordpress-bypass-missing')
 v,error,n=run([first,(original,{**header,'X-MAD4B-Public-CCSS-Ready':'1'})])
 ok('already optimized second origin forbidden',v is None and n==2
    and error=='source-stability-original-already-optimized')
 v,error,n=run([first,(original,{**header,'X-MAD4B-Public-CCSS-Market':'GB'})])
 ok('second market variant cannot reuse base certificate',v is None and n==2
    and error=='source-stability-wcml-market-context-changed')
 v,error,n=run([first,(original,{**header,'X-MAD4B-Public-CCSS-Currency':'EUR'})])
 ok('second currency variant cannot reuse source CSS',v is None and n==2
    and error=='source-stability-wcml-market-context-changed')
 v,error,n=run([first,('malformed',header)])
 ok('missing second Elementor body fails closed',v is None and n==2
    and error=='missing-original-body-source-graph')
 v,error,n=run([first])
 ok('one original origin alone never qualifies',v is None and n==1)
 v,error,n=run([])
 ok('empty origin source is never a certificate',v is None)
 ok('hermetic check preserves original private source HTML',
    (job/'source.html').read_text()==original)
 ok('hermetic check never writes public CSS cert or ledger',
    not (wp/'mad4b-public-critical-css-v1').exists())
m.observer.ordered.inspect=old
print('PASS',counts,'double-original WordPress source stability cases')

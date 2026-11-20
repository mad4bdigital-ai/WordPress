#!/usr/bin/env python3
"""v2 issuer source evidence, runtime pin, and publish safety checks."""
import copy
import importlib.util
import json
import pathlib
import sys
import tempfile

BASE=pathlib.Path(__file__).parents[1]
ROOT=BASE.parents[1]
def load(file,name):
 spec=importlib.util.spec_from_file_location(name,BASE/file)
 instance=importlib.util.module_from_spec(spec);spec.loader.exec_module(instance)
 return instance
issuer=load('public-ordered-critical-certificate-v2.py','v2_issuer')
manifest=load('public-css-dependency-manifest.py','v2_manifest')
checks=0
def ok(name,passed):
 global checks
 assert passed,name
 checks+=1;print('PASS',name)
def hold(name,func):
 try:func()
 except (KeyError,ValueError):ok(name,True)
 else:raise AssertionError(name)
with tempfile.TemporaryDirectory(prefix='mad4b-v2-issuer-') as tmp:
 root=pathlib.Path(tmp)
 wp=root/'site/wp-content'
 folder=wp/'plugins/example'
 folder.mkdir(parents=True)
 files=['.a{color:red;}', '.b{font-size:13px;}']
 for idx,css in enumerate(files):
  (folder/('a.css' if idx==0 else 'b.css')).write_text(css*50)
 host='tours.dev.mad4b.com'
 hrefs=['https://'+host+'/wp-content/plugins/example/'+name for name in ('a.css','b.css')]
 html='<html><head><title>Contact</title><link rel="stylesheet" href="'+hrefs[0]+'">'+   '<style>h1{display:block}</style><link rel="stylesheet" href="'+hrefs[1]+'">'+   '</head><body class="page-id-69520"><main class="elementor-69520">ok</main></body></html>'
 m=manifest.inspect(html,host,wp)
 chunks=manifest.compile_manifest(m,wp)
 job=root/'job';job.mkdir()
 (job/'source.html').write_text(html)
 def put(name,data):
  (job/name).write_text(json.dumps(data,separators=(',',':'),sort_keys=True))
 put('css-dependencies.json',m)
 observer={'contract':'mad4b.public-css.manifest-observer.v1','host':host,
   'path':'/contact-us/','route_key':'f'*64,'language':'en',
   'market_country':'static','currency':'USD',
   'manifest_sha256':m['manifest_sha256'],'original_html_sha256':manifest.sha(html.encode()),
   'cascade_slots':2,'css_source_proven':True,
   'browser_fcp_lcp_cls_qa_complete':False,'public_css_route_certificate_issued':False,
   'fastcgi_ready_for_promotion':False}
 put('multi-css-source-report.json',observer)
 put('compiled-chunks.json',{'manifest_sha256':m['manifest_sha256'],
    'chunks':[{k:v for k,v in z.items() if k!='css'} for z in chunks['chunks']]})
 critical=[]
 for i,chunk in enumerate(chunks['chunks']):
  css=('.v%d{color:green;}'%i)*1100
  sha=issuer.sha(css.encode())
  (job/(sha+'.css')).write_text(css)
  critical.append({'slot':chunk['slot'],'source_sha256':chunk['sha256'],
    'source_bytes':chunk['bytes'],'critical_sha256':sha,'critical_bytes':len(css),
    'critical_file':sha+'.css'})
 candidate={'contract':'mad4b.public-css.ordered-critical-candidate.v1',
    'manifest_sha256':m['manifest_sha256'],'host':host,'path':'/contact-us/',
    'viewports':[320,390,414,768],'source_cascade_slots':2,'observation_count':4,
    'critical_total_bytes':sum(x['critical_bytes'] for x in critical),
    'public_route_certificate_issued':False,'critical_slots':critical}
 put('ordered-critical-candidate.json',candidate)
 put('browser-parity.json',{'contract':'mad4b.ordered-css.browser-parity.qa.v1',
    'host':host,'pathname':'/contact-us/','manifest_sha256':m['manifest_sha256'],
    'pass':True,'widths':[320,390,414,767,768],
    'results':[{'width':w,'pass':True} for w in [320,390,414,767,768]]})
 v=[320,390,414,767,768]
 qa={'contract':'mad4b.public-css.ordered-critical-real-origin-qa.v1',
     'manifest_sha256':m['manifest_sha256'],'host':host,'path':'/contact-us/',
     'source_css_mode':'original-css-urls',
     'critical_candidate_sha256':issuer.sha((job/'ordered-critical-candidate.json').read_bytes()),
     'pass':True,'public_certificate_issued':False,'fastcgi_purged':False,
     'viewport_results':[{'width':w,'pass':True,
        'fcp_gain_ms':340 if w<768 else 0,'lcp_reg_ms':-150,
        'layout_pass':True,'real_css_links_loaded':True,
        'stylesheet_count':2,'failed_css':[],'errors':[],
        'source':{'lcp':3300},'critical':{'cls':0.01}} for w in v]}
 put('ordered-native-critical-qa.json',qa)
 runtime=ROOT/'wp-content/mu-plugins/422-mad4b-public-critical-css-runtime.php'
 consumer=ROOT/'wp-content/mu-plugins/422-mad4b-public-critical-css-v2-consumer.php'
 route={'type':'page','id':69520,'path':'/contact-us/','language':'en','revision':0,'route_key':'f'*64}
 catalog={'contract':'mad4b.public-critical-css.routes.v1','host':host,'routes':[route]}
 state={'site_host':host,'critical_css':'on','currency':'USD','market_country':'static',
   'runtime_sha':issuer.sha(runtime.read_bytes()),
   'consumer_v2_sha':issuer.sha(consumer.read_bytes()),
   'wpml_languages':['en'],'css_epoch':129,
   'native_breakpoint':767,'global_generation':0,'listing_generation':0}
 def proof(c=catalog,r=route,s=state):
  return issuer.certificate(c,r,s,job,wp,runtime,consumer)
 built=proof()
 ok('only full origin-backed QA issues v2 certificate',built['status']=='certified' and built['contract']==issuer.CONTRACT)
 ok('two source cascade slots bind separate Critical CSS SHA',len(built['critical_slots'])==2 and built['critical_slots'][0]['slot']<built['critical_slots'][1]['slot'])
 ok('manifest and WCML market retained',built['source_manifest_sha256']==m['manifest_sha256'] and built['market_country']=='static')
 ok('certificate pinned to exact 422 and optional 423 source',built['runtime_sha']==state['runtime_sha'] and built['consumer_sha']==state['consumer_v2_sha'])
 ok('source generations and Elementor graph persisted',built['dependencies']['route_generation']==0 and built['elementor_doc_fingerprint']==issuer.sha(b'69520'))
 hold('publisher never accepts foreign WCML market',lambda:proof(s=dict(state,market_country='DE')))
 hold('publisher rejects stale 422 runtime SHA',lambda:proof(s=dict(state,runtime_sha='b'*64)))
 hold('publisher rejects stale v2 module SHA',lambda:proof(s=dict(state,consumer_v2_sha='b'*64)))
 hold('publisher rejects foreign route not in catalog',lambda:proof(r=dict(route,id=69521)))
 bad=copy.deepcopy(qa)
 bad['viewport_results'][1]['fcp_gain_ms']=40
 put('ordered-native-critical-qa.json',bad)
 hold('weak FCP blocks certificate issuance',proof)
 put('ordered-native-critical-qa.json',qa)
 (folder/'b.css').write_text(files[1]*50+'/* drift */')
 hold('changed WordPress source CSS blocks issuance',proof)
 (folder/'b.css').write_text(files[1]*50)
 bad=copy.deepcopy(candidate);bad['critical_slots'][1]['critical_bytes']=12345
 put('ordered-critical-candidate.json',bad)
 hold('tampered critical chunk blocks issuance',proof)
 put('ordered-critical-candidate.json',candidate)
 hold('cannot publish into test root or bypass live approval',lambda:issuer.publish(built,job,wp))
 ok('dryrun and publish tests leave wp-content certs-v2 absent',not (wp/'mad4b-public-critical-css-v1/certs-v2').exists())
print(f'PASS {checks}/{checks} v2 source-owned certificate issuance tests')

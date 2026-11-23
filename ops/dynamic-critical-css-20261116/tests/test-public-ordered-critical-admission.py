#!/usr/bin/env python3
import copy,importlib.util,json,pathlib,tempfile
d=pathlib.Path(__file__).parents[1]
s=importlib.util.spec_from_file_location('ordered_admission',d/'public-ordered-critical-admission.py')
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
checks=0
def ok(name,value):
 global checks
 assert value,name;checks+=1;print('PASS',name)
def fails(name,fn):
 try:fn()
 except (ValueError,KeyError):ok(name,True)
 else:raise AssertionError(name)
with tempfile.TemporaryDirectory() as td:
 p=pathlib.Path(td)
 def put(name,obj):
  (p/name).write_text(json.dumps(obj,separators=(',',':')))
 source='<html><head><link rel="stylesheet" href="/wp-content/plugins/a.css"></head><body class="page-id-23"></body></html>'
 (p/'source.html').write_text(source)
 critical=b'.x{color:green}'
 critsha=m.sha(critical)
 (p/(critsha+'.css')).write_bytes(critical)
 manifest={'contract':m.css.CONTRACT,'host':'test.example',
    'files':[{'order':0,'slot':0,'href':'https://test.example/wp-content/plugins/a.css',
      'path':'/wp-content/plugins/a.css','media':'all','bytes':100,'sha256':'a'*64}],
    'total_bytes':100}
 manifest['manifest_sha256']=m.sha(m.css.canonical(manifest))
 key=manifest['manifest_sha256']
 put('css-dependencies.json',manifest)
 observer={'contract':'mad4b.public-css.manifest-observer.v1',
    'manifest_sha256':key,'host':'test.example','path':'/test/','language':'en',
    'market_country':'base','currency':'USD','css_source_proven':True,'cascade_slots':1,
    'original_html_sha256':m.sha(source.encode()),
    'browser_fcp_lcp_cls_qa_complete':False,'public_css_route_certificate_issued':False,
    'fastcgi_ready_for_promotion':False}
 put('multi-css-source-report.json',observer)
 candidate={'contract':'mad4b.public-css.ordered-critical-candidate.v1',
    'manifest_sha256':key,'host':'test.example','path':'/test/',
    'viewports':[320,390,414,767,768],
    'critical_viewports':[320,390,414,767], 'desktop_validation_viewports':[768],'source_cascade_slots':1,
    'critical_total_bytes':len(critical),'observation_count':5,
    'public_route_certificate_issued':False,
    'critical_slots':[{'slot':0,'source_sha256':'a'*64,
      'source_bytes':100,'critical_sha256':critsha,
      'critical_bytes':len(critical),'critical_file':critsha+'.css'}]}
 put('ordered-critical-candidate.json',candidate)
 put('compiled-chunks.json',{'manifest_sha256':key,
      'chunks':[{'slot':0,'sha256':'a'*64,'bytes':100}]})
 parity={'contract':'mad4b.ordered-css.browser-parity.qa.v1','host':'test.example',
    'pathname':'/test/','manifest_sha256':key,'pass':True,
    'widths':[320,390,414,767,768],
    'results':[{'width':w,'pass':True} for w in [320,390,414,767,768]]}
 put('browser-parity.json',parity)
 qa={'contract':'mad4b.public-css.ordered-critical-real-origin-qa.v1',
     'manifest_sha256':key,'host':'test.example','path':'/test/',
     'critical_candidate_sha256':m.sha((p/'ordered-critical-candidate.json').read_bytes()),
     'source_css_mode':'original-css-urls','pass':True,
     'public_certificate_issued':False,'fastcgi_purged':False,
     'viewport_results':[{'width':w,'pass':True,'fcp_gain_ms':300 if w<768 else 0,
      'lcp_reg_ms':-50,'layout_pass':True,'failed_css':[],'errors':[],
      'real_css_links_loaded':True,'stylesheet_count':1,
      'source':{'lcp':3000},'critical':{'cls':0.01}} for w in [320,390,414,767,768]]}
 put('ordered-native-critical-qa.json',qa)
 proof=m.proof(p)
 ok('five original CSS-backed source viewports qualify for separate delivery review',proof['decision']=='qualified_for_delivery_review')
 ok('public certificate and FastCGI remain disabled',proof['public_certificate_published'] is False and proof['fastcgi_public_ready'] is False)
 bad=copy.deepcopy(qa);bad['viewport_results'][1]['fcp_gain_ms']=20
 put('ordered-native-critical-qa.json',bad)
 ok('weak FCP creates HOLD not a certificate',m.proof(p)['decision']=='hold_performance_or_interaction')
 put('ordered-native-critical-qa.json',qa)
 bad=copy.deepcopy(qa);bad['critical_candidate_sha256']='f'*64
 put('ordered-native-critical-qa.json',bad)
 fails('stale candidate SHA cannot use earlier browser QA',lambda:m.proof(p))
 put('ordered-native-critical-qa.json',qa)
 bad=copy.deepcopy(parity);bad['pass']=False
 put('browser-parity.json',bad)
 fails('original cascade parity is mandatory',lambda:m.proof(p))
 put('browser-parity.json',parity)
 bad=copy.deepcopy(observer);bad['market_country']='GB'
 put('multi-css-source-report.json',bad)
 ok('source context is visible and never invented',m.proof(p)['market_country']=='GB')
 put('multi-css-source-report.json',observer)
 (p/(critsha+'.css')).write_bytes(b'.x{color:red}')
 fails('critical CSS byte mutation invalidates QA',lambda:m.proof(p))
 (p/(critsha+'.css')).write_bytes(critical)
 (p/'source.html').write_text('different HTML')
 fails('unbound original HTML snapshot is denied',lambda:m.proof(p))
print(f'PASS {checks}/{checks} multi-css browser admission tests')

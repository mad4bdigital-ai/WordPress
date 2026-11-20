#!/usr/bin/env python3
import importlib.util
import json
import pathlib
import tempfile
D=pathlib.Path(__file__).parents[1]
sp=importlib.util.spec_from_file_location('css_manifest_observer',D/'public-css-manifest-observer.py')
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
count=0
def test(name,expr):
 global count
 assert expr,name;count+=1;print('PASS',name)
def expect_fail(name,callback):
 try:callback()
 except ValueError:test(name,True)
 else:raise AssertionError(name)
with tempfile.TemporaryDirectory() as td:
 content=pathlib.Path(td)/'wp-content';a=content/'plugins/sample'
 a.mkdir(parents=True);(a/'style.css').write_text('.x{color:green}')
 route={'path':'/tours/one/','language':'en','id':22,'type':'tours-and-activities','route_key':'f'*64}
 cat={'contract':'mad4b.public-critical-css.routes.v1','host':'company.example','routes':[route]}
 html='<html><head><link href="/wp-content/plugins/sample/style.css" rel="stylesheet"></head><body><h1>Test</h1></body></html>'
 headers={'X-MAD4B-Public-CCSS-Market':'base','X-MAD4B-Public-CCSS-Currency':'USD'}
 fetch=lambda host,path:(html,headers)
 result,manifest,compiled=m.observe(cat,route,content,expected_host='company.example',fetch=fetch)
 test('first-party source-only proof built',result['css_source_proven'] and result['decision']=='awaiting_multi_css_route_qa')
 test('source proof cannot grant public HTML readiness',not result['browser_fcp_lcp_cls_qa_complete']
   and not result['public_css_route_certificate_issued'] and not result['fastcgi_ready_for_promotion'])
 test('server-resolved base USD pinned',result['market_country']=='base' and result['currency']=='USD')
 m.store(pathlib.Path(td),result,manifest,compiled)
 test('immutable chunk output stored only in selected private report', (pathlib.Path(td)/(compiled['chunks'][0]['sha256']+'.css')).is_file())
 test('source SHA readback',json.loads((pathlib.Path(td)/'css-dependencies.json').read_text())['manifest_sha256']==manifest['manifest_sha256'])
 wrong=dict(cat,host='foreign.example')
 expect_fail('foreign host cannot borrow source catalog identity',lambda:m.observe(wrong,route,content,expected_host='company.example',fetch=fetch))
 expect_fail('missing WCML identity fails closed',lambda:m.observe(cat,route,content,expected_host='company.example',fetch=lambda *args:(html,{})))
 expect_fail('already transformed public HTML cannot be recertified',lambda:m.observe(cat,route,content,expected_host='company.example',fetch=lambda *args:(html,dict(headers,**{'X-MAD4B-Public-CCSS-Ready':'1'}))))
 expect_fail('unregistered route cannot be admitted',lambda:m.observe(cat,dict(route,id=23),content,expected_host='company.example',fetch=fetch))
 expect_fail('independent Home certifier remains separate',lambda:m.observe({'contract':cat['contract'],'host':cat['host'],'routes':[dict(route,path='/')]},dict(route,path='/'),content,expected_host='company.example',fetch=fetch))
 print(f'PASS {count}/{count} first-party multiCSS observation tests')

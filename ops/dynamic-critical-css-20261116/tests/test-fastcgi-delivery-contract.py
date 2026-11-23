#!/usr/bin/env python3
"""No raw Cache HIT or forged Ready header proves that HTML contains CCSS."""
import importlib.util
from pathlib import Path
import sys
D=Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('css_delivery',D/'public-fastcgi-delivery-contract.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
n=0
def ok(cond,msg):
 global n
 assert cond,msg
 n+=1;print('PASS',msg)
v2='<html><head><style data-mad4b-ccss-v2-critical="1">h1{color:red}</style>'+\
    '<link data-mad4b-ccss-v2-rest="1" rel="stylesheet" href="/css.css">'+\
    '<script id="mad4b-public-ccss-v2-recovery"></script></head><body></body></html>'
v1='<html><head><style id="mad4b-public-critical-css-v1">h1{color:red}</style>'+\
    '<link id="mad4b-public-css-rest-v1" rel="stylesheet"></head><body></body></html>'
native='<html><head><link rel="stylesheet" href="/all.css"></head><body></body></html>'
def check(html=native,cache='HIT',ready='',phase='',private=False,host='wayfatours.com'):
 return m.classify(host,{'x-wayfa-origin-cache' if host=='wayfatours.com' else 'x-mad4b-preset-cache':cache,
   'cache-control':'private, no-store' if private else 'public,max-age=1800',
   'x-mad4b-public-ccss-ready':ready,'x-mad4b-public-ccss-phase':phase},html)
ok(check()['delivery']=='CACHE_HIT_NATIVE','FastCGI HIT without inline CCSS is Native')
ok(check(v2,ready='1',phase='qualified')['delivery']=='CACHE_HIT_CCSS',
   'source-attested inline V2 CSS + async + recovery is HIT CCSS')
ok(check(v2,ready='1',phase='qualified')['css_contract']=='v2','V2 distinguished')
ok(check(native,ready='1',phase='qualified')['header_html_mismatch'],
   'forged Ready 1 without HTML does not count as CSS')
ok(check(v2,ready='1')['delivery']=='CACHE_HIT_NATIVE',
   'V2 without qualified phase cannot claim public success')
ok(check(v1,ready='1')['delivery']=='CACHE_HIT_CCSS','V1 signed inline style counted separately')
ok(check(v2,ready='1',phase='qualified',private=True)['delivery']=='PRIVATE_SAFE',
   'private no-store remains private even with valid CSS')
ok(check(v2,ready='1',phase='qualified',private=True)['html_css_verified'],
   'private optimized variant can still be measured')
ok(check(native,private=True)['delivery']=='PRIVATE_SAFE','private native is safe no-store')
ok(check(v2,cache='MISS',ready='1',phase='qualified')['delivery']=='ORIGIN_CCSS_READY',
   'public CSS MISS is ready but is never misreported HIT')
ok(check(native,cache='MISS')['delivery']=='CACHE_MISS_NATIVE','cache MISS native is distinct')
ok(check(v2,ready='1',phase='qualified',host='tours.dev.mad4b.com')['cache_provider']=='Preset',
   'Source site Preset Cache distinct from Wayfa FastCGI')
ok(check(native,host='tours.dev.mad4b.com')['delivery']=='CACHE_HIT_NATIVE',
   'Source HIT alone never qualifies CSS')
ok(check(v2,ready='1',phase='qualified')['delivery']=='CACHE_HIT_CCSS',
   'case-insensitive canonical first-party headers')
try:m.classify('evil.com',{},native)
except ValueError:ok(True,'foreign host denied')
else:raise AssertionError('foreign host admitted')
try:m.fetch('wayfatours.com','/checkout/')
except ValueError:ok(True,'private transactional route not probed')
else:raise AssertionError('checkout requested')
ok(m.classify('wayfatours.com',{},native,404)['delivery']=='HTTP_NOT_200',
   'HTTP 404 never counted as Native HIT')
audit=(D/'health-audit.py').read_text()
ok("delivery=load(D/'public-fastcgi-delivery-contract.py'" in audit,
   'site-owned health audit imports exact first-party classifier')
ok("variant['html_delivery_contract']=delivery.fetch(" in audit,
   'periodic health probes inspect delivered body not merely headers')
ok("result['html_delivery_contract_sample_count']=len(delivery_samples)" in audit,
   'health report labels sample count and never extrapolates all routes')
ok(m.baseline_public_route({'contract':'mad4b.public-critical-css.routes.v1',
   'host':'tours.dev.mad4b.com','routes':[{'type':'page','language':'en',
   'path':'/properties/'},{'type':'page','language':'en','path':'/contact-us/'},
   {'type':'page','language':'en','path':'/'}]})=='/contact-us/',
   'Source dashboard can sample active catalog pages without certificates')
ok(m.baseline_public_route({'contract':'mad4b.public-critical-css.routes.v1',
   'host':'wayfatours.com','routes':[{'type':'page','language':'en',
   'path':'/about-us/'}]})=='/about-us/',
   'secondary verified company page selected when contact absent')
ok(m.baseline_public_route({'contract':'mad4b.public-critical-css.routes.v1',
   'host':'wayfatours.com','routes':[{'type':'page','language':'en',
   'path':'/sample-page/'}]}) is None,
   'no nonpublic sample or inactive properties catalog admitted')
try:m.baseline_public_route({'contract':'mad4b.public-critical-css.routes.v1',
   'host':'untrusted.example','routes':[]})
except ValueError:ok(True,'foreign site baseline rejected')
else:raise AssertionError('foreign site baseline accepted')
ok("result['html_delivery_native_baseline']" in audit
   and "delivery.baseline_public_route(catalog)" in audit,
   'site audit emits native/Private CSS baseline without certification')
print('PASS',n,'FastCGI/Preset HTML delivery classification tests')

#!/usr/bin/env python3
"""No source-family observation or CSS release may reuse an HTTP cache shell."""
import importlib.util
import pathlib
import sys
D=pathlib.Path(sys.argv[1])
def import_from(name,file):
    sp=importlib.util.spec_from_file_location(name,D/file)
    mod=importlib.util.module_from_spec(sp)
    sp.loader.exec_module(mod)
    return mod
observer=import_from('source_host_original_http','public-css-manifest-observer.py')
idx=import_from('source_host_template_index','public-template-variant-index.py')
source={'Cache-Control':'private, no-store, max-age=0',
        'X-MAD4B-Preset-Cache':'BYPASS',
        'X-MAD4B-Public-CCSS-Ready':'',
        'X-MAD4B-Public-CCSS-Currency':'EUR',
        'X-MAD4B-Public-CCSS-Market':'DE'}
wayfa={'Cache-Control':'private, no-store, max-age=0',
        'X-Wayfa-Origin-Cache':'BYPASS',
        'X-MAD4B-Public-CCSS-Ready':'',
        'X-MAD4B-Public-CCSS-Currency':'USD',
        'X-MAD4B-Public-CCSS-Market':'base'}
checks=0
def ok(cond,label):
    global checks
    assert cond,label
    checks+=1;print('PASS',label)
ok(observer.original_proven_fresh('tours.dev.mad4b.com',source),
   'Source fresh WordPress BYPASS verified')
ok(not observer.original_proven_fresh('tours.dev.mad4b.com',
      {**source,'X-MAD4B-Preset-Cache':'MISS'}),
   'Source Preset Cache MISS cannot sign CSS')
ok(not observer.original_proven_fresh('tours.dev.mad4b.com',
      {**source,'X-MAD4B-Preset-Cache':'HIT'}),
   'Source Preset Cache HIT cannot sign CSS')
ok(not observer.original_proven_fresh('tours.dev.mad4b.com',
      {**source,'Cache-Control':'public,max-age=1800'}),
   'Source response is never accepted when publicly cacheable')
ok(observer.original_proven_fresh('wayfatours.com',wayfa),
   'Wayfa independent FastCGI bypass marker retained')
ok(not observer.original_proven_fresh('wayfatours.com',
      {**wayfa,'X-Wayfa-Origin-Cache':'HIT'}),
   'Wayfa FastCGI HIT cannot sign CSS')
ok(not observer.original_proven_fresh('evil.example',source),
   'unregistered host cannot borrow Source proof')
class Response:
    def __init__(self,headers):
        self.status=200
        self.headers={'Content-Type':'text/html; charset=utf-8',**headers}
    def __enter__(self):return self
    def __exit__(self,*_args):return False
    def read(self,n):return b'<!doctype html><html><body>source</body></html>'
class Opener:
    def __init__(self,headers):self.headers=headers;self.bypass=False
    def open(self,request,timeout):
        self.bypass=any(
            k.lower()=='x-mad4b-bypass-cache' and v=='1'
            for k,v in request.header_items())
        return Response(self.headers)
current=Opener(source)
original=observer.urllib.request.build_opener
try:
    observer.urllib.request.build_opener=lambda *_:current
    html,headers=observer.public_fetch('tours.dev.mad4b.com','/contact-us/')
    ok(current.bypass and headers['X-MAD4B-Preset-Cache']=='BYPASS'
       and html.startswith('<!doctype'), 'source HTTP request forces original bypass')
    current=Opener({**source,'X-MAD4B-Preset-Cache':'HIT'})
    observer.urllib.request.build_opener=lambda *_:current
    try:observer.public_fetch('tours.dev.mad4b.com','/contact-us/')
    except ValueError:ok(True,'source original fetch refuses HIT after request')
    else:raise AssertionError('Source HIT admitted')
finally:
    observer.urllib.request.build_opener=original
original2=idx.origin.public_fetch
try:
    idx.origin.public_fetch=lambda host,path:('<html>fresh</html>',source)
    ok(idx.fresh_original_source('source','tours.dev.mad4b.com','/contact-us/')
       ==('<html>fresh</html>','EUR','DE'),
       'Source template index derives only origin source market and currency')
    idx.origin.public_fetch=lambda host,path:('<html>stale</html>',
        {**source,'X-MAD4B-Preset-Cache':'HIT'})
    ok(idx.fresh_original_source('source','tours.dev.mad4b.com','/contact-us/')
       is None,'stale Source observation never mapped to source family')
    idx.origin.public_fetch=lambda host,path:('<html>stale</html>',
        {**source,'X-MAD4B-Public-CCSS-Ready':'1'})
    ok(idx.fresh_original_source('source','tours.dev.mad4b.com','/contact-us/')
       is None,'already transformed CSS is never requalified as original')
    try:idx.fresh_original_source('source','wayfatours.com','/contact-us/')
    except ValueError:ok(True,'cross-tenant source discovery denied')
    else:raise AssertionError('foreign site source allowed')
finally:
    idx.origin.public_fetch=original2
print('PASS',checks,'cached or cross-context HTML cannot qualify source CSS')

#!/usr/bin/env python3
"""Native WordPress original-CSS requests cannot silently use a FastCGI HIT."""
import importlib.util,pathlib,sys
D=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('wayfa_origin_bypass_under_test',D/'public-css-manifest-observer.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
checks=0
def ok(x,label):
 global checks
 assert x,label
 checks+=1
 print('PASS',label)
class Response:
 status=200
 def __init__(self,headers):
  self.headers=headers
 def __enter__(self):return self
 def __exit__(self,*a):return None
 def read(self,n):return b'<!doctype html><html><head></head><body></body></html>'
class Opener:
 def __init__(self,cache='BYPASS',cc='private, no-store'):
  self.cache=cache
  self.cc=cc
  self.headers=[]
 def open(self,req,timeout):
  self.headers.append({k.lower():v for k,v in req.header_items()})
  return Response({'Content-Type':'text/html; charset=utf-8',
     'X-Wayfa-Origin-Cache':self.cache,'Cache-Control':self.cc,
     'X-MAD4B-Public-CCSS-Currency':'USD',
     'X-MAD4B-Public-CCSS-Market':'base'})
old=m.urllib.request.build_opener
try:
 fake=Opener()
 m.urllib.request.build_opener=lambda *_:fake
 html,headers=m.public_fetch('wayfatours.com','/tours-and-activities/cairo-tour/')
 ok(fake.headers[0].get('x-mad4b-bypass-cache')=='1',
    'Wayfa capture requests native FastCGI BYPASS')
 ok(headers['X-Wayfa-Origin-Cache']=='BYPASS'
    and 'no-store' in headers['Cache-Control'],
    'native source proof requires no-store original response')
 ok(html.startswith('<!doctype html>'),'original HTML returned intact')
 for cache,cc,error in [('HIT','private, no-store','HIT'),('MISS','private, no-store','MISS'),
                        ('BYPASS','public, max-age=300','cacheable')]:
  bad=Opener(cache,cc)
  m.urllib.request.build_opener=lambda *_:bad
  try:m.public_fetch('wayfatours.com','/tour/')
  except ValueError as e:
   ok('wayfa-source-html-not-fresh-fastcgi-bypass' in str(e),
      'disallowed '+error+' source response cannot qualify')
  else:raise AssertionError(error+' accepted')
 generic=Opener('HIT','public, max-age=300')
 m.urllib.request.build_opener=lambda *_:generic
 m.public_fetch('other-company.example','/tour/')
 ok('x-mad4b-bypass-cache' not in generic.headers[0],
    'no changed source/staging cache transport without Wayfa enrollment')
 try:m.public_fetch('wayfatours.com','/../hidden')
 except ValueError as e:ok('untrusted-catalog-public-route' in str(e),
                          'path traversal refused before any HTTP request')
 else:raise AssertionError('untrusted route admitted')
finally:m.urllib.request.build_opener=old
print(f'PASS {checks}/{checks} native original HTML FastCGI bypass invariants')

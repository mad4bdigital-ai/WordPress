#!/usr/bin/env python3
"""MAD4B read-only cache safety verifier for cart island / Fluent payment routes.

Never uses real customer cookies or tokens; no write endpoints.
This checks the ORIGINAL production Nginx boundaries, NOT cache admission.
"""
from __future__ import annotations
import argparse,json,pathlib,re,sys,urllib.parse,urllib.request,datetime
BASE='https://wayfatours.com'
HOST='wayfatours.com'
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36'
ROUTES=[
 '/',
 '/es/',
 '/tours-and-activities/',
 '/tours-and-activities/amazing-5-days-cairo-and-luxor-discovery-tour/',
]
# Invalid/synthetic cookies only; never read or write browser personal data.
CASES=[
 ('anonymous',''),
 ('woocommerce_cart_cookie','woocommerce_items_in_cart=1; woocommerce_cart_hash=mad4b_probe'),
 ('woocommerce_guest_session','wp_woocommerce_session_0123456789abcdef0123456789abcdef=mad4b_probe'),
 ('market_preference','mad4b_country_selected_v1=mad4b_probe'),
 ('manual_nationality','travel_booking_nationality_preference_v1=mad4b_probe'),
]
def fetch(url,cookie='',accept='text/html'):
 headers={'User-Agent':UA,'Accept':accept,'Referer':BASE+'/'}
 if cookie:headers['Cookie']=cookie
 req=urllib.request.Request(url,headers=headers,method='GET')
 with urllib.request.urlopen(req,timeout=22) as r:
  # Canonicalize duplicate Cache-Control fields; Nginx adds its own policy.
  cc=', '.join(r.headers.get_all('Cache-Control',[]))
  return {
   'code':r.status,'cc':cc.lower(),
   'origin':(r.headers.get('X-Wayfa-Origin-Cache') or '').upper(),
   'set_cookie':bool(r.headers.get_all('Set-Cookie')),
   'type':(r.headers.get('Content-Type') or '').lower(),
   'body':r.read(1500000).decode('utf-8','replace')
  }
def verify_html(path,label,cookie,mode):
 response=fetch(BASE+path,cookie)
 assert response['code']==200,(path,label,'not_200')
 assert 'text/html' in response['type'],(path,label,'not_html')
 cc=response['cc']
 if label=='anonymous':
  # This is the familiar shared page. A source CSS qualification transition
  # may temporarily make it private; that is not a privacy violation.
  assert response['origin'] in {'HIT','MISS','UPDATING','STALE','BYPASS','EXPIRED',''},(path,label,'cache_unknown')
 else:
  assert response['origin'] not in {'HIT','UPDATING','STALE'},(path,label,'SENSITIVE_HTML_CACHE_HIT')
  assert 'private' in cc and 'no-store' in cc,(path,label,'SENSITIVE_HTML_CACHE_NOT_PRIVATE')
  assert response['origin']=='BYPASS',(path,label,'SENSITIVE_NOT_BYPASSED')
 body=response['body']
 if mode=='payment_only':
  assert 'data-mad4b-cart-island="v1"' not in body,(path,label,'CART_ISLAND_VISIBLE_IN_PAYMENT_ONLY')
  assert 'mad4b-cart-island-v1.js' not in body,(path,label,'CART_JS_LOADED_IN_PAYMENT_ONLY')
 else:
  # A public shell must never contain bearer credentials regardless of the
  # shopper's cookie; the island must read private JSON separately.
  assert not re.search(r'<div[^>]*data-mad4b-cart-island="v1"[^>]*data-cart-(?:token|hash|user)',body,re.I),\
   (path,label,'PERSONAL_STATE_IN_CART_ISLAND_SHELL')
 return {'route':path,'case':label,'http':response['code'],'cache':response['origin'],
         'private_no_store':('private' in cc and 'no-store' in cc),
         'public_html':('public' in cc and 'private' not in cc),
         'island_visible':'data-mad4b-cart-island="v1"' in body}
def verify_store():
 for label,cookie in [('anonymous',''),('woocommerce_guest_session',
       'wp_woocommerce_session_0123456789abcdef0123456789abcdef=mad4b_probe')]:
  # Verify both canonical and WPML-localized Store API endpoint routing.
  for url in ['/wp-json/wc/store/v1/cart','/es/wp-json/wc/store/v1/cart']:
   response=fetch(BASE+url,cookie,'application/json')
   assert response['code']==200 and 'application/json' in response['type']
   assert 'private' in response['cc'] and 'no-store' in response['cc']
   assert response['origin'] not in {'HIT','UPDATING','STALE'}
   body=json.loads(response['body'])
   assert isinstance(body.get('items'),list) and isinstance(body.get('items_count'),int)
def site_routes(filename):
 if not filename: return ROUTES
 document=json.loads(pathlib.Path(filename).read_text())
 assert document.get('contract')=='mad4b.cart-cache-boundary.wpml-routes.v1'
 assert document.get('site_host')==HOST
 routes=document.get('routes')
 assert isinstance(routes,list) and len(routes)==6 and len(set(routes))==6
 for route in routes:
  assert isinstance(route,str) and 1<=len(route)<=400
  assert route.startswith('/') and not route.startswith('//')
  assert '?' not in route and '#' not in route and '..' not in route
  assert not re.search(r'(?:wp-admin|wp-login|checkout|order-pay|account)',route,re.I)
 assert routes[0]=='/' and routes[1]=='/es/'
 assert routes[2].startswith('/tours-and-activities/')
 assert routes[3].startswith('/es/tours-and-activities/')
 assert routes[4].startswith('/tours-and-activities/') and routes[4]!=routes[2]
 assert routes[5].startswith('/es/tours-and-activities/') and routes[5]!=routes[3]
 return routes

def main():
 p=argparse.ArgumentParser()
 p.add_argument('--mode',choices=['payment_only','standard'],default='payment_only')
 p.add_argument('--routes-json',default='',help='read-only dynamic WPML EN/ES route inventory')
 p.add_argument('--output',default='')
 a=p.parse_args()
 result={'contract':'mad4b.cart-cache-boundary.readonly.v1',
         'site_host':HOST,'timestamp_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
         'mode':a.mode,'checks':[],'status':'passed'}
 for path in site_routes(a.routes_json):
  for label,cookie in CASES:
   result['checks'].append(verify_html(path,label,cookie,a.mode))
 verify_store()
 result['store_api_verified']=True
 if a.output:
  dest=pathlib.Path(a.output).resolve()
  # No sensitive response bodies or cookies in persisted report.
  dest.write_text(json.dumps(result,indent=2)+'\n')
 print(json.dumps({'status':'PASS_CART_CACHE_BOUNDARY',
                   'html_route_cases':len(result['checks']),
                   'store_api_cases':4,
                   'source':'live_origin','mode':a.mode},sort_keys=True))
if __name__=='__main__':
 try:main()
 except Exception as e:
  print(json.dumps({'status':'HOLD_PRIVACY_BOUNDARY','error':str(e)[:250]}),file=sys.stderr)
  sys.exit(1)

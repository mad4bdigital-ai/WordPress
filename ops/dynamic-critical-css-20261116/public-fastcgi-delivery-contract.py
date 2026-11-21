#!/usr/bin/env python3
"""Read-only first-party HTML delivery classifier; never a CSS certificate."""
import re
import urllib.request
from urllib.parse import urlsplit

HOSTS=('tours.dev.mad4b.com','wayfatours.com')
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36'
LIMIT=6_000_000
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*_args,**_kwargs):return None

def classify(host,headers,html,status=200):
    if host not in HOSTS or not isinstance(headers,dict) or not isinstance(html,str):
        raise ValueError('untrusted-delivery-contract')
    if len(html)>LIMIT:raise ValueError('oversized-html')
    h={k.lower():str(v).strip() for k,v in headers.items()}
    cache=h.get('x-wayfa-origin-cache' if host=='wayfatours.com' else 'x-mad4b-preset-cache','').upper()
    control=h.get('cache-control','').lower()
    ready=h.get('x-mad4b-public-ccss-ready','')
    phase=h.get('x-mad4b-public-ccss-phase','')
    end=html.lower().find('</head>')
    head=html[:end] if 0<end<=LIMIT else ''
    v2=('data-mad4b-ccss-v2-critical="1"' in head and
        'data-mad4b-ccss-v2-rest="1"' in head and
        'id="mad4b-public-ccss-v2-recovery"' in head)
    v1=('id="mad4b-public-critical-css-v1"' in head and
        'id="mad4b-public-css-rest-v1"' in head)
    valid_css=(ready=='1' and (v2 and phase=='qualified' or v1))
    if status!=200:
        kind='HTTP_NOT_200'
    elif 'no-store' in control or 'private' in control:
        kind='PRIVATE_SAFE'
    elif cache=='HIT' and valid_css:
        kind='CACHE_HIT_CCSS'
    elif cache=='HIT':
        kind='CACHE_HIT_NATIVE'
    elif valid_css:
        kind='ORIGIN_CCSS_READY'
    elif cache in ('MISS','BYPASS'):
        kind='CACHE_MISS_NATIVE'
    else:
        kind='DELIVERY_UNKNOWN'
    return {'delivery':kind,'cache_provider':('FastCGI' if host=='wayfatours.com' else 'Preset'),
            'cache_state':cache or 'UNKNOWN','html_css_verified':bool(valid_css),
            'css_contract':('v2' if valid_css and v2 else 'v1' if valid_css else None),
            'ready_header':ready or None,
            'header_html_mismatch':ready=='1' and not valid_css,
            'private_nonshared':('private' in control or 'no-store' in control),
            'http_status':status}

def baseline_public_route(catalog):
    """Pick a currently discovered English public page for native baseline.

    A negative result never grants readiness. Never select booking routes,
    homepage (separately certified), unpublished routes or external sites.
    """
    if (not isinstance(catalog,dict)
        or catalog.get('contract')!='mad4b.public-critical-css.routes.v1'
        or catalog.get('host') not in HOSTS
        or not isinstance(catalog.get('routes'),list)):
        raise ValueError('untrusted-live-wordpress-css-catalog')
    candidates=[]
    for row in catalog['routes']:
        if not isinstance(row,dict) or row.get('type')!='page' or row.get('language')!='en':
            continue
        path=row.get('path')
        if (not isinstance(path,str) or path=='/'
            or not re.fullmatch(r'/[a-zA-Z0-9/_%.-]{1,349}',path)
            or '//' in path or '..' in path
            or any(x in path.lower().split('/') for x in
                   ('checkout','cart','my-account','wp-admin','wp-json','order-pay',
                    'properties','transportations','sample-page'))):
            continue
        rank=0 if path=='/contact-us/' else 1 if path=='/about-us/' else 2
        candidates.append((rank,len(path),path))
    return min(candidates)[2] if candidates else None


def fetch(host,path):
    if (host not in HOSTS
        or not isinstance(path,str)
        or not re.fullmatch(r'/[a-zA-Z0-9/_%.-]{1,349}',path)
        or '//' in path or '..' in path
        or re.search(r'%2f|%5c',path,re.I)
        or any(x in path.lower().split('/') for x in
            ('checkout','cart','my-account','wp-admin','wp-json','order-pay'))):
        raise ValueError('unsafe-public-delivery-probe')
    request=urllib.request.Request('https://'+host+path,method='GET',headers={
      'User-Agent':UA,'Accept':'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
      'Accept-Encoding':'identity'})
    with urllib.request.build_opener(NoRedirect()).open(request,timeout=12) as r:
        data=r.read(LIMIT+1)
        if len(data)>LIMIT:raise ValueError('public-response-html-over-limit')
        headers={k:v for k,v in r.headers.items()
                 if k.lower() in ('cache-control','x-wayfa-origin-cache',
                      'x-mad4b-preset-cache','x-mad4b-public-ccss-phase',
                      'x-mad4b-public-ccss-ready')}
        return classify(host,headers,data.decode('utf-8',errors='replace'),r.status)

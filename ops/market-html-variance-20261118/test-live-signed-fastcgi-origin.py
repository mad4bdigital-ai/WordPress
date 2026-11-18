#!/usr/bin/env python3
"""Read-only live admission test: synthetic signed cookies remain in memory."""
import importlib.util,json,time
from pathlib import Path
from urllib.request import Request,urlopen
from importlib.machinery import SourceFileLoader
p=Path(__file__).with_name('capture-live-route-variance.py')
spec=importlib.util.spec_from_loader('mlive',SourceFileLoader('mlive',str(p)))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
tokens=m.mint_country_tokens()
targets=[('US','/es/'),('DE','/es/')]
out=[]
for country,route in targets:
    for visit in (1,2):
        req=Request('https://wayfatours.com'+route,
            headers={'User-Agent':m.UA,
               'Accept':'text/html,application/xhtml+xml',
               'Accept-Encoding':'identity',
               'Cookie':'mad4b_country_selected_v1='+tokens[country]})
        t=time.monotonic()
        with urlopen(req,timeout=35) as res:
            raw=res.read(2800001)
            if res.status!=200 or res.url!='https://wayfatours.com'+route:raise ValueError('origin_status_invalid')
            data=raw.decode('utf-8','replace')
            parsed=m.PublicPage();parsed.feed(data)
            proof=parsed.displayed_prices('es' if route=='/es/' else 'en')
            record={
                'country':country,'route':route,'visit':visit,
                'http':res.status,'cache':res.headers.get('X-Wayfa-Origin-Cache',''),
                'origin_admitted':res.headers.get('X-MAD4B-Signed-Home-Cache-Ready',''),
                'edge_flags':res.headers.get('X-MAD4B-Signed-Cache-Diag',''),
                'private_css':res.headers.get('X-MAD4B-Private-Mobile-CSS-Ready',''),
                'cache_control':res.headers.get_all('Cache-Control') or [],
                'set_cookie':bool(res.headers.get('Set-Cookie')),
                'price_count':len(proof[0]) if proof else None,
                'html_size':len(raw),
                'elapsed_ms':int((time.monotonic()-t)*1000)
            }
            out.append(record)
            print('LIVE_SIGNED_FASTCGI_RESULT',json.dumps(record,sort_keys=True),flush=True)
assert len(out)==len(targets)*2
if any(x['http']!=200 or x['price_count']!=(11 if x['route']=='/es/' else 9) for x in out):
    raise RuntimeError('signed_origin_price_contract_failure')
assert all(x['origin_admitted']=='1' and x['private_css']=='1' for x in out)
assert all(any('no-store' in v for v in x['cache_control']) for x in out)
if any(x['cache']!='HIT' for x in out if x['visit']==2):
    raise RuntimeError('signed_origin_fastcgi_not_stored')
print('SIGNED_PRIVATE_FASTCGI_LIVE_HIT_VERIFIED')

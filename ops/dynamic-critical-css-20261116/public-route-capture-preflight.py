#!/usr/bin/env python3
"""Fail-closed selector-graph stability proof before costly Chromium QA.

This is not a certificate issuer. It admits full QA only if CSS URL, exact
source bytes, Elementor document graph, market/currency and object/locale
remain identical in four independently rendered viewport captures.
"""
import json,re,sys
from pathlib import Path

REQUIRED={320,390,414,768}
MARKET_ERROR='PUBLIC_CAPTURE_REQUIRES_TRUSTED_SERVER_WCML_CURRENCY_AND_MARKET'
ERRORS={
  'market_context_unavailable','capture_error','unstable_css_source',
  'unstable_elementor_graph','unstable_market_currency','candidate_incomplete'
}
def read(path):
    p=Path(path)
    if p.is_symlink() or not p.is_file() or p.stat().st_size>2000000:
        raise ValueError('untrusted-public-capture-artifact')
    return json.loads(p.read_text())
def examine(capture,candidates,route):
    if (capture.get('contract')!='mad4b.mobile-critical.capture.v1'
        or candidates.get('contract')!='mad4b.mobile-critical.candidates.v1'
        or capture.get('host')!=candidates.get('host')
        or capture.get('host') not in ('tours.dev.mad4b.com','wayfatours.com')
        or not re.fullmatch(r'[a-z]{2}',route.get('language',''))
        or not isinstance(route.get('path'),str)
        or not isinstance(route.get('id'),int)):
        raise ValueError('cross-company-or-invalid-capture-identity')
    observations=capture.get('scenarios',[])
    if (not isinstance(observations,list)
        or len(observations)!=4 or {x.get('width') for x in observations}!=REQUIRED
        or any(x.get('lang')!=route['language'] or x.get('uri')!=route['path']
               for x in observations)):
        raise ValueError('wrong-route-or-incomplete-viewport-identity')
    failures=[x.get('error','') for x in observations if x.get('error')]
    if failures:
        reason='market_context_unavailable' if len(failures)==4 and all(MARKET_ERROR in f for f in failures) else 'capture_error'
        return {'decision':'hold','reason':reason,'views':len(observations)}
    css_files={x.get('css_file') for x in observations}
    source_shas={x.get('source_sha') for x in observations}
    if (len(css_files)!=1 or len(source_shas)!=1
        or not all(re.fullmatch(r'[a-f0-9]{32}[.]css',x or '') for x in css_files)
        or not all(re.fullmatch(r'[a-f0-9]{64}',x or '') for x in source_shas)):
        return {'decision':'hold','reason':'unstable_css_source','views':4,
            'css_files':len(css_files),'source_shas':len(source_shas)}
    doc={x.get('dom_sha') for x in observations}
    if len(doc)!=1 or not re.fullmatch(r'[a-f0-9]{64}',next(iter(doc)) or ''):
        return {'decision':'hold','reason':'unstable_elementor_graph','views':4}
    context={(x.get('resolved_market'),x.get('resolved_currency')) for x in observations}
    if len(context)!=1:
        return {'decision':'hold','reason':'unstable_market_currency','views':4}
    market,currency=next(iter(context))
    if (not isinstance(currency,str) or not re.fullmatch(r'[A-Z]{3}',currency)
        or not isinstance(market,str) or not re.fullmatch(r'(?:[A-Z]{2}|static|base)',market)):
        return {'decision':'hold','reason':'market_context_unavailable','views':4}
    rows=candidates.get('candidates',[])
    if (not isinstance(rows,list) or len(rows)!=1):
        return {'decision':'hold','reason':'candidate_incomplete','views':4}
    item=rows[0]
    if (item.get('lang')!=route['language'] or item.get('route')!=route['path']
        or set(item.get('widths',[]))!=REQUIRED or len(item['widths'])!=4
        or item.get('css_file')!=next(iter(css_files))
        or item.get('source_sha')!=next(iter(source_shas))
        or item.get('dom_sha')!=next(iter(doc))
        or item.get('resolved_market')!=market
        or item.get('resolved_currency')!=currency
        or item.get('page_id')!=route['id']):
        return {'decision':'hold','reason':'candidate_incomplete','views':4}
    return {'decision':'admit_qa','reason':'exact_four_viewport_source_stable',
            'views':4,'css_sha':item['source_sha']}
def main():
    if len(sys.argv)!=4:raise ValueError('usage: capture-index candidates route')
    result=examine(*(read(x) for x in sys.argv[1:]))
    print('PUBLIC_CCSS_CAPTURE_PREFLIGHT',json.dumps(result,sort_keys=True))
if __name__=='__main__':
    try:main()
    except (OSError,TypeError,ValueError,KeyError,IndexError,json.JSONDecodeError) as exc:
        print('HOLD_PUBLIC_CCSS_CAPTURE_PREFLIGHT',str(exc),file=sys.stderr);sys.exit(4)

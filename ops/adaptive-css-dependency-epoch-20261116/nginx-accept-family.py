#!/usr/bin/env python3
"""Safely construct a reviewed accept-family Nginx cache-key candidate; never activate it."""
import argparse
from pathlib import Path
import re

MAPS = '''# Browser image-format negotiation changes LiteSpeed combined CSS hashes.
# Keep bounded AVIF/WebP source families in FastCGI keys. Do NOT key only on
# User-Agent: generic probes and real Chrome may require different CSS.
map $http_accept $mad4b_css_accept_webp {
 default 0;
 ~*image/webp 1;
}
map $http_accept $mad4b_css_accept_avif {
 default 0;
 ~*image/avif 1;
}
map "$mad4b_css_accept_webp$mad4b_css_accept_avif" $mad4b_css_accept_family {
 default "other";
 "00" "generic";
 "10" "webp";
 "01" "avif";
 "11" "avif_webp";
}
'''
ANCHOR = 'map $http_user_agent $mad4b_device_class {'
VARY = 'add_header Vary User-Agent always;'
ADDED = 'add_header Vary Accept always;'
MARKET = 'map $host $mad4b_uncertified_market_html'
PRIVATE = 'fastcgi_no_cache $company_perf_skip $company_perf_no_origin_optin $upstream_http_set_cookie;'
KEYS = re.compile(r'fastcgi_cache_key "[^"\n]+";')

class TopologyError(ValueError):
    pass

def apply(src: str) -> str:
    if MARKET not in src or PRIVATE not in src:
        raise TopologyError('MISSING_MARKET_OR_PRIVATE_GUARD')
    keys = KEYS.findall(src)
    if MAPS in src:
        if src.count(MAPS) == 1 and len(keys)==5 and src.count(ADDED)==5 and all('|accept=$mad4b_css_accept_family' in key for key in keys):
            return src
        raise TopologyError('PARTIAL_ACCEPT_SEGMENTATION')
    if src.count(ANCHOR)!=1 or len(keys)!=5 or src.count(VARY)!=5 or ADDED in src:
        raise TopologyError('UNEXPECTED_CACHE_KEY_TOPOLOGY')
    if any('|accept=' in key for key in keys):
        raise TopologyError('PARTIALLY_SEGMENTED_KEYS')
    out=src.replace(ANCHOR,MAPS+ANCHOR,1)
    for key in keys:
        out=out.replace(key,key.replace('";','|accept=$mad4b_css_accept_family";'),1)
    out=out.replace(VARY,VARY+'\n   '+ADDED)
    if len(KEYS.findall(out))!=5 or out.count(ADDED)!=5:
        raise TopologyError('TRANSFORM_INCOMPLETE')
    return out

if __name__=='__main__':
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--input',type=Path,required=True)
    ap.add_argument('--output',type=Path,required=True)
    args=ap.parse_args()
    if args.output.exists() or args.output.resolve()==args.input.resolve():
        ap.error('new output file required')
    args.output.write_text(apply(args.input.read_text()))
    print('CANDIDATE_ONLY: NGINX_PREFLIGHT_AND_RELOAD_REQUIRED')

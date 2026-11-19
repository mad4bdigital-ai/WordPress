#!/usr/bin/env python3
"""Read-only source IP-isolated FastCGI invariant checks. Does not modify server."""
from pathlib import Path
import argparse
import re
import sys
p=argparse.ArgumentParser()
p.add_argument('--config',type=Path,default=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/stack/nginx.conf'))
args=p.parse_args()
s=args.config.read_text()
checks={}
def proof(label,predicate):
    checks[label]=bool(predicate)
    print(('PASS' if predicate else 'FAIL'),label)
def region(start,end):
    assert s.count(start)==1,(start,s.count(start))
    return s.split(start,1)[1].split(end,1)[0]
root=region('location = / {','location ~ ^/(?:ar|fr|de|it|es)/$ {')
proof('home cache key includes realip-restored client address',
      'client=$remote_addr' in root and 'fastcgi_cache_key' in root)
proof('home cache key includes browser family and CSS accept family',
      'ua=$http_user_agent' in root and 'accept=$mad4b_css_accept_family' in root)
proof('home cache key includes language device site and path',
      all(x in root for x in ('$http_accept_language','$host','$request_uri','$mad4b_device_class')))
proof('only GET/HEAD eligible to cache',
      'fastcgi_cache_methods GET HEAD;' in root and 'fastcgi_cache_valid 200 120s;' in root)
proof('origin must opt into public cache and may block storage',
      all(x in root for x in ('$company_perf_no_origin_optin','$company_perf_upstream_private','$company_perf_private_policy','$upstream_http_set_cookie')))
proof('both bypass and storage maintain original market admissions',
      'fastcgi_cache_bypass $company_perf_skip;' in root and
      'fastcgi_no_cache $company_perf_skip ' in root)
proof('private browser HTML policy independent from edge cache',
      'add_header Cache-Control $mad4b_browser_html_coherence always;' in root)
proof('market/visitor IP gate is part of admission',
      'map "$company_perf_wrong_host$mad4b_effective_cookie_unsafe_v2$company_perf_has_auth$company_perf_has_query$company_perf_unsafe_method$company_perf_has_context_headers$mad4b_market_html_block" $company_perf_skip' in s)
proof('missing certified market only permits bounded public client IP',
      'map "$mad4b_uncertified_market_html|$mad4b_public_visitor_ip_bound" $mad4b_market_html_block' in s and
      '"1|1" 0;' in s and
      'map $remote_addr $mad4b_public_visitor_ip_bound {' in s)
proof('real client IP restores only two explicitly trusted proxy IPs',
      set(re.findall(r'^\s*set_real_ip_from\s+([^;]+);',s,re.M))=={'172.16.1.18','172.16.34.6'} and
      re.search(r'^\s*real_ip_header X-Forwarded-For;',s,re.M) is not None and
      re.search(r'^\s*real_ip_recursive on;',s,re.M) is not None)
proof('untrusted Geo/Country/Currency inbound context disables cache',
      '$http_x_mad4b_currency' in s and '$http_x_geo_country' in s and
      '$http_cf_ipcountry' in s and '$http_x_country_code' in s and
      '$company_perf_has_context_headers' in s)
proof('untrusted/extra cookies and request arguments are bypass reasons',
      'map $http_cookie $mad4b_tracked_cookie_safe_v2' in s and
      'map $args $company_perf_has_query { default 1;' in s and
      'map $http_authorization $company_perf_has_auth { default 1;' in s)
proof('full cross-IP public market cache is not enabled',
      'map $host $mad4b_uncertified_market_html { default 1; }' in s and
      'mad4b-ip-market-v1|client=$remote_addr' in root and
      'public-market-cross-ip-v1' not in root)
if not all(checks.values()):
    print('SOURCE_FASTCGI_IP_BOUND_POLICY_HOLD',sum(checks.values()),'/',len(checks))
    raise SystemExit(9)
print('SOURCE_FASTCGI_IP_BOUND_POLICY_VERIFIED',len(checks),'NO_NGINX_MUTATION')

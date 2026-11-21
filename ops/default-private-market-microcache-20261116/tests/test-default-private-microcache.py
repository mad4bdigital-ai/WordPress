"""Static invariants for Tours.dev market-isolated Nginx config."""
import pathlib,re,sys
source=pathlib.Path(sys.argv[1]).read_text()
assert source.count('fastcgi_cache_key "mad4b-ip-market-v1|client=$remote_addr|ua=$http_user_agent|lang=$http_accept_language|')==5
assert source.count('fastcgi_cache_valid 200 120s;')==5
assert source.count('fastcgi_param MAD4B_IP_MARKET_CACHE_GATE "v1";')>=5
assert "map $remote_addr $mad4b_public_visitor_ip_bound {" in source
assert 'map $host $mad4b_uncertified_market_html { default 1; }' in source
assert 'map "$mad4b_uncertified_market_html|$mad4b_public_visitor_ip_bound" $mad4b_market_html_block {' in source
assert 'map $upstream_http_x_mad4b_cache_eligibility $company_perf_no_origin_optin' in source
assert 'fastcgi_cache_bypass $company_perf_skip;' in source
assert source.count('fastcgi_no_cache $company_perf_skip $company_perf_no_origin_optin $company_perf_upstream_private $company_perf_private_policy $upstream_http_set_cookie;')==5
assert re.search(r'0\|0\|0\|0\|\|1"\s+"private, no-store, max-age=0"',source)
assert 'X-MAD4B-QA-Gates' not in source
assert 'X-MAD4B-Cache-Diag' not in source
assert 'set_real_ip_from 172.16.1.18;' in source
assert 'set_real_ip_from 172.16.34.6;' in source
print("PASS 14/14 private IP-market FastCGI source invariants")

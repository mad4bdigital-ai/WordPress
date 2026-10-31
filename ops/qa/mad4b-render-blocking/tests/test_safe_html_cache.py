from pathlib import Path
conf=(Path(__file__).resolve().parents[1]/'nginx-staging-public-cache.conf').read_text()
assert 'fastcgi_cache_path /var/cache/nginx/etg_woo_perf_v1' in conf
start=conf.index('# BEGIN MAD4B ANONYMOUS HOMEPAGE MICROCACHE STAGING')
end=conf.index('# END MAD4B ANONYMOUS HOMEPAGE MICROCACHE STAGING',start)
block=conf[start:end]
assert block.count('fastcgi_cache_use_stale off;')==3
assert 'fastcgi_cache_background_update on' not in block
assert block.count('fastcgi_cache_bypass $company_perf_skip;')==3
assert block.count('fastcgi_no_cache $company_perf_skip $company_perf_no_origin_optin $upstream_http_set_cookie;')==3
assert block.count('fastcgi_cache_valid 200 30s;')==2
assert block.count('fastcgi_cache_valid 200 15s;')==1
for token in ('$http_cookie','$http_authorization','$http_x_mad4b_currency','$http_x_geo_country','$http_cf_ipcountry','$args'):
    assert token in conf,token
print('PASS: anonymous locale-separated HTML cache preserves session, authorization, FX, geo bypass and no unsafe background stale refresh')

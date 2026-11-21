#!/usr/bin/env python3
"""Fail-closed FastCGI cohort partition preflight; READ-ONLY.

A client-side random sample or a private candidate receipt is NOT proof that
1% of real visitors can safely receive different HTML. Require a trusted,
origin-generated cohort dimension in either the cache key or BOTH bypass and
no-cache and the exact corresponding FastCGI param.
"""
import argparse,hashlib,json,pathlib,re
from datetime import datetime,timezone

LOCATIONS={
 'source':('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/stack/nginx.conf',
           'tours.dev.mad4b.com'),
 'wayfa':('/opt/wayfa-rebuild-20261102/stack/nginx.conf','wayfatours.com')
}
VAR='$mad4b_css_rollout_cohort'
PARAM='MAD4B_CSS_ROLLOUT_COHORT'

def verify(site,config,host):
 if site not in LOCATIONS or not isinstance(config,str):
  raise ValueError('unenrolled_site')
 # Remove comments; do not mistake a previous backup or dormant map for
 # active output. This is a conservative check, not a full Nginx parser.
 lines=[line.split('#',1)[0].strip() for line in config.splitlines()]
 text='\n'.join(line for line in lines if line)
 keys=re.findall(r'\bfastcgi_cache_key\s+([^;]+);',text)
 bypass=re.findall(r'\bfastcgi_cache_bypass\s+([^;]+);',text)
 no_cache=re.findall(r'\bfastcgi_no_cache\s+([^;]+);',text)
 passed=re.findall(r'\bfastcgi_param\s+'+PARAM+r'\s+([^;]+);',text)
 if not keys or not bypass or not no_cache:raise ValueError('fastcgi_contract_absent')
 all_keys=all(VAR in line for line in keys)
 all_bypass=all(VAR in line for line in bypass)
 all_no_cache=all(VAR in line for line in no_cache)
 forwarded=bool(passed) and all(VAR in line for line in passed)
 # Cookie-triggered bypass without signature verification is NOT safe,
 # even if performance cache headers say private/no-store.
 source_verified='mad4b_css_rollout_origin_attested_v1' in text
 safe=bool(forwarded and source_verified and
           (all_keys or (all_bypass and all_no_cache)))
 return {'contract':'mad4b.adaptive-css.fastcgi-cohort-admission.v1',
         'site':site,'host':host,'active_cache_key_count':len(keys),
         'cache_key_partitioned':all_keys,
         'request_bypass_partitioned':all_bypass and all_no_cache,
         'origin_param_forwarded':forwarded,
         'signed_source_cohort_verified':source_verified,
         'canary_public_delivery_admitted':safe,
         'decision':'ADMIT' if safe else 'HOLD_CACHE_ISOLATION',
         'nginx_sha256':hashlib.sha256(config.encode()).hexdigest()}
if __name__=='__main__':
 p=argparse.ArgumentParser()
 p.add_argument('site',choices=LOCATIONS)
 arg=p.parse_args()
 path,host=LOCATIONS[arg.site]
 f=pathlib.Path(path)
 if f.is_symlink() or not f.is_file() or f.stat().st_uid!=0:raise SystemExit('untrusted_nginx_profile')
 result=verify(arg.site,f.read_text(),host)
 result['audited_at_utc']=datetime.now(timezone.utc).isoformat()
 print(json.dumps(result,sort_keys=True))

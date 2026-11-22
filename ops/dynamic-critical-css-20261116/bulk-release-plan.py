#!/usr/bin/env python3
"""Read-only source-owned bulk CSS V2 planning, no certificate or WP write."""
from pathlib import Path
import collections,hashlib,importlib.util,json,re,sys,time,os
ROOT=Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1')
BASE={
 'source':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/operations/css-cron-v1'),
 'wayfa':Path('/opt/wayfa-rebuild-20261102/operations/css-cron-v1')
}
LIMIT=24
def load(site):
 b=BASE[site];p=b/'renderer/public-route-queue.py'
 spec=importlib.util.spec_from_file_location('css_bulk_queue_'+site,p)
 q=importlib.util.module_from_spec(spec);spec.loader.exec_module(q);return q
def clean_site(site,snapshot=None):
 if site not in BASE:raise ValueError('foreign-site')
 q=load(site);root=ROOT/site
 jobs=sorted((x for x in root.iterdir()
             if x.is_dir() and not x.is_symlink() and
             (x/'catalog.json').is_file() and (x/'site.json').is_file()),
             key=lambda p:p.stat().st_mtime)
 if not jobs:raise ValueError('no-catalog-source')
 source=Path(snapshot) if snapshot else jobs[-1]
 if source not in jobs:raise ValueError('untrusted-catalog-snapshot')
 cat=q.read(source/'catalog.json');state=q.read(source/'site.json')
 report=q.read(root/'source-families-latest.json')
 if cat['host']!=report['host'] or state['site_host']!=cat['host'] or state['critical_css']!='on':
  raise ValueError('source-host-or-policy-mismatch')
 if state['css_epoch']!=report.get('css_epoch'):
  raise ValueError('report-css-epoch-changed')
 if state.get('consumer_v2_sha') is None or not re.fullmatch('[a-f0-9]{64}',state['consumer_v2_sha']):
  raise ValueError('consumer-v2-sha-not-attested')
 if state.get('runtime_sha') is None or not re.fullmatch('[a-f0-9]{64}',state['runtime_sha']):
  raise ValueError('runtime-sha-not-attested')
 rows=q.safe_catalog(cat,site)
 family_counts=collections.Counter()
 routes=[]
 for r in rows:
  if q.independently_certified_home(r):
   continue
  assert 'source_profile' in r and r.get('source_graph_status')=='configured',r.get('path')
  family=(r['language'],r['source_profile'],r['type'])
  family_counts[family]+=1
  commerce=r['type'] not in ('page','post')
  routes.append({
   'route_key':r['route_key'],
   'path':r['path'],'language':r['language'],'type':r['type'],
   'source_profile':r['source_profile'],
   'source_graph_sha256':r.get('source_graph'),
   'revision':r['revision'],
   'market_requirement':('trusted_geo_wcml_preflight' if state.get('wcml_multi_currency') and commerce
                         else 'exact_native_market_preflight'),
   'source_identity_requirement':'fresh_original_wordpress_421_422_css_manifest',
   'release':'released_pending_qa_only_after_exact_route_preflight',
   'qa':'async_post_release',
   'public_cache':'private_no_store_pending_qa'
  })
 if len({r['route_key'] for r in routes})!=len(routes):
  raise ValueError('duplicate-route-key')
 # Give a first turn to each separate Elementor source family; subsequently
 # cycle across family+language. Each page remains an independent CSS grant.
 groups=collections.defaultdict(list)
 for r in routes:groups[(r['language'],r['source_profile'],r['type'])].append(r)
 order={'tours-and-activities':0,'destinations':1,'properties':2,'page':3,'post':4}
 keys=sorted(groups,key=lambda k:(order.get(k[2],5),0 if k[0]=='en' else 1,k[0],k[1]))
 for k in keys:groups[k].sort(key=lambda x:x['path'])
 ordered=[]
 while keys:
  updated=[]
  for k in keys:
   ordered.append(groups[k].pop(0))
   if groups[k]:updated.append(k)
  keys=updated
 waves=[]
 for n,start in enumerate(range(0,len(ordered),LIMIT),1):
  cohort=ordered[start:start+LIMIT]
  waves.append({'wave':n,'count':len(cohort),
                'source_profiles':len({(x['language'],x['source_profile']) for x in cohort}),
                'routes':cohort})
 assert len(ordered)==sum(w['count'] for w in waves)
 return {'contract':'mad4b.css.v2.bulk-release.source-plan.v1',
   'site':site,'host':cat['host'],'planned_at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
   'source_snapshot':str(source),
   'catalog_sha256':hashlib.sha256((source/'catalog.json').read_bytes()).hexdigest(),
   'state_sha256':hashlib.sha256((source/'site.json').read_bytes()).hexdigest(),
   'css_epoch':state['css_epoch'],
   'global_generation':cat.get('global_generation'),
   'listing_generation':cat.get('listing_generation'),
   'runtime_sha':state['runtime_sha'],
   'consumer_v2_sha':state['consumer_v2_sha'],
   'wcml_multi_currency':bool(state.get('wcml_multi_currency')),
   'enabled_currencies':state.get('enabled_currencies',[]),
   'total_catalog_rows':len(rows),
   'separate_home_first_fold':len(rows)-len(ordered),
   'eligible_for_source_preflight':len(ordered),
   'ready_for_public_release':0,
   'waves':waves,
   'policy':{'wave_limit':LIMIT,'max_parallel_browser_on_host':1,
     'market_key':'site|language|route|country|currency',
     'no_url_only_or_cross_route_css_reuse':True,
     'source_and_css_sha_attestation_per_page':True,
     'release_prior_to_performance_QA':True,
     'lease_hours':6,'pending_cache':'private,no-store',
     'post_release':'exact_route_QA_queue_and_independent_revert',
     'source_drift_action':'skip_and_refresh_plan',
     'mutations':'NONE: read-only planning only'}}
def write_plan(site):
 p=clean_site(site);out=ROOT/'bulk-release-plans'/f'{site}-plan-20261122.json'
 if out.is_symlink():raise ValueError('unsafe-plan-path')
 tmp=out.with_name('.'+out.name+'.pending')
 tmp.write_text(json.dumps(p,ensure_ascii=True,indent=2,sort_keys=True)+'\n')
 os.chmod(tmp,0o600);os.replace(tmp,out)
 return p,out
if __name__=='__main__':
 for site in sys.argv[1:] or ['source','wayfa']:
  p,f=write_plan(site)
  print(site,'CATALOG',p['total_catalog_rows'],'BULK_SCOPE',p['eligible_for_source_preflight'],
    'FIRST_FOLD',p['separate_home_first_fold'],'WAVES',len(p['waves']),
    'FIRST_WAVE',p['waves'][0]['count'],'FILE',f)

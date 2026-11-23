#!/usr/bin/env python3
"""Combined latest.json never repeats yesterday's stale site errors."""
import pathlib,tempfile,json,os,sys,importlib.util,time
D=pathlib.Path(sys.argv[1])
s=importlib.util.spec_from_file_location('health_company_rollup',D/'health-audit.py')
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
assert 'source' in m.SITES and 'wayfa' in m.SITES
now=1800000000
ts=lambda n:time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(n))
def put(root,site,at,status,contract='mad4b.critical-css.integrity-audit.v1',row_site=None):
 path=root/('latest-'+site+'.json')
 row={'site':row_site or site,'host':m.SITES[site]['host'],'status':status,
      'issues':[],'warnings':[]}
 doc={'contract':contract,'at_utc':ts(at),'sites':{site:row}}
 path.write_text(json.dumps(doc));path.chmod(0o600)
cases=0
def check(c,msg):
 global cases
 assert c,msg
 cases+=1;print('PASS',msg)
with tempfile.TemporaryDirectory() as t:
 root=pathlib.Path(t)
 c=m.aggregate_company_health(root,now)
 check(all(x['status']=='deferred' for x in c['sites'].values()),
       'no company report is never assumed healthy')
 put(root,'source',now-600,'healthy_with_safe_fallback')
 put(root,'wayfa',now-180,'healthy')
 c=m.aggregate_company_health(root,now)
 check(c['sites']['source']['status']=='healthy_with_safe_fallback'
       and c['sites']['wayfa']['status']=='healthy',
       'independent fresh company reports merge without re-running WordPress')
 check(c['contains_new_css_certification'] is False,
       'read-only rollup never claims a new CSS grant')
 put(root,'source',now-3601,'error')
 c=m.aggregate_company_health(root,now)
 check(c['sites']['source']['status']=='deferred' and
       c['sites']['wayfa']['status']=='healthy',
       'stale old error cannot poison current company aggregate')
 put(root,'source',now-600,'healthy',contract='forged')
 check(m.aggregate_company_health(root,now)['sites']['source']['status']=='deferred',
       'foreign report contract denied')
 put(root,'source',now-600,'healthy',row_site='wayfa')
 check(m.aggregate_company_health(root,now)['sites']['source']['status']=='deferred',
       'cross-company report cannot impersonate another site')
 put(root,'source',now-600,'healthy')
 p=root/'latest-source.json'
 p.unlink();p.symlink_to(root/'latest-wayfa.json')
 check(m.aggregate_company_health(root,now)['sites']['source']['status']=='deferred',
       'symlinked cross-site report cannot grant health')
print('PASS',cases,'current independent source/target audit rollup regressions')

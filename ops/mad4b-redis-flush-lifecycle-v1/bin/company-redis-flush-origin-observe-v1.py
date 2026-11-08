#!/usr/bin/env python3
"""Read-only Redis FLUSHDB attribution by counter deltas and sanitized PHP trace.

No Redis MONITOR/SLOWLOG, no key enumeration, no client data, no Redis mutation.
Never claims PHP causation if Redis changed without a matching local trace.
"""
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

ROOT=Path('/opt/mad4b-company-provisioning')
SITES={
 'tours-dev':{'redis':'redis-wkgtkzta35wkkgouwt6ruljc',
   'content':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content')},
 'wayfatours':{'redis':'redis-guxhp9y6vgh7oouuw8plxrlr',
   'content':Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')}
}
REPORT=ROOT/'reports/company-redis-flush-origin-current.json'
SCRIPT=r"""export REDISCLI_AUTH="$(sed -n 's/^requirepass[[:space:]]\+//p' /usr/local/etc/redis/redis.conf | head -1)"
redis-cli --no-auth-warning INFO commandstats | awk -F, '
/^cmdstat_flushdb:/{print $1;db=1}
/^cmdstat_flushall:/{print $1;all=1}
END{if(!db)print "cmdstat_flushdb:calls=0";if(!all)print "cmdstat_flushall:calls=0"}'
redis-cli --no-auth-warning INFO server | grep -E '^(uptime_in_seconds|run_id):'"""
def read(site):
 c=SITES[site]['redis']
 r=subprocess.run(['docker','exec',c,'sh','-c',SCRIPT],capture_output=True,text=True,timeout=17)
 if r.returncode:raise RuntimeError('REDIS_STATS_UNAVAILABLE')
 match=re.search(r'(?m)^cmdstat_flushdb:calls=(\d+)',r.stdout)
 uptime=re.search(r'(?m)^uptime_in_seconds:(\d+)',r.stdout)
 runid=re.search(r'(?m)^run_id:([0-9a-f]+)',r.stdout)
 flushall=re.search(r'(?m)^cmdstat_flushall:calls=(\d+)',r.stdout)
 if not match or not uptime or not runid or not flushall:raise RuntimeError('REDIS_INFO_INCOMPLETE')
 epoch=hashlib.sha256((runid.group(1)+'|'+match.group(1)+'|'+flushall.group(1)).encode()).hexdigest()
 warm_path=Path('/var/lib/mad4b-cache-warm')/(site+'.json')
 warm=json.loads(warm_path.read_text()) if warm_path.is_file() else {}
 prior_count=len(warm.get('completed',[]))
 is_current=warm.get('redis_flush_epoch')==epoch
 warm_state={'expected_routes':warm.get('route_count'),
  'partial_reported_routes':prior_count,
  'partial_verified_routes':prior_count if is_current else 0,
  'epoch_verified':is_current,
  'complete_claimed':warm.get('complete') is True}
 path=ROOT/'reports/redis-flush-root-cause-20261108/traces'/(('source' if site=='tours-dev' else 'wayfatours')+'.log')
 if not path.is_file() or path.is_symlink():raise RuntimeError('FLUSH_ORIGIN_TRACE_UNAVAILABLE')
 safe=[]
 for raw in path.read_text(errors='replace').splitlines()[-100:]:
  # Accept older sanitized frame descriptions while rejecting paths and data.
  if len(raw)>1600:continue
  fields=raw.split(' ',1)
  if len(fields)!=2 or not re.fullmatch(r'[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]{8}\+00:00',fields[0]):
   continue
  origins=re.sub(r'[^A-Za-z0-9_.: >-]', '_', fields[1])
  safe.append(fields[0]+' '+origins)
 return {'flushdb_calls':int(match.group(1)),'uptime_sec':int(uptime.group(1)),
  'redis_run_id':runid.group(1),'php_trace_count':len(safe),
  'latest_php_trace':safe[-1] if safe else None,
  'warm_progress':warm_state}
def evaluate(prev,current):
 prior=prev or {}
 delta=None
 reason='BASELINE_FIRST_OBSERVATION'
 if prior.get('redis_run_id')==current.get('redis_run_id'):
  delta=max(0,current['flushdb_calls']-prior['flushdb_calls'])
  reason='NO_FLUSH_SINCE_LAST_OBSERVATION' if delta==0 else 'REDIS_FLUSHDB_INCREASED'
 elif prior:reason='REDIS_INSTANCE_RESTARTED'
 trace_delta=max(0,current['php_trace_count']-prior.get('php_trace_count',0))
 if delta and trace_delta:label='PHP_CALLER_CAPTURED'
 elif delta:label='FLUSH_NOT_ATTRIBUTED_TO_PHP_TRACE'
 else:label='NO_NEW_FLUSH'
 return {'flushdb_delta':delta,'php_trace_delta':trace_delta,
  'reason':reason,'attribution':label}
def atomic(path,data):
 fd,temp=tempfile.mkstemp(prefix='.redis-flush-obs-',dir=path.parent)
 with os.fdopen(fd,'w') as f:
  json.dump(data,f,indent=2,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
 os.chmod(temp,0o600);os.replace(temp,path)
def main():
 old=json.loads(REPORT.read_text()) if REPORT.exists() else {}
 sites={}
 for name in SITES:
  try:
   cur=read(name)
   cur.update(evaluate(old.get('sites',{}).get(name),cur))
   cur['status']='OBSERVED'
  except Exception as exc:
   cur={'status':'UNAVAILABLE','failure_type':type(exc).__name__}
  sites[name]=cur
 report={'contract':'mad4b.redis-flush-origin-observer.v1',
  'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
  'audit_only':True,
  'uses_redis_monitor':False,'issues_commands':False,'does_not_flush_redis':True,
  'sites':sites}
 atomic(REPORT,report)
 print(json.dumps({'timestamp':report['observed_utc'],
  'sites':{n:{'status':v['status'],'delta':v.get('flushdb_delta'),
  'attribution':v.get('attribution'),'trace_events':v.get('php_trace_count')}
  for n,v in sites.items()}},separators=(',',':')))
 return 0 if all(v['status']=='OBSERVED' for v in sites.values()) else 2
if __name__=='__main__':sys.exit(main())

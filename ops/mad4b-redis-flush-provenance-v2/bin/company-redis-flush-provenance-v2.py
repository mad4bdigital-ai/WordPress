#!/usr/bin/env python3
"""Redis FLUSHDB/FLUSHALL counter-to-WordPress caller reconciliation.

All events are JSON emitted by a site-owned, fail-open wp_cache_flush
observer. No Redis monitor, key enumeration, WP mutation or log of PHP args.
"""
import argparse
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

ROOT=Path('/opt/mad4b-company-provisioning')
DIR=ROOT/'reports/redis-flush-provenance-v2-20261108'
REPORT=ROOT/'reports/company-redis-flush-provenance-current.json'
SITES={
 'tours-dev':{
  'redis':'redis-wkgtkzta35wkkgouwt6ruljc',
  'file':'/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins/.mad4b-host-telemetry/redis-flush-events-v2.jsonl'},
 'wayfatours':{
  'redis':'redis-guxhp9y6vgh7oouuw8plxrlr',
  'file':'/opt/wayfa-rebuild-20261102/site-copy/wp-content/mu-plugins/.mad4b-host-telemetry/redis-flush-events-v2.jsonl'},
}

# Observation-only SHA allowlist. Unknown LiteSpeed upgrades create an alert;
# this observer NEVER repairs, replaces, or rolls back vendor code.
INSTRUMENTATION={
 'tours-dev':{
  'wrapper':'/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/litespeed-cache/src/object.lib.php',
  'wrapper_sha256':'fba4fa7189d1506eac28fed65cfc2d415a52448dd482b364812f837bcfca6867',
  'emitter':'/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins/000-mad4b-redis-flush-provenance.php',
  'emitter_sha256':'21c90f47b191b7aaaabb62e632f6a21f21b1b8d12d5c7671fa779ef4a4b1e42b'},
 'wayfatours':{
  'wrapper':'/opt/wayfa-rebuild-20261102/site-copy/wp-content/plugins/litespeed-cache/src/object.lib.php',
  'wrapper_sha256':'fba4fa7189d1506eac28fed65cfc2d415a52448dd482b364812f837bcfca6867',
  'emitter':'/opt/wayfa-rebuild-20261102/site-copy/wp-content/mu-plugins/000-mad4b-redis-flush-provenance.php',
  'emitter_sha256':'041f30fd3f2b624bb27dd2bd0454d24e6532050cc6e27420b5f5ca031b2b47d9'}
}


def instrumentation_integrity(site):
    # Read-only SHA check; original Release Guard policy is unchanged.
    expected=INSTRUMENTATION[site]
    details={}
    for kind in ('wrapper','emitter'):
        file=Path(expected[kind])
        if not file.is_file() or file.is_symlink():
            details[kind]='MISSING_OR_UNSAFE_FILE'
            continue
        digest=hashlib.sha256(file.read_bytes()).hexdigest()
        details[kind]='PASS' if digest==expected[kind+'_sha256'] else 'HASH_DRIFT'
    return {'state':'PASS' if all(x=='PASS' for x in details.values())
            else 'PROVENANCE_HOOK_DRIFT_ALERT','components':details}


REDIS_INFO=r"""export REDISCLI_AUTH="$(sed -n 's/^requirepass[[:space:]]\+//p' /usr/local/etc/redis/redis.conf | head -1)"
redis-cli --no-auth-warning INFO commandstats | awk -F, '
/^cmdstat_flushdb:/{print $1;db=1}
/^cmdstat_flushall:/{print $1;all=1}
END{if(!db)print "cmdstat_flushdb:calls=0";if(!all)print "cmdstat_flushall:calls=0"}'
redis-cli --no-auth-warning INFO server | grep '^run_id:'"""
SAFE_WORD=re.compile(r'^[a-zA-Z0-9._:-]{1,110}$')
SAFE_FRAME=re.compile(r'^[a-zA-Z0-9._:-]{1,110}$')
SAFE_ID=re.compile(r'^[0-9a-f]{24}$')
SAFE_SITE=set(SITES)
ALLOWED_EXEC={'wpcli','web','cron'}
ALLOWED_COMMAND={'none','eval','eval-file','cache','cli'}
SAFE_TIME=re.compile(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$')


def redis_stats(site):
 c=SITES[site]['redis']
 p=subprocess.run(['docker','exec',c,'sh','-c',REDIS_INFO],
                  capture_output=True,text=True,timeout=20)
 if p.returncode:raise RuntimeError('REDIS_COMMANDSTATS_UNAVAILABLE')
 vals={}
 for key in ('flushdb','flushall'):
  m=re.search(r'(?m)^cmdstat_'+key+r':calls=(\d+)',p.stdout)
  if not m:raise RuntimeError('REDIS_'+key+'_COUNTER_MISSING')
  vals[key+'_calls']=int(m.group(1))
 m=re.search(r'(?m)^run_id:([0-9a-f]{10,})',p.stdout)
 if not m:raise RuntimeError('REDIS_RUN_ID_MISSING')
 vals['run_id']=m.group(1)
 return vals


def validate_event(obj,site):
 if not isinstance(obj,dict):raise ValueError('EVENT_NOT_OBJECT')
 if obj.get('contract')!='mad4b.redis-flush-provenance.v2':raise ValueError('EVENT_CONTRACT')
 if obj.get('site')!=site:raise ValueError('EVENT_WRONG_COMPANY')
 if not isinstance(obj.get('event_id'),str) or not SAFE_ID.fullmatch(obj['event_id']):
  raise ValueError('EVENT_INVALID_ID')
 if not isinstance(obj.get('timestamp_utc'),str) or not SAFE_TIME.fullmatch(obj['timestamp_utc']):
  raise ValueError('EVENT_INVALID_TIME')
 if obj.get('execution') not in ALLOWED_EXEC or obj.get('command') not in ALLOWED_COMMAND:
  raise ValueError('EVENT_INVALID_EXECUTION')
 if type(obj.get('successful')) is not bool:raise ValueError('EVENT_RESULT_INVALID')
 for key in ('script_basename','caller_basename','workflow'):
  val=obj.get(key)
  if not isinstance(val,str) or not SAFE_WORD.fullmatch(val):
   raise ValueError('EVENT_NAME_UNSAFE')
 frames=obj.get('frames')
 if not isinstance(frames,list) or len(frames)>9:
  raise ValueError('EVENT_FRAMES_INVALID')
 if any(not isinstance(x,str) or not SAFE_FRAME.fullmatch(x) for x in frames):
  raise ValueError('EVENT_FRAME_UNSAFE')
 # Never use unvalidated extra event fields in archived or reported content.
 return {k:obj[k] for k in (
  'contract','event_id','timestamp_utc','site','execution','command',
  'script_basename','caller_basename','workflow','successful','frames')}


def collect_events(site):
 path=Path(SITES[site]['file'])
 if not path.is_file() or path.is_symlink():
  raise RuntimeError('SITE_EVENT_LOG_NOT_REGULAR')
 if path.stat().st_size>2200000:
  raise RuntimeError('SITE_EVENT_LOG_OVERFLOW')
 archived=[]
 with path.open('r+',encoding='utf-8') as stream:
  fcntl.flock(stream.fileno(),fcntl.LOCK_EX)
  text=stream.read()
  if text and not text.endswith('\n'):
   raise ValueError('PARTIAL_SITE_EVENT_LOG')
  for line in text.splitlines():
   if not line:continue
   if len(line)>2200:raise ValueError('OVERSIZED_EVENT')
   archived.append(validate_event(json.loads(line),site))
  if archived:
   # Only sanitized data ever enters root-owned audit files.
   day=dt.datetime.now(dt.timezone.utc).strftime('%Y%m%d')
   archive=DIR/'events'/f'{day}-{site}.jsonl'
   archive.parent.mkdir(parents=True,exist_ok=True)
   with archive.open('a',encoding='utf-8') as f:
    os.chmod(archive,0o600)
    for row in archived:f.write(json.dumps(row,sort_keys=True,separators=(',',':'))+'\n')
    f.flush();os.fsync(f.fileno())
   stream.seek(0);stream.truncate()
   stream.flush();os.fsync(stream.fileno())
  fcntl.flock(stream.fileno(),fcntl.LOCK_UN)
 return archived


def attribution(events,delta,prior_exists,run_changed):
 good=[x for x in events if x['successful']]
 named=[x for x in good if
     x['script_basename'] not in ('unknown','unrecognized','stdin')
     or x['workflow']!='unassigned']
 if run_changed:
  return 'REDIS_RESTART_REBASELINE'
 if not prior_exists:
  return 'FIRST_BASELINE_NO_CAUSAL_CLAIM'
 if delta==0:
  return 'NO_NEW_FLUSH' if not good else 'PHP_FLUSH_REPORTED_WITHOUT_REDIS_DELTA'
 if delta>len(good):
  return 'UNATTRIBUTED_FLUSH_ALERT'
 if not named:
  return 'FLUSH_SOURCE_UNRESOLVED_ALERT'
 if len(named)<delta:
  return 'PARTIALLY_ATTRIBUTED_FLUSH_ALERT'
 return 'PHP_FLUSH_CORRELATED_WITH_REDIS_COUNTER'


def atomically_write(path,obj):
 path.parent.mkdir(parents=True,exist_ok=True)
 fd,temp=tempfile.mkstemp(prefix='.mad4b-flush-provenance-',dir=path.parent)
 try:
  with os.fdopen(fd,'w') as f:
   json.dump(obj,f,indent=2,sort_keys=True)
   f.write('\n');f.flush();os.fsync(f.fileno())
  os.chmod(temp,0o600);os.replace(temp,path)
 finally:
  if os.path.exists(temp):os.unlink(temp)


def reconcile(previous,site,stats,events):
 old=previous.get('sites',{}).get(site,{})
 same_run=stats['run_id']==old.get('run_id')
 known='run_id' in old
 delta=None
 if same_run:
  delta=max(0,stats['flushdb_calls']-int(old.get('flushdb_calls',0)))
  delta+=max(0,stats['flushall_calls']-int(old.get('flushall_calls',0)))
 identifiers=[{'time':e['timestamp_utc'],'script':e['script_basename'],
    'caller_file':e['caller_basename'],'workflow':e['workflow'],
    'execution':e['execution'],'command':e['command'],
    'event_id':e['event_id'],'successful':e['successful']} for e in events[-30:]]
 new_good=[x for x in identifiers if x['successful']]
 backlog=old.get('pending_successful_events',[])
 if not isinstance(backlog,list):backlog=[]
 previous_unmatched=int(old.get('pending_unattributed_delta',0))
 if not known or not same_run:
  status='REDIS_RESTART_REBASELINE' if known else 'FIRST_BASELINE_NO_CAUSAL_CLAIM'
  new_pending=new_good
  unmatched=0
 else:
  pending=[*backlog,*new_good]
  expected=delta+previous_unmatched
  matched=pending[:expected]
  new_pending=pending[expected:]
  unmatched=max(0,expected-len(pending))
  named=[x for x in matched if
     x.get('script') not in ('unknown','unrecognized','stdin')
     or x.get('caller_file') not in ('unknown',None)
     or x.get('workflow')!='unassigned']
  if unmatched:
   status='UNATTRIBUTED_FLUSH_ALERT' if previous_unmatched else 'PENDING_ATTRIBUTION'
  elif matched and len(named)<len(matched):
   status='FLUSH_SOURCE_UNRESOLVED_ALERT'
  elif matched:
   status='PHP_FLUSH_CORRELATED_WITH_REDIS_COUNTER'
  elif new_pending:
   status='PENDING_COUNTER_CONFIRMATION'
  else:
   status='NO_NEW_FLUSH'
 if len(new_pending)>60:
  status='PROVENANCE_EVENT_BACKLOG_ALERT'
  new_pending=new_pending[-60:]
 return {**stats,'status':status,'count_delta':delta,
  'captured_event_count':len(events),
  'successful_php_flush_count':len(new_good),
  'captured_named_callers':sum(
     x['script'] not in ('unknown','unrecognized','stdin')
     or x.get('caller_file') not in ('unknown',None)
     or x['workflow']!='unassigned' for x in new_good),
  'pending_unattributed_delta':unmatched,
  'pending_successful_events':new_pending,
  'recent_callers':identifiers}


def main():
 arg=argparse.ArgumentParser()
 arg.add_argument('--report',default=str(REPORT))
 arg.add_argument('--strict',action='store_true')
 args=arg.parse_args()
 target=Path(args.report)
 prev=json.loads(target.read_text()) if target.is_file() else {}
 if prev and prev.get('contract')!='mad4b.redis-flush-provenance-reconciler.v2':
  raise ValueError('PREVIOUS_CONTRACT_MISMATCH')
 out={'contract':'mad4b.redis-flush-provenance-reconciler.v2',
  'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
  'no_redis_mutations':True,'payload_values_or_client_addresses_collected':False,
  'sites':{}}
 alerts=[]
 for site in SITES:
  try:
   stats=redis_stats(site)
   events=collect_events(site)
   result=reconcile(prev,site,stats,events)
   if result['status'].endswith('_ALERT'):
    alerts.append({'site':site,'reason':result['status'],
      'counter_delta':result['count_delta'],
      'captured_named_callers':result['captured_named_callers'],
      'captured_events':result['captured_event_count']})
  except Exception as exc:
   result={'status':'ATTRIBUTION_OBSERVER_UNAVAILABLE',
     'failure_type':type(exc).__name__}
   alerts.append({'site':site,'reason':'ATTRIBUTION_OBSERVER_UNAVAILABLE'})
  try:
   integrity=instrumentation_integrity(site)
  except (OSError,ValueError):
   integrity={'state':'PROVENANCE_HOOK_DRIFT_ALERT',
     'components':{'readback':'UNAVAILABLE'}}
  result['instrumentation_integrity']=integrity
  if integrity['state']!='PASS':
   alerts.append({'site':site,'reason':'PROVENANCE_HOOK_DRIFT_ALERT'})
  out['sites'][site]=result
 out['alerts']=alerts
 out['verdict']='ALERT' if alerts else 'PASS'
 atomically_write(target,out)
 alert_path=ROOT/'reports/company-redis-flush-provenance-alert-current.json'
 atomically_write(alert_path,{'contract':'mad4b.redis-flush-provenance-alert.v2',
  'observed_utc':out['observed_utc'],'alerts':alerts,
  'status':out['verdict']})
 # Publish a small site-specific readback for WordPress admin only.
 for site,reading in out['sites'].items():
  status=reading.get('status','ATTRIBUTION_OBSERVER_UNAVAILABLE')
  if status.endswith('_ALERT') or status=='ATTRIBUTION_OBSERVER_UNAVAILABLE' or (
      reading.get('instrumentation_integrity',{}).get('state')!='PASS'):
   user_state='ALERT'
  elif status.startswith('PENDING_'):
   user_state='PENDING'
  elif status in ('REDIS_RESTART_REBASELINE','FIRST_BASELINE_NO_CAUSAL_CLAIM'):
   user_state='OBSERVING'
  else:user_state='PASS'
  latest=reading.get('recent_callers',[])
  actor=latest[-1] if latest else {}
  caller=(actor.get('script') if actor.get('script') not in
   (None,'unknown','unrecognized','stdin') else actor.get('caller_file'))
  if not caller or caller=='unknown':caller=actor.get('workflow','unassigned')
  if not caller or caller=='unassigned':caller='not identified'
  integrity_status=reading.get('instrumentation_integrity',{}).get('state')
  if integrity_status!='PASS':status='PROVENANCE_HOOK_DRIFT_ALERT'
  mini={'contract':'mad4b.redis-flush-site-status.v2',
    'observed_utc':out['observed_utc'],'site':site,'status':user_state,
    'classification':status,'last_caller':caller,
    'flushdb_calls':reading.get('flushdb_calls','unknown'),
    'flushall_calls':reading.get('flushall_calls','unknown'),
    'count_delta':reading.get('count_delta','unknown')}
  statuspath=Path(SITES[site]['file']).with_name('redis-flush-status-v2.json')
  # Host owns the target; the PHP user can only read the safe, small summary.
  fd,temporary=tempfile.mkstemp(prefix='.redis-flush-status-',dir=statuspath.parent)
  try:
   with os.fdopen(fd,'w') as f:
    json.dump(mini,f,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
   os.chmod(temporary,0o640)
   os.chown(temporary,0,33)
   os.replace(temporary,statuspath)
  finally:
   if os.path.exists(temporary):os.unlink(temporary)
 # Emit an operator journal alert only on transition/changed alert context.
 previous_alerts=prev.get('alerts',[])
 if alerts and alerts!=previous_alerts:
  for item in alerts:
   subprocess.run(['logger','-p','daemon.warning','-t','mad4b-redis-flush',
     'MAD4B_REDIS_FLUSH_ALERT site='+item['site']+' reason='+item['reason']],
     capture_output=True,timeout=8)
 print(json.dumps({'verdict':out['verdict'],
    'sites':{k:{'status':v.get('status'),'count_delta':v.get('count_delta'),
                'named_callers':v.get('captured_named_callers')}
     for k,v in out['sites'].items()}},sort_keys=True))
 return 2 if args.strict and alerts else 0

if __name__=='__main__':sys.exit(main())

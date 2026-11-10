#!/usr/bin/env python3
"""Single-slot coordinator for registered central WordPress CLI services.
Only known, fixed service commands can run. No new broker Jobs or grants.
Each active job retains its own app/WordPress permissions and readback rules.
"""
import argparse,fcntl,json,os,pathlib,subprocess,sys,time
LOCK=pathlib.Path('/run/lock/mad4b-central-wpcli-site.lock')
PRIORITY_PROBE=pathlib.Path('/opt/mad4b-company-provisioning/bin/wpcli-signed-priority.py')


def priority_probe():
 # The central DB hint is read-only, outside the WordPress WP-CLI two-slot
 # guard. It NEVER authenticates a Job; only original 305/312 may do that.
 try:
  r=subprocess.run(['/usr/bin/python3',str(PRIORITY_PROBE),'status'],
      capture_output=True,text=True,check=False,timeout=18)
  if r.returncode:
   return {'state':'PRIORITY_SIGNAL_UNAVAILABLE','defer_optional':False}
  data=json.loads(r.stdout.strip().splitlines()[-1])
  if (not isinstance(data,dict)
      or data.get('contract')!='mad4b.original-signed-wpcli-priority.v1'
      or type(data.get('defer_optional')) is not bool):
   return {'state':'PRIORITY_SIGNAL_INVALID','defer_optional':False}
  return data
 except (OSError,ValueError,IndexError,subprocess.TimeoutExpired):
  return {'state':'PRIORITY_SIGNAL_UNAVAILABLE','defer_optional':False}


def defer_if_signed_priority(unit,probe=priority_probe):
 status=probe()
 if status.get('defer_optional') is True:
  record(state='DEFERRED_FOR_ORIGINAL_SIGNED_WPCLI',unit=unit,
     scope='tours-central:staging',priority_jobs=status.get('jobs_detected',0),
     authorization_changed=False,job_claimed=False,jobs_executed=0,
     retry='NEXT_EXISTING_SYSTEMD_TIMER')
  return True
 return False
# Commands preserve the exact original systemd ExecStart, including per-service locks.
SPECS={
 'mad4b-company-native-handler-staging': [
  '/usr/bin/flock','-n','/run/lock/mad4b-company-native-handler-staging.lock',
  '/usr/bin/python3','/opt/mad4b-company-provisioning/bin/company-native-handler-staging-supervisor.py','once'],
 'mad4b-company-native-contract-reconcile': [
  '/usr/bin/python3','/opt/mad4b-company-provisioning/bin/company-native-contract-auto-reconcile.py','--once'],
 'mad4b-company-reconcile-inventory-refresh': [
  '/usr/bin/python3','/opt/mad4b-company-provisioning/bin/company-reconcile-inventory-refresh-v1.py'],
 'mad4b-geo-content-intake-staging':[
  '/usr/bin/flock','-n','/run/lock/mad4b-geo-content-intake-staging.lock',
  '/usr/local/sbin/mad4b-geo-intake-tours-dev'],
 'mad4b-rail-fare-intake-staging':[
  '/usr/bin/flock','-n','/run/lock/mad4b-rail-fare-intake-staging.lock',
  '/usr/local/sbin/mad4b-rail-fare-reconcile-tours-dev'],
 'mad4b-wpml-content-intake-staging':['/usr/local/sbin/mad4b-wpml-intake-tours-dev'],
 'mad4b-company-staging-readonly-onboard':[
  '/usr/bin/flock','-n','/run/lock/mad4b-company-staging-readonly-onboard.service.lock',
  '/usr/bin/python3','/opt/mad4b-company-provisioning/bin/company-staging-readonly-auto-enroll.py','apply'],
 'mad4b-company-crossssh-wpcli-readiness':[
  '/usr/bin/flock','-n','/run/lock/mad4b-company-crossssh-wpcli-readiness.lock',
  '/usr/bin/python3','/opt/mad4b-company-provisioning/bin/company-crossssh-wpcli-readiness.py','check'],
 'mad4b-native-origin-contentjobs':[
  '/usr/bin/python3','/opt/mad4b-company-transport/readiness-native-origin-v1/native-contentjob-client.py'],
 'mad4b-media-reference-audit@commerce-staging':[
  '/usr/bin/flock','-n','/run/lock/mad4b-media-reference-audit-commerce-staging.lock',
  '/usr/local/libexec/mad4b-media-reference-audit/run.sh'],
 'mad4b-wayfa-full-wpcli-readiness':[
  '/usr/bin/python3','/opt/mad4b-company-provisioning/bin/company-native-full-wpcli-readiness.py'],
}
def record(**data):
 print(json.dumps({'contract':'mad4b.central-wpcli-serial-scheduler.v1',
  **data},sort_keys=True),flush=True)
def main():
 ap=argparse.ArgumentParser()
 ap.add_argument('unit',nargs='?')
 ap.add_argument('--wait',type=int,default=55)
 ap.add_argument('--dry-run',action='store_true')
 ap.add_argument('--selftest',action='store_true')
 args=ap.parse_args()
 if args.selftest:
  assert len(SPECS)==11
  assert all(v and pathlib.Path(v[0]).is_file() for v in SPECS.values())
  assert len({v[0] for v in SPECS.values()})>1
  assert defer_if_signed_priority('selftest',probe=lambda:{'defer_optional':True,'jobs_detected':1})
  assert not defer_if_signed_priority('selftest',probe=lambda:{'defer_optional':False})
  record(state='SELFTEST_PASS',fixed_service_count=len(SPECS),
      original_signed_priority_cases_passed=2,jobs_executed=0);return 0
 if os.geteuid()!=0 or args.unit not in SPECS or not 5<=args.wait<=110:
  record(state='NOT_AUTHORIZED_SERVICE_OR_WAIT',jobs_executed=0);return 2
 command=SPECS[args.unit]
 if args.dry_run:
  record(state='EXACT_SERVICE_COMMAND_REGISTERED',unit=args.unit,scope='tours-central:staging',
         jobs_executed=0);return 0
 # Gate optional work BEFORE waiting for the central scheduler lock; no job
 # is started and no root lock is acquired when original 313 is eligible.
 if defer_if_signed_priority(args.unit):return 0
 fd=os.open(str(LOCK),os.O_RDWR|os.O_CREAT|getattr(os,'O_NOFOLLOW',0),0o600)
 try:
  st=os.fstat(fd)
  if st.st_uid!=0 or (st.st_mode & 0o077):
   record(state='UNTRUSTED_LOCK_FILE',unit=args.unit,jobs_executed=0);return 2
  deadline=time.monotonic()+args.wait
  started=time.monotonic()
  while True:
   try:
    fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
    break
   except BlockingIOError:
    if time.monotonic()>=deadline:
     record(state='DEFERRED_WPCLI_SLOT_BUSY',unit=args.unit,scope='tours-central:staging',
            waited_seconds=round(time.monotonic()-started,2),jobs_executed=0,
            retry='NEXT_EXISTING_SYSTEMD_TIMER')
     return 0
    time.sleep(0.3)
  try:
   # Recheck AFTER acquiring the scheduler lock: another original owner Job
   # may have arrived while this background service was waiting.
   if defer_if_signed_priority(args.unit):return 0
   record(state='SLOT_GRANTED',unit=args.unit,scope='tours-central:staging',
          waited_seconds=round(time.monotonic()-started,2),
          authorization_changed=False)
   outcome=subprocess.run(command,check=False)
   record(state='EXISTING_SERVICE_COMMAND_COMPLETED' if outcome.returncode==0 else
          'EXISTING_SERVICE_RETURNED_NONZERO',unit=args.unit,
          child_exit_code=outcome.returncode,
          native_execution_certified=False,authorization_changed=False)
   return outcome.returncode
  finally:
   fcntl.flock(fd,fcntl.LOCK_UN)
 finally:os.close(fd)
if __name__=='__main__':
 try:sys.exit(main())
 except Exception as err:
  record(state='WPCLI_SCHEDULER_FAILED',reason=type(err).__name__,
         jobs_executed=0)
  sys.exit(2)

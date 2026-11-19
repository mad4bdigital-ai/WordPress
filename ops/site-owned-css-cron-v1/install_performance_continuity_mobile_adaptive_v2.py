#!/usr/bin/env python3
"""Atomically install the source-tracked Wayfa continuity v2 monitor.

Limits writes to a site-owned monitor, its dedicated systemd service unit,
and its existing small observability receipt. No CSS/WordPress/Chromium writes.
"""
import contextlib,fcntl,hashlib,json,os,pathlib,stat,subprocess,sys,tempfile
SRC=pathlib.Path(__file__).with_name('performance-continuity-mobile-adaptive-v2.py')
SRC_UNIT=pathlib.Path(__file__).parent/'systemd/mad4b-performance-continuity-wayfa.service'
SITE=pathlib.Path('/opt/wayfa-rebuild-20261102/operations/css-cron-v1')
TARGET=SITE/'monitor/performance-continuity-mobile-adaptive-v2.py'
UNIT=pathlib.Path('/etc/systemd/system/mad4b-performance-continuity-wayfa.service')
BACKUP=pathlib.Path('/etc/systemd/system/mad4b-performance-continuity-wayfa.service.rollback-20261119')
LOCK='/run/lock/mad4b-wayfa-performance-continuity-install.lock'
OLD_LINE='ExecStart=/usr/bin/python3 /opt/mad4b-company-provisioning/worktrees/pr814-converged-local-20261115/ops/dynamic-critical-css-20261116/performance-continuity-watchdog.py'
NEW_LINE='ExecStart=/usr/bin/python3 '+str(TARGET)

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def atomic(p,raw,mode):
 fd,tmp=tempfile.mkstemp(prefix='.mad4b-perf-watch-',dir=p.parent)
 try:
  with os.fdopen(fd,'wb') as f:
   os.fchmod(f.fileno(),mode);os.fchown(f.fileno(),0,0)
   f.write(raw);f.flush();os.fsync(f.fileno())
  os.replace(tmp,p)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
def run(*args):
 p=subprocess.run(args,capture_output=True,text=True,timeout=34)
 if p.returncode:raise ValueError('command_failed:'+str(args)+':'+p.stderr[:130])
 return p.stdout.strip()
def apply():
 with open(LOCK,'a+') as lf:
  fcntl.flock(lf.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
  if os.geteuid()!=0:raise ValueError('root_required')
  if any(p.is_symlink() for p in (SRC,SRC_UNIT,SITE,UNIT,BACKUP,TARGET)):
   raise ValueError('symlink_not_trusted')
  if BACKUP.exists() or TARGET.exists():raise ValueError('release_already_attempted')
  raw=UNIT.read_bytes();new=SRC_UNIT.read_bytes()
  if raw.decode().count(OLD_LINE)!=1 or new.decode().count(NEW_LINE)!=1:
   raise ValueError('unit_source_or_target_changed')
  if len(new)>2000 or len(SRC.read_bytes())>20000:raise ValueError('too_large')
  # Actual public HTML and headers must independently pass BOTH strategies.
  import importlib.util,time
  s=importlib.util.spec_from_file_location('live_css_monitor_attested',SRC)
  m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
  obs=m.observe()
  if m.evaluate({},obs,int(time.time()))['state']!='healthy':
   raise ValueError('public_html_not_currently_verified_both_locales')
  TARGET.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
  fd=os.open(BACKUP,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
  with os.fdopen(fd,'wb') as f:f.write(raw);f.flush();os.fsync(f.fileno())
  old_report=m.REPORT.read_bytes() if m.REPORT.exists() else None
  installed=False
  try:
   atomic(TARGET,SRC.read_bytes(),0o555)
   if sha(TARGET)!=sha(SRC):raise ValueError('monitor_sha_mismatch')
   atomic(UNIT,new,0o644);installed=True
   run('systemctl','daemon-reload')
   run('systemctl','start','mad4b-performance-continuity-wayfa.service')
   report=m.load(m.REPORT)
   if (report.get('state')!='healthy' or report.get('recovery_priority')!='normal'
       or any(report['routes'][l].get('delivery_verified') is not True for l in ('en','es'))):
    raise ValueError('monitor_post_install_receipt_unverified')
  except Exception:
   if installed:atomic(UNIT,raw,0o644);run('systemctl','daemon-reload')
   if old_report is not None:atomic(m.REPORT,old_report,0o600)
   if TARGET.exists():TARGET.unlink()
   raise
  print(json.dumps({'contract':'mad4b.css.wayfa-adaptive-continuity-install.v2',
   'installed':True,'new_monitor_sha256':sha(TARGET),'unit_sha256':sha(UNIT),
   'rollback_unit_preserved':True,'readback':'healthy','en_es_async_verified':True,
   'native_css_changed':False,'certificates_changed':False,'cron_jobs_changed':False,
   'host_resource_admission_unchanged':True},sort_keys=True))
if __name__=='__main__':
 try:apply()
 except Exception as e:
  print('HOLD_WAYFA_CONTINUITY_INSTALL:'+type(e).__name__+':'+str(e)[:220],file=sys.stderr)
  sys.exit(4)

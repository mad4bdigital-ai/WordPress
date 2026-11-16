#!/usr/bin/env python3
"""First-party additive CAS deployment of NON-SIGNING Original305 telemetry."""
from __future__ import annotations
import argparse,hashlib,json,os,stat,subprocess,tempfile
from pathlib import Path

REPO=Path(__file__).resolve().parents[2]
SOURCE=REPO/'ops/native-source-publisher-v1'
HOST=Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1')
SYSTEMD=Path('/etc/systemd/system')
REG=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled/index.json')
HOLD=HOST/'original305-readiness-ambiguous-write-HOLD.json'
REPORT=Path('/opt/mad4b-company-provisioning/reports/original305-observer-firstparty-v1-20261116')
PIN_REG='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'
PIN_HOLD='21f299380f89b803e70e463541ff953fa5e67f6c3eef963fe6925210aa8c1695'
FILES={
 'original305_hold_triage_v1.py':(SOURCE/'original305_hold_triage_v1.py',HOST/'original305_hold_triage_v1.py','8268de82651a5b2ffbfd4a675c36ac12048da71a492490eeb2c663bb61c83f83',0o600),
 'original305_runtime_observer_v1.py':(SOURCE/'original305_runtime_observer_v1.py',HOST/'original305_runtime_observer_v1.py','a6a5530aa461d8713fcc8a7f1517bfb3ef87d68a256b67468cf61dc0a9c59508',0o600),
 'mad4b-original305-observer.service':(SOURCE/'systemd/mad4b-original305-observer.service',SYSTEMD/'mad4b-original305-observer.service','308701269b3f14c4abb8f38a0a8ae5548c0506a8d7b779dc31b638d7cb9a9acf',0o644),
 'mad4b-original305-observer.timer':(SOURCE/'systemd/mad4b-original305-observer.timer',SYSTEMD/'mad4b-original305-observer.timer','88567827856e47a62545f9153be26c3e95ee7459994961522bae911f5e0523e6',0o644),
}
class InstallDenied(RuntimeError):pass

def require(ok,why):
 if not ok:raise InstallDenied(why)

def sha(b):return hashlib.sha256(b).hexdigest()

def run(*argv,timeout=25):
 p=subprocess.run(list(argv),capture_output=True,text=True,timeout=timeout)
 require(p.returncode==0,'ORIGINAL305_INSTALL_DEPENDENCY_FAILED')
 return p.stdout.strip()

def signer_disabled():
 result=run('systemctl','show','mad4b-original-broker-host-readiness.timer','-p','UnitFileState','-p','ActiveState')
 return 'UnitFileState=disabled' in result and 'ActiveState=inactive' in result

def preflight():
 require(os.geteuid()==0,'INSTALL_ROOT_REQUIRED')
 require(not REPO.is_symlink() and run('git','-C',str(REPO),'status','--porcelain')=='','GIT_SOURCE_UNCLEAN')
 commit=run('git','-C',str(REPO),'rev-parse','HEAD')
 require(REG.is_file() and sha(REG.read_bytes())==PIN_REG,'REGISTRY_V8_CHANGED')
 require(HOLD.is_file() and not HOLD.is_symlink() and sha(HOLD.read_bytes())==PIN_HOLD,
         'HISTORICAL_HOLD_CHANGED')
 require(signer_disabled(),'ORIGINAL_SIGNER_TIMER_MUST_STAY_DISABLED')
 require(HOST.is_dir() and not HOST.is_symlink() and HOST.stat().st_uid==0 and
         stat.S_IMODE(HOST.stat().st_mode)==0o700,'ORIGINAL_HOST_DIR_UNTRUSTED')
 require(SYSTEMD.is_dir() and SYSTEMD.stat().st_uid==0,'SYSTEMD_DIR_UNTRUSTED')
 require(not REPORT.exists(),'FIRSTPARTY_OBSERVER_ALREADY_INSTALLED')
 for _,(src,dst,pin,mode) in FILES.items():
  require(src.is_file() and not src.is_symlink() and src.stat().st_uid==0,
          'OBSERVER_SOURCE_FILE_UNTRUSTED')
  require(sha(src.read_bytes())==pin,'OBSERVER_SOURCE_HASH_CHANGED')
  blob=subprocess.run(['git','-C',str(REPO),'show',commit+':'+str(src.relative_to(REPO))],capture_output=True,timeout=25)
  require(blob.returncode==0 and blob.stdout==src.read_bytes(),'OBSERVER_SOURCE_NOT_COMMITTED')
  require(not dst.exists() and not dst.is_symlink(),'OBSERVER_DESTINATION_ALREADY_USED')
 return {'commit':commit,'registry_sha256':PIN_REG,'hold_sha256':PIN_HOLD,
         'observer_timer_active':False,'original_signer_timer_active':False,
         'original_hold_changed':False,'master_approval_created':False,
         'host_release_authorized':False,'source_hashes':{key:values[2] for key,values in FILES.items()}}

def atomic(path:Path,data:bytes,mode:int):
 fd,tmp=tempfile.mkstemp(prefix='.original305-observer-firstparty-',dir=path.parent)
 try:
  os.fchmod(fd,mode);os.fchown(fd,0,0)
  with os.fdopen(fd,'wb') as file:file.write(data);file.flush();os.fsync(file.fileno())
  require(not path.exists(),'OBSERVER_PATH_RACE')
  os.link(tmp,path,follow_symlinks=False)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)

def install():
 proof=preflight();REPORT.mkdir(mode=0o700)
 created=[];enabled=False
 try:
  for name,(src,dst,pin,mode) in FILES.items():
   require(sha(src.read_bytes())==pin,'OBSERVER_SOURCE_CHANGED_DURING_INSTALL')
   atomic(dst,src.read_bytes(),mode);created.append(dst)
  run('systemctl','daemon-reload')
  check=run('/usr/bin/python3',str(HOST/'original305_runtime_observer_v1.py'),'--check',timeout=100)
  status=json.loads(check)
  require(status.get('contract')=='mad4b.original305-diagnostic-snapshot.v1' and
          status.get('legacy_hold_present') is True and status.get('timer_enabled') is False,
          'OBSERVER_SIGNER_HOLD_PROOF_INVALID')
  published=json.loads(run('/usr/bin/python3',str(HOST/'original305_runtime_observer_v1.py'),'--publish',timeout=135))
  require(published.get('state')=='ORIGINAL305_OBSERVER_PUBLISHED' and
          published.get('host_release_authorized') is False,'WORDPRESS_OBSERVER_NOT_VERIFIED')
  run('systemctl','enable','--now','mad4b-original305-observer.timer',timeout=30)
  enabled=True
  timer=run('systemctl','show','mad4b-original305-observer.timer','-p','UnitFileState','-p','ActiveState')
  require('UnitFileState=enabled' in timer and 'ActiveState=active' in timer,
          'AUTONOMOUS_OBSERVER_TIMER_NOT_ACTIVE')
  require(signer_disabled() and sha(HOLD.read_bytes())==PIN_HOLD and sha(REG.read_bytes())==PIN_REG,
          'ORIGINAL_SECURITY_BOUNDARY_CHANGED')
  proof.update({'state':'ORIGINAL305_FIRSTPARTY_AUTOMATIC_OBSERVER_INSTALLED',
       'observer_timer_active':True,'periodic_observation_minutes':10,
       'initial_wordpress_telemetry_published':True,
       'original_signer_timer_active':False,'original_hold_changed':False,
       'published_observation_epoch':published.get('observation_epoch')})
  report=REPORT/'FINAL-FIRSTPARTY-ORIGINAL305-AUTOMATIC-OBSERVER.json'
  fd=os.open(report,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
  with os.fdopen(fd,'w') as file:
   json.dump(proof,file,sort_keys=True,indent=2);file.write('\n');file.flush();os.fsync(file.fileno())
  return proof
 except BaseException:
  if enabled:subprocess.run(['systemctl','disable','--now','mad4b-original305-observer.timer'],capture_output=True,timeout=30)
  for path in reversed(created):
   row=next((v for v in FILES.values() if v[1]==path),None)
   if row is not None and path.is_file() and not path.is_symlink() and sha(path.read_bytes())==row[2]:path.unlink()
  subprocess.run(['systemctl','daemon-reload'],capture_output=True,timeout=30)
  raise

if __name__=='__main__':
 p=argparse.ArgumentParser();group=p.add_mutually_exclusive_group(required=True)
 group.add_argument('--check',action='store_true');group.add_argument('--install',action='store_true')
 arg=p.parse_args()
 try:print(json.dumps(install() if arg.install else {'state':'OBSERVER_INSTALL_CAS_VERIFIED_NOT_EXECUTED',**preflight()},sort_keys=True))
 except (InstallDenied,OSError,ValueError,TypeError,subprocess.SubprocessError) as exc:
  print(json.dumps({'state':'ORIGINAL305_OBSERVER_FIRSTPARTY_INSTALL_DENIED',
      'reason_code':str(exc) if isinstance(exc,InstallDenied) else 'INSTALL_DEPENDENCY_UNAVAILABLE',
      'host_release_authorized':False},sort_keys=True))
  raise SystemExit(3) from None

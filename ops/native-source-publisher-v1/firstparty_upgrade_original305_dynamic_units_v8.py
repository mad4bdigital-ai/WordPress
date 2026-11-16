#!/usr/bin/env python3
"""CAS-only original Systemd unit metadata cleanup; never sign, start or clear HOLD."""
from __future__ import annotations
import argparse,hashlib,json,os,subprocess,stat,tempfile
from pathlib import Path

REPO=Path(__file__).resolve().parents[2]
SOURCE=REPO/'ops/native-source-publisher-v1/systemd'
SYS=Path('/etc/systemd/system')
BASE='mad4b-original-broker-host-readiness'
ROOT=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
REPORT=Path('/opt/mad4b-company-provisioning/reports/original305-firstparty-dynamic-systemd-v8-20261116')
PIN_REG='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'
FILES={
 'service':{'src':SOURCE/(BASE+'.v8.DYNAMIC.service'),'dst':SYS/(BASE+'.service'),'sha_before':'1c07d5d24187cafd6ebdec490fbc9248896243ee5896eadd736d23bf3e3e33bf'},
 'timer':{'src':SOURCE/(BASE+'.v8.DYNAMIC.timer'),'dst':SYS/(BASE+'.timer'),'sha_before':'9c8942ea63661210457209d4d5e086472cae5b81a9acd37d315221cd09f971b0'},
}
class FirstPartyDenied(RuntimeError):pass

def require(ok,code):
 if not ok:raise FirstPartyDenied(code)

def sha(data):return hashlib.sha256(data).hexdigest()

def invoke(args):
 p=subprocess.run(args,capture_output=True,text=True,timeout=25)
 require(p.returncode==0,'ORIGINAL_SYSTEMD_ACTION_REJECTED')
 return p.stdout.strip()

def atomically_replace(path,data):
 fd,name=tempfile.mkstemp(prefix='.original305-systemd-cas-',dir=path.parent)
 try:
  os.fchmod(fd,0o644);os.fchown(fd,0,0)
  with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
  os.replace(name,path)
 finally:
  if os.path.exists(name):os.unlink(name)

def plan():
 require(os.geteuid()==0,'ORIGINAL_ROOT_REQUIRED')
 require(ROOT.is_dir() and not ROOT.is_symlink() and ROOT.stat().st_uid==0,'ORIGINAL_REGISTRY_ROOT_INVALID')
 require(sha((ROOT/'index.json').read_bytes())==PIN_REG,'ORIGINAL_V8_REGISTRY_CAS_DRIFT')
 require(SYS.is_dir() and SYS.stat().st_uid==0 and not SYS.is_symlink(),'SYSTEMD_DIR_UNTRUSTED')
 require(not subprocess.check_output(['git','-C',str(REPO),'status','--porcelain']).strip(),'GIT_SOURCE_DIRTY')
 head=invoke(['git','-C',str(REPO),'rev-parse','HEAD'])
 enabled=subprocess.run(['/usr/bin/systemctl','is-enabled',BASE+'.timer'],capture_output=True,text=True,timeout=12)
 require(enabled.returncode in (0,1) and enabled.stdout.strip()=='disabled','TIMER_MUST_BE_DISABLED')
 active=subprocess.run(['/usr/bin/systemctl','is-active',BASE+'.service'],capture_output=True,text=True,timeout=12)
 require(active.stdout.strip() not in ('active','activating'),'SERVICE_MUST_BE_STOPPED')
 require(not REPORT.exists(),'REPORT_ALREADY_EXISTS')
 source={};original={}
 for kind,row in FILES.items():
  file=row['src'];target=row['dst']
  require(file.is_file() and not file.is_symlink() and file.stat().st_uid==0,'SOURCE_FILE_UNTRUSTED')
  rel='ops/native-source-publisher-v1/systemd/'+file.name
  g=subprocess.run(['git','-C',str(REPO),'show',head+':'+rel],capture_output=True,timeout=25)
  require(g.returncode==0 and g.stdout==file.read_bytes(),'GIT_BLOB_DIFFERENT')
  require(target.is_file() and not target.is_symlink() and target.stat().st_uid==0 and target.stat().st_gid==0 and
          target.stat().st_nlink==1,'LIVE_SYSTEMD_UNIT_NOT_ROOT_PRIVATE')
  old=target.read_bytes()
  require(sha(old)==row['sha_before'],'SYSTEMD_PREIMAGE_CAS_DRIFT')
  source[kind]=g.stdout;original[kind]=old
 require(b'original305_readiness_supervisor_v6.py' in source['service'] and
         b'original_broker_host_readiness_refresh_v6.py' not in source['service'] and
         b'Original305 signed readiness refresh via enrolled original Provider Registry' in source['timer'],
         'ORIGINAL_SYSTEMD_DYNAMIC_UNIT_CONTRACT_INVALID')
 return head,source,original

def run(*,apply=False):
 head,source,original=plan()
 result={'contract':'mad4b.original305-firstparty-dynamic-units-v8.v1',
         'state':'DYNAMIC_SYSTEMD_UNITS_CAS_VERIFIED_NOT_INSTALLED',
         'source_commit':head,'registry_v8_verified':True,
         'new_hashes':{k:sha(v) for k,v in source.items()},
         'timer_enabled':False,'host_release_authorized':False,
         'signed_message_created':False,'hold_cleared':False}
 if not apply:return result
 REPORT.mkdir(mode=0o700)
 for k,data in original.items():
  b=REPORT/(k+'-PRE-V8-CAS.unit')
  fd=os.open(b,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
  with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
 changed=[]
 try:
  for k,row in FILES.items():
   require(sha(row['dst'].read_bytes())==row['sha_before'],'SYSTEMD_CONCURRENT_PREIMAGE_DRIFT')
   atomically_replace(row['dst'],source[k]);changed.append(k)
  require(sha((ROOT/'index.json').read_bytes())==PIN_REG,'REGISTRY_CONCURRENT_DRIFT')
  invoke(['/usr/bin/systemctl','daemon-reload'])
  require(all(row['dst'].read_bytes()==source[k] for k,row in FILES.items()),'POSTINSTALL_HASH_MISMATCH')
  state=subprocess.run(['/usr/bin/systemctl','is-enabled',BASE+'.timer'],capture_output=True,text=True,timeout=12)
  require(state.stdout.strip()=='disabled','TIMER_UNEXPECTEDLY_ENABLED')
  result.update({'state':'DYNAMIC_SYSTEMD_UNITS_FIRSTPARTY_INSTALLED','installed':True,'backup_root':str(REPORT)})
  receipt=REPORT/'FINAL-ORIGINAL305-DYNAMIC-UNITS.json'
  fd=os.open(receipt,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
  with os.fdopen(fd,'w') as f:json.dump(result,f,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
  return result
 except BaseException:
  for k in reversed(changed):
   target=FILES[k]['dst']
   if target.read_bytes()==source[k]:atomically_replace(target,original[k])
  subprocess.run(['/usr/bin/systemctl','daemon-reload'],capture_output=True,timeout=25)
  raise

if __name__=='__main__':
 p=argparse.ArgumentParser();g=p.add_mutually_exclusive_group(required=True)
 g.add_argument('--check',action='store_true');g.add_argument('--install',action='store_true')
 a=p.parse_args();print(json.dumps(run(apply=a.install),sort_keys=True))

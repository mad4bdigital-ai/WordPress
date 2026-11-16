#!/usr/bin/env python3
"""Upgrade the enrolled Original305 supervisor to a verified lease-aware singleton renewal policy.

Original Broker V6 and Registry remain immutable; timer must be disabled.
No Hold is cleared, signed message is issued, or timer enabled by this code.
"""
from __future__ import annotations
import argparse,hashlib,json,os,stat,subprocess,tempfile
from pathlib import Path

REPO=Path(__file__).resolve().parents[2]
SRC=REPO/'ops'
HOST=Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1')
REG=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled/index.json')
SERV=Path('/etc/systemd/system/mad4b-original-broker-host-readiness.service')
TIMER='mad4b-original-broker-host-readiness.timer'
OLD=HOST/'original305_readiness_supervisor_v6.py'
NEW=HOST/'original305_readiness_supervisor_v7.py'
REPORT=Path('/opt/mad4b-company-provisioning/reports/host-original305-supervisor-v7-phase-fix-cas-20261116')
SHA_REG='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'
SHA_OLD='be76afdba64e19794e5deac7049d3427be73c4c601ef2ab1bf88a33749dd2b32'
SHA_SERV='94450c714cab69f3651148fc706cc4194bd61d977fcd4133218776cf38443f6b'
SHA_TIMER='d36eb23a554185e9158118d1430e93d324fe2f4eff26e28cbeca024a9ff45383'
SOURCES={
 'native-source-publisher-v1/original305_readiness_supervisor_v7.py':NEW,
 'native-source-publisher-v1/systemd/mad4b-original-broker-host-readiness.v7.DYNAMIC.service':SERV,
}

class UpgradeDenied(RuntimeError):pass

def must(ok,reason):
    if not ok:raise UpgradeDenied(reason)

def sha(data):return hashlib.sha256(data).hexdigest()

def checked(cmd):
    r=subprocess.run(cmd,capture_output=True,text=True,timeout=35)
    must(r.returncode==0,'FIRSTPARTY_COMMAND_FAILED')
    return r.stdout.strip()

def timer_disabled()->bool:
    p=subprocess.run(['/usr/bin/systemctl','is-enabled',TIMER],
                     capture_output=True,text=True,timeout=15)
    # systemctl returns exit 1 for a legitimately disabled, installed timer.
    return p.returncode in (0,1) and p.stdout.strip()=='disabled'

def atomic(path,data,mode):
    fd,temp=tempfile.mkstemp(prefix='.original305-cas-',dir=path.parent)
    try:
        os.fchmod(fd,mode);os.fchown(fd,0,0)
        with os.fdopen(fd,'wb') as out:
            out.write(data);out.flush();os.fsync(out.fileno())
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def plan():
    must(os.geteuid()==0,'ROOT_REQUIRED')
    must(sha(REG.read_bytes())==SHA_REG,'ORIGINAL_V6_REGISTRY_CHANGED')
    must(not subprocess.check_output(['git','-C',str(REPO),'status','--porcelain']).strip(),
         'GIT_WORKTREE_NOT_CLEAN')
    must(HOST.stat().st_uid==0 and stat.S_IMODE(HOST.stat().st_mode)==0o700,
         'HOST_SOURCE_BASE_NOT_PRIVATE')
    must(sha(OLD.read_bytes())==SHA_OLD and sha(SERV.read_bytes())==SHA_SERV,
         'ORIGINAL305_V6_PREIMAGE_DRIFT')
    must(sha((SERV.parent/TIMER).read_bytes())==SHA_TIMER,
         'ORIGINAL305_TIMER_PREIMAGE_CHANGED')
    must(not NEW.exists() and not NEW.is_symlink(),'ORIGINAL305_V7_ALREADY_INSTALLED')
    must(timer_disabled(),
         'ORIGINAL305_TIMER_MUST_BE_DISABLED_DURING_CAS')
    active=subprocess.run(['/usr/bin/systemctl','is-active',TIMER.replace('.timer','.service')],
                          capture_output=True,text=True,timeout=15)
    must(active.stdout.strip()!='active' and active.stdout.strip()!='activating',
         'ORIGINAL305_SERVICE_RUNNING')
    must(not REPORT.exists(),'FIRSTPARTY_REPORT_ALREADY_EXISTS')
    head=checked(['git','-C',str(REPO),'rev-parse','HEAD'])
    blobs={}
    for rel,dest in SOURCES.items():
        src=SRC/rel
        must(src.is_file() and not src.is_symlink() and src.stat().st_uid==0 and
             not src.stat().st_mode&0o022,'SOURCE_UNTRUSTED')
        r=subprocess.run(['git','-C',str(REPO),'show',head+':ops/'+rel],
                         capture_output=True,timeout=25)
        must(r.returncode==0 and r.stdout==src.read_bytes(),'SOURCE_NOT_EXACT_GIT_BLOB')
        blobs[rel]=r.stdout
    return head,blobs

def install(*,apply=False):
    head,blobs=plan()
    code=blobs['native-source-publisher-v1/original305_readiness_supervisor_v7.py']
    unit=blobs['native-source-publisher-v1/systemd/mad4b-original-broker-host-readiness.v7.DYNAMIC.service']
    result={'state':'ORIGINAL305_SUPERVISOR_V7_CAS_VERIFIED_NOT_INSTALLED',
            'commit':head,'supervisor_v7_sha256':sha(code),
            'service_unit_v7_sha256':sha(unit),'registry_v8_intact':True,
            'timer_enabled':False,'signed_message_issued':False,
            'host_release_authorized':False,'existing_ambiguous_hold_cleared':False}
    if not apply:return result
    REPORT.mkdir(mode=0o700)
    backup=REPORT/'ORIGINAL305-SERVICE-PRE-V7.service'
    fd=os.open(backup,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb') as out:
        out.write(SERV.read_bytes());out.flush();os.fsync(out.fileno())
    new_created=False;updated=False
    try:
        fd=os.open(NEW,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'wb') as out:
            out.write(code);out.flush();os.fsync(out.fileno())
        new_created=True
        must(sha(SERV.read_bytes())==SHA_SERV and sha(REG.read_bytes())==SHA_REG,
             'CAS_CHANGED_BEFORE_SERVICE_SWAP')
        atomic(SERV,unit,0o644);updated=True
        checked(['/usr/bin/systemctl','daemon-reload'])
        must(sha(SERV.read_bytes())==sha(unit) and sha(NEW.read_bytes())==sha(code),
             'V7_POSTINSTALL_READBACK_FAILED')
        must(timer_disabled(),
             'TIMER_ENABLED_UNEXPECTEDLY')
        result.update({'state':'ORIGINAL305_SUPERVISOR_V7_CAS_INSTALLED',
                       'installed':True,'timer_enabled':False,
                       'service_backup':str(backup)})
        final=REPORT/'FINAL-SUPERVISOR-V7-INSTALLED.json'
        fd=os.open(final,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'w') as out:
            json.dump(result,out,indent=2,sort_keys=True);out.write('\n');out.flush();os.fsync(out.fileno())
        return result
    except BaseException:
        if updated and sha(SERV.read_bytes())==sha(unit):atomic(SERV,backup.read_bytes(),0o644)
        if new_created and sha(NEW.read_bytes())==sha(code):NEW.unlink()
        subprocess.run(['/usr/bin/systemctl','daemon-reload'],capture_output=True,timeout=25)
        raise

if __name__=='__main__':
    p=argparse.ArgumentParser();g=p.add_mutually_exclusive_group(required=True)
    g.add_argument('--check',action='store_true');g.add_argument('--install',action='store_true')
    args=p.parse_args()
    print(json.dumps(install(apply=args.install),sort_keys=True))

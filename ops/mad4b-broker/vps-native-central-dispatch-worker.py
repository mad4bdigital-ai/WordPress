#!/usr/bin/env python3
"""Bounded Staging-only root inbox worker for signed Native 306/312/314 dispatch.

Never executes jobs from WordPress queue alone; only root-owned manifests
carrying exact local command/readback files. One attempt per manifest;
failures are parked, not re-executed. The systemd unit is the root principal.
"""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

ROOT=Path("/opt/staging/mad4b-central-host-commands/dispatch-inbox")
DISPATCHER=Path("/opt/mad4b-company-transport/broker-v1/vps-native-central-auto-dispatch.py")
CONTRACT="mad4b.native-central-inbox.v1"
NAME=re.compile(r"[A-Za-z0-9_.-]{8,90}\.json")

def root_owned(path):
    st=path.lstat()
    return stat.S_ISREG(st.st_mode) and st.st_uid==0 and not st.st_mode & 0o077 and not path.is_symlink()

def main():
    if os.geteuid()!=0:
        raise RuntimeError("ROOT_ONLY_SERVICE")
    if not (os.environ.get("MAD4B_NATIVE_CENTRAL_DISPATCH")=="1"
        and re.fullmatch("[a-f0-9]{32}",os.environ.get("INVOCATION_ID",""))):
        raise RuntimeError("BOUND_SYSTEMD_INVOCATION_REQUIRED")
    cgroup=Path("/proc/self/cgroup").read_text()
    if "/system.slice/mad4b-native-central-dispatch.service" not in cgroup:
        raise RuntimeError("WRONG_SYSTEMD_UNIT")
    if not ROOT.is_dir() or ROOT.is_symlink() or ROOT.stat().st_uid!=0 or ROOT.stat().st_mode & 0o077:
        raise RuntimeError("ROOT_ONLY_INBOX_REQUIRED")
    for folder in ("completed","held"):
        path=ROOT/folder
        path.mkdir(mode=0o700,exist_ok=True)
        if path.is_symlink() or path.stat().st_uid!=0 or path.stat().st_mode & 0o077:
            raise RuntimeError("ROOT_OWNED_ARCHIVE_REQUIRED")
    lock=ROOT/".worker.lock"
    fd=os.open(str(lock),os.O_RDWR|os.O_CREAT,0o600)
    with os.fdopen(fd,"r+") as f:
        try:fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            print(json.dumps({"state":"OTHER_ORIGINAL_WORKER_RUNNING","execution_performed":False}))
            return
        report={"contract":CONTRACT,"scanned":0,"completed":0,"held":0}
        for path in sorted(ROOT.glob("*.json"))[:3]:
            report["scanned"]+=1
            if not NAME.fullmatch(path.name) or not root_owned(path):
                report["held"]+=1
                # Never open or move ambiguous/symlink/unowned input.
                continue
            result=subprocess.run([sys.executable,str(DISPATCHER),str(path)],
              capture_output=True,text=True,timeout=150)
            valid=False
            if result.returncode==0:
                try:
                    row=json.loads(result.stdout.strip().splitlines()[-1])
                    valid=(row.get("state")=="CENTRAL_JOB_EXECUTION_AND_RECEIPT_VERIFIED"
                        and row.get("production_mutation") is False
                        and row.get("contract")=="mad4b.native-central-dispatch.v1"
                        and re.fullmatch(r"[a-f0-9]{32}",row.get("job_id","")) is not None)
                except (ValueError,IndexError,TypeError):pass
            directory=ROOT/("completed" if valid else "held")
            dest=directory/path.name
            if dest.exists():
                raise RuntimeError("DUPLICATE_MANIFEST_ARCHIVE_NAME")
            os.replace(path,dest)
            # Never print command bytes, original signing secrets or raw job.
            if valid:report["completed"]+=1
            else:report["held"]+=1
        report["state"]="WORKER_SWEEP_COMPLETE"
        print(json.dumps(report,sort_keys=True))
        if report["held"]:raise SystemExit(2)

if __name__=="__main__":
    try:main()
    except (OSError,ValueError,RuntimeError,subprocess.SubprocessError) as exc:
        print("MAD4B_NATIVE_WORKER_DENIED:"+str(exc),file=sys.stderr)
        raise SystemExit(2)

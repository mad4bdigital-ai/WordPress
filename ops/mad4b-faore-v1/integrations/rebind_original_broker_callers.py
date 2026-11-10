#!/usr/bin/env python3
"""One-time, scope-guarded migration of original host Broker WPCLI argv targets.

Patch only the known local enrolled central-staging caller files. Preserve
original Broker code flow and never create/claim jobs. Root-owned originals
are separately backed up before atomic replacement.
"""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path("/opt/mad4b-company-transport")
BACKUP=ROOT/"native-operator-v1/backups/faore-dynamic-actor-20261105/broker-callers"
SCRIPTS=(
    "vps-broker-poller.py",
    "vps-broker-registry-ssh-status-sync.py",
    "vps-broker-journal-sync.py",
    "broker-native-ssh-reconcile.py",
    "remote-exact-canary.py",
    "original-core-channel-evidence.py",
    "vps-broker-remote-ssh-canary.py",
    "vps-broker-fixed-diagnostic.py",
)
OLD=b"etg-commerce-staging-php"
NEW=b"@mad4b-central-staging"


def replace_once(current: bytes) -> bytes:
    if b"wpcli_dynamic_actor" not in current or b"with_actor" not in current:
        raise RuntimeError("BROKER_CALLER_NOT_ENROLLED")
    if current.count(OLD)==0 and current.count(NEW)==1:
        return current
    if current.count(OLD)!=1 or current.count(NEW):
        raise RuntimeError("BROKER_CALLER_TARGET_AMBIGUOUS")
    return current.replace(OLD,NEW,1)


def apply() -> dict:
    if os.geteuid()!=0:
        raise RuntimeError("BROKER_REBIND_ROOT_REQUIRED")
    BACKUP.mkdir(mode=0o700,parents=True,exist_ok=True)
    BACKUP.chmod(0o700)
    prepared=[]
    for name in SCRIPTS:
        path=ROOT/"broker-v1"/name
        stat=path.lstat()
        if path.is_symlink() or not path.is_file() or stat.st_uid!=0 or stat.st_mode & 0o022:
            raise RuntimeError("BROKER_CALLER_FILE_UNSAFE")
        original=path.read_bytes()
        updated=replace_once(original)
        prepared.append((path,original,updated,stat.st_mode & 0o777))
    names=[]
    for path,original,updated,mode in prepared:
        if original==updated:
            names.append(path.name)
            continue
        backup=BACKUP/(path.name+".original")
        if backup.exists():
            if backup.read_bytes()!=original:
                raise RuntimeError("BROKER_BACKUP_MISMATCH")
        else:
            fd=os.open(backup,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
            with os.fdopen(fd,"wb") as stream:
                stream.write(original)
        fd,tmp=tempfile.mkstemp(prefix=".faore-",dir=path.parent)
        try:
            with os.fdopen(fd,"wb") as stream:
                stream.write(updated)
            os.chmod(tmp,mode)
            p=subprocess.run(["/usr/bin/python3","-m","py_compile",tmp],
                             capture_output=True,text=True,check=False,timeout=10)
            if p.returncode:
                raise RuntimeError("BROKER_PATCH_COMPILE_FAILED")
            os.replace(tmp,path)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)
        names.append(path.name)
    return {"state":"ORIGINAL_BROKER_CALLERS_REBOUND",
            "caller_count":len(names),"names":names,
            "jobs_created":False,"write_authorized":False}


if __name__=="__main__":
    print(json.dumps(apply(),sort_keys=True))

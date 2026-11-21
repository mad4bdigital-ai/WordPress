#!/usr/bin/env python3
"""Pin Central original 313 v4 proof config to actual installed Provider v3.

Readback-only Central WordPress config mutation: never issues a Job, Grant,
native receipt, or Code Release. Does not change the v4 finalizer.
"""
from __future__ import annotations
import argparse
import base64
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import registry

ROOT=Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
CENTRAL=Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-platform/modules")
CONFIG=CENTRAL/"authority/dynamic313/config.php"
ORIGINAL_V4=CENTRAL/"services/313b-mad4b-native-independent-verifier.php"
BACKUP=Path("/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108/central-v4-provider3-config-backup.php")

def require(ok,reason):
    if not ok:raise registry.RegistryDenied(reason)

def decode(raw:bytes)->dict:
    m=re.fullmatch(
        rb"<\?php\nreturn json_decode\(base64_decode\('([A-Za-z0-9+/=]+)'\),true\);\n",
        raw)
    require(m is not None,"ORIGINAL_CENTRAL_V4_CONFIG_UNEXPECTED_SHAPE")
    result=json.loads(base64.b64decode(m[1],validate=True))
    require(isinstance(result,dict),"CENTRAL_PROVIDER_CONFIG_NOT_OBJECT")
    return result

def read_expected():
    require(os.geteuid()==0,"FIRST_PARTY_ROOT_REQUIRED")
    index=registry.Registry(ROOT)
    provider=index.resolve("company.code.release")
    require(provider["version"]==3 and
            provider["evidence_schema"]=="code_release.v2" and
            len(provider.get("dependencies",[]))==3,
            "INSTALLED_DEPENDENCY_PINNED_PROVIDER_V3_REQUIRED")
    require(CONFIG.is_file() and not CONFIG.is_symlink() and
            CONFIG.stat().st_uid==0 and
            not stat.S_IMODE(CONFIG.stat().st_mode)&0o022,
            "ORIGINAL_CENTRAL_PROVIDER_CONFIG_NOT_TRUSTED")
    v4=ORIGINAL_V4.read_text()
    require("supports_dynamic_v4" in v4 and
            "enrolled_schema_id" in v4 and
            "recorded_exact_for_original_313" in v4,
            "ORIGINAL_V4_FINALIZER_NOT_ENROLLED")
    old=decode(CONFIG.read_bytes())
    require(old.get("contract")=="mad4b.dynamic-313-first-party-config.v1" and
            old.get("original_313_finalizer_connected") is True and
            old.get("operational_ready") is False and
            old["trusted"]["first_party_enrolled"] is True and
            old["trusted"]["provider"]["version"] in (2,3) and
            old["trusted"]["provider"]["operation_id"]=="company.code.release" and
            old["trusted"]["provider"]["command"]==provider["command"] and
            old["trusted"]["independent_verifier_public_key_b64"]==
              base64.b64encode(index.independent_verifier_public_key).decode(),
            "CENTRAL_ORIGINAL_V4_PROVIDER_CONFIG_DRIFT")
    previous=old["trusted"]["provider"]["version"]
    old["trusted"]["provider"]=provider
    old["trusted"]["evidence_schema"]=index.evidence_definition("company.code.release")
    raw=(
        "<?php\nreturn json_decode(base64_decode('"+
        base64.b64encode(registry.canonical(old)).decode()+
        "'),true);\n"
    ).encode()
    return raw,previous

def install(apply=False):
    new,old_version=read_expected()
    result={
        "state":"CENTRAL_ORIGINAL_V4_PROVIDER_V3_CHECKED_NOT_MUTATED",
        "previous_provider_version":old_version,
        "target_provider_version":3,
        "original_finalizer_changed":False,
        "operational_ready":False,
        "original_job_created":False,
        "grant_issued":False,
        "original_313_receipt_created":False,
        "wayfa_production_modified":False,
    }
    if CONFIG.read_bytes()==new:
        result["state"]="CENTRAL_ORIGINAL_V4_PROVIDER_V3_ALREADY_SYNCED"
        return result
    if not apply:return result
    require(not BACKUP.exists(),"PROTECTED_CENTRAL_V4_CONFIG_BACKUP_ALREADY_EXISTS")
    BACKUP.write_bytes(CONFIG.read_bytes())
    os.chmod(BACKUP,0o600)
    require(BACKUP.stat().st_uid==0 and
            not stat.S_IMODE(BACKUP.stat().st_mode)&0o077,
            "CENTRAL_PROVIDER_BACKUP_NOT_ROOT_PRIVATE")
    fd,temp=tempfile.mkstemp(prefix=".mad4b-first-party-v3-",
                             dir=CONFIG.parent)
    try:
        os.fchmod(fd,0o644)
        os.fchown(fd,0,0)
        with os.fdopen(fd,"wb") as out:
            out.write(new);out.flush();os.fsync(out.fileno())
        require(CONFIG.read_bytes()==BACKUP.read_bytes(),
                "CENTRAL_PROVIDER_CHANGED_DURING_UPGRADE")
        os.replace(temp,CONFIG)
        require(CONFIG.read_bytes()==new and
                decode(CONFIG.read_bytes())["trusted"]["provider"]["version"]==3,
                "CENTRAL_V4_PROVIDER_V3_READBACK_FAILED")
    except BaseException:
        if CONFIG.read_bytes()==new:
            fd,tmp=tempfile.mkstemp(dir=CONFIG.parent)
            with os.fdopen(fd,"wb") as out:out.write(BACKUP.read_bytes())
            os.chmod(tmp,0o644)
            os.replace(tmp,CONFIG)
        raise
    finally:
        if os.path.exists(temp):os.unlink(temp)
    result["state"]="CENTRAL_ORIGINAL_V4_PROVIDER_V3_CONFIG_PINNED"
    result["rollback_path"]=str(BACKUP)
    return result

if __name__=="__main__":
    p=argparse.ArgumentParser()
    g=p.add_mutually_exclusive_group(required=True)
    g.add_argument("--check",action="store_true")
    g.add_argument("--install",action="store_true")
    args=p.parse_args()
    print(json.dumps(install(apply=args.install),sort_keys=True))

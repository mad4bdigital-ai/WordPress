#!/usr/bin/env python3
"""First-party Provider v3 upgrade, protecting transitive Python dependencies.

Original dynamic command digests, Plan identities and independent signer key
remain unchanged. The original Worker and original Central 313 are not changed.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import registry
from first_party_install import atomic_private

SOURCE=Path(__file__).resolve().parents[1]
OPS=SOURCE.parent
INSTALLED=Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
BACKUP=Path("/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108/dynamic-provider-v3-deps-backup")
ITEMS={
    "registry.py":SOURCE/"registry.py",
    "company-code-release-v1/original_313_host_handler.py":OPS/"company-code-release-v1/original_313_host_handler.py",
    "company-code-release-v1/company_lifecycle_broker.py":OPS/"company-code-release-v1/company_lifecycle_broker.py",
    "company-code-release-v1/company_code_release.py":OPS/"company-code-release-v1/company_code_release.py",
    "dynamic-operation-registry-v1/providers/company.code.release/manifest.json":
        SOURCE/"providers/company.code.release/manifest.json",
}

def require(ok,reason):
    if not ok:raise registry.RegistryDenied(reason)

def validate_source():
    require(os.geteuid()==0,"FIRST_PARTY_ROOT_REQUIRED")
    r=registry.Registry(INSTALLED)
    old=r.resolve("company.code.release")
    new=json.loads((SOURCE/"providers/company.code.release/manifest.json").read_text())
    require(old["version"]==2 and new["version"]==3 and
            old["operation_id"]==new["operation_id"] and
            old["command"]==new["command"] and
            old["verifier"]==new["verifier"] and
            old["evidence_schema"]==new["evidence_schema"] and
            old["evidence"]==new["evidence"],
            "PROVIDER_V3_ONLY_DEPENDENCY_UPGRADE_ALLOWED")
    require(len(new["dependencies"])==3,"EXPECTED_PINNED_DEPENDENCIES")
    for key in ("handler","verifier"):
        f=OPS/new[key]["path"]
        require(f.is_file() and registry.sha(f.read_bytes())==new[key]["sha256"],
                "PROVIDER_V3_COMPONENT_NOT_PINNED")
    for row in new["dependencies"]:
        f=OPS/row["path"]
        require(f.is_file() and registry.sha(f.read_bytes())==row["sha256"],
                "PROVIDER_V3_DEPENDENCY_NOT_PINNED")
    gitroot=OPS.parent
    require(subprocess.check_output(["git","-C",str(gitroot),
                                    "status","--porcelain"]).strip()==b"",
            "PROVIDER_V3_SOURCE_NOT_COMMITTED")
    idx=json.loads((INSTALLED/"index.json").read_text())
    require(len(idx["providers"])==1 and
            idx["providers"][0]["manifest"]==
            "dynamic-operation-registry-v1/providers/company.code.release/manifest.json",
            "REGISTRY_INDEX_IDENTITY_CHANGED")
    idx["providers"][0]["sha256"]=registry.sha(
        (SOURCE/"providers/company.code.release/manifest.json").read_bytes())
    idx["upgraded_source_git_commit"]=subprocess.check_output(
        ["git","-C",str(gitroot),"rev-parse","HEAD"],text=True).strip()
    files={INSTALLED/k:v.read_bytes() for k,v in ITEMS.items()}
    for file in files:require(file.is_file(),"EXPECTED_INSTALLED_MODULE_MISSING")
    files[INSTALLED/"index.json"]=registry.canonical(idx)+b"\n"
    return r,files,idx

def run(install):
    old,files,idx=validate_source()
    result={
       "contract":"mad4b.dynamic-provider-v3-transitive-pins.v1",
       "state":"PROVIDER_V3_SOURCE_VERIFIED",
       "source_git_commit":idx["upgraded_source_git_commit"],
       "previous_version":2,"new_version":3,
       "original_command_argv_unchanged":True,
       "independent_signing_key_unchanged":True,
       "signed_job_created":False,"grant_created":False,
       "original_313_receipt_issued":False,"wayfa_mutated":False}
    if not install:return result
    require(not BACKUP.exists(),"EXISTING_V3_BACKUP_REFUSE_REPLAY")
    BACKUP.mkdir(mode=0o700,parents=True)
    os.chmod(BACKUP,0o700)
    originals={p:p.read_bytes() for p in files}
    for p,blob in originals.items():
        f=BACKUP/p.relative_to(INSTALLED)
        f.parent.mkdir(parents=True,exist_ok=True)
        os.chmod(f.parent,0o700)
        atomic_private(f,blob)
    applied=[]
    try:
        for p,blob in files.items():
            if p.name=="index.json":continue
            atomic_private(p.with_name(p.name+".v3-stage"),blob)
            os.replace(p.with_name(p.name+".v3-stage"),p)
            applied.append(p)
        p=INSTALLED/"index.json"
        atomic_private(p.with_name("index.json.v3-stage"),files[p])
        os.replace(p.with_name("index.json.v3-stage"),p)
        applied.append(p)
        new=registry.Registry(INSTALLED)
        require(new.resolve("company.code.release")["version"]==3 and
                new.independent_verifier_public_key==
                old.independent_verifier_public_key,
                "REGISTRY_V3_FIRST_PARTY_READBACK_DENIED")
    except BaseException:
        for p in reversed(applied):
            atomic_private(p.with_name(p.name+".rollback"),originals[p])
            os.replace(p.with_name(p.name+".rollback"),p)
        raise
    result["state"]="PROVIDER_V3_PINNED_DEPENDENCIES_INSTALLED"
    result["backup"]=str(BACKUP)
    return result

if __name__=="__main__":
    p=argparse.ArgumentParser()
    g=p.add_mutually_exclusive_group(required=True)
    g.add_argument("--check",action="store_true")
    g.add_argument("--install",action="store_true")
    arg=p.parse_args()
    print(json.dumps(run(arg.install),sort_keys=True))

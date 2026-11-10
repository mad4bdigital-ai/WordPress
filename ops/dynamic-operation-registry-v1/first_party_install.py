#!/usr/bin/env python3
"""MAD4B authorized one-time first-party dynamic-registry installer.

This installs root-owned source/bindings only. It does NOT alter the original
306 worker/313 finalizer, issue a WordPress Job/Grant, or apply a company release.
Installed index is published last, after all pinned artifacts are staged.
"""
from __future__ import annotations
import argparse
import base64
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization
import registry

SOURCE_OPS=Path(__file__).resolve().parents[1]
SOURCE_REGISTRY=SOURCE_OPS/"dynamic-operation-registry-v1"
INSTALLED=Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
PLANS=Path("/var/lib/mad4b/dynamic-operation-registry/plans")
ROOT_KEY=Path("/opt/mad4b-company-transport/native-313-independent/root-only-verifier-ed25519.key")
PLAN_SOURCE=Path("/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108/wayfa-plan-dynamic-registry-source-final.json")
FILES={
    "registry.py":SOURCE_REGISTRY/"registry.py",
    "admission.py":SOURCE_REGISTRY/"admission.py",
    "worker_adapter.py":SOURCE_REGISTRY/"worker_adapter.py",
    "evidence.py":SOURCE_REGISTRY/"evidence.py",
    "company-code-release-v1/original_313_host_handler.py":SOURCE_OPS/"company-code-release-v1/original_313_host_handler.py",
    "company-code-release-v1/original_313_independent_readback.py":SOURCE_OPS/"company-code-release-v1/original_313_independent_readback.py",
    "company-code-release-v1/original_313_code_release_contract.py":SOURCE_OPS/"company-code-release-v1/original_313_code_release_contract.py",
    "company-code-release-v1/company_code_release.py":SOURCE_OPS/"company-code-release-v1/company_code_release.py",
    "company-code-release-v1/company_lifecycle_broker.py":SOURCE_OPS/"company-code-release-v1/company_lifecycle_broker.py",
    "dynamic-operation-registry-v1/providers/company.code.release/manifest.json":SOURCE_REGISTRY/"providers/company.code.release/manifest.json",
    "dynamic-operation-registry-v1/providers/company.code.release/evidence-schema.json":SOURCE_REGISTRY/"providers/company.code.release/evidence-schema.json",
    "php/class-mad4b-dynamic-313-verifier.php":SOURCE_REGISTRY/"php/class-mad4b-dynamic-313-verifier.php",
}

def require(condition:bool,reason:str)->None:
    if not condition:raise registry.RegistryDenied(reason)

def private_dir(p:Path)->None:
    require(p.is_absolute() and not p.is_symlink(),"INVALID_INSTALL_DIRECTORY")
    if not p.exists(): p.mkdir(mode=0o700,parents=True)
    meta=p.stat()
    require(meta.st_uid==0 and stat.S_ISDIR(meta.st_mode) and
            not meta.st_mode & 0o077, "INSTALL_DIRECTORY_NOT_ROOT_PRIVATE")

def atomic_private(path:Path,contents:bytes)->None:
    private_dir(path.parent)
    require(not path.is_symlink(),"INSTALL_SYMLINK_DENIED")
    fd,name=tempfile.mkstemp(prefix=".new-",dir=path.parent)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"wb") as f:
            f.write(contents);f.flush();os.fsync(f.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

def build()->tuple[dict,dict]:
    if os.geteuid()!=0:raise registry.RegistryDenied("FIRST_PARTY_ROOT_INSTALL_REQUIRED")
    require(ROOT_KEY.is_file() and not ROOT_KEY.is_symlink() and
            ROOT_KEY.stat().st_uid==0 and
            stat.S_IMODE(ROOT_KEY.stat().st_mode)==0o600,
            "ORIGINAL_ROOT_INDEPENDENT_KEY_NOT_TRUSTED")
    private=Ed25519PrivateKey.from_private_bytes(ROOT_KEY.read_bytes())
    public=private.public_key().public_bytes(
        serialization.Encoding.Raw,serialization.PublicFormat.Raw)
    index=json.loads((SOURCE_REGISTRY/"index.preview-NOT-ENROLLED.json").read_text())
    require(index.get("installed_by_first_party") is False and
            index.get("source_preview_only") is True and
            len(index.get("providers",[]))==1,
            "SOURCE_INDEX_NOT_PREVIEW")
    for dest,source in FILES.items():
        require(source.is_file() and not source.is_symlink(),
                "SOURCE_COMPONENT_MISSING")
        require(source.stat().st_uid==0 and not source.stat().st_mode&0o022,
                "SOURCE_COMPONENT_NOT_TRUSTED")
    for row in index["providers"]:
        source=SOURCE_OPS/row["manifest"]
        require(registry.sha(source.read_bytes())==row["sha256"],
                "SOURCE_MANIFEST_NOT_PINNED")
        m=json.loads(source.read_text())
        for key in ("handler","verifier","evidence"):
            item=m[key]
            require(registry.sha((SOURCE_OPS/item["path"]).read_bytes())==item["sha256"],
                    "SOURCE_PROVIDER_COMPONENT_DRIFT")
    plan=json.loads(PLAN_SOURCE.read_text())
    content={k:v for k,v in plan.items() if k not in
             ("plan_sha256","changed_count")}
    require(registry.sha(registry.canonical(content))==plan["plan_sha256"],
            "SOURCE_PLAN_HASH_MISMATCH")
    require(plan["company"]=="wayfatours" and
            plan["environment"]=="production" and
            plan["site_uuid"]=="a3a7cc36-db7e-4a14-8456-b41857def05c",
            "SOURCE_PLAN_IDENTITY_MISMATCH")
    git_root=SOURCE_OPS.parent
    commit=subprocess.check_output(
        ["git","-C",str(git_root),"rev-parse","HEAD"],text=True).strip()
    require(plan["git_commit"] in
            subprocess.check_output(["git","-C",str(git_root),"rev-list","HEAD"],
                                    text=True).split(),
            "SOURCE_PLAN_COMMIT_NOT_IN_LOCAL_GIT")
    index["installed_by_first_party"]=True
    index.pop("source_preview_only",None)
    index.pop("production_mutated",None)
    index["independent_verifier_public_key_b64"]=base64.b64encode(public).decode()
    index["installed_source_git_commit"]=commit
    return index,plan

def inspect(install:bool=False)->dict:
    index,plan=build()
    status={
        "contract":"mad4b.dynamic-operation-registry.first-party-install.v1",
        "state":"FIRST_PARTY_SOURCE_VERIFIED_NO_LIVE_INSTALL",
        "source_git_commit":index["installed_source_git_commit"],
        "company":plan["company"],
        "environment":plan["environment"],
        "plan_sha256":plan["plan_sha256"],
        "enrolled":False,
        "original_306_worker_modified":False,
        "original_central_313_modified":False,
        "job_created":False,"grant_issued":False,
        "production_mutated":False,
    }
    if install:
        require(not (INSTALLED/"index.json").exists(),
                "REGISTRY_ALREADY_ENROLLED_RECONCILE_FIRST")
        private_dir(INSTALLED)
        for relative,src in FILES.items():
            path=INSTALLED/relative
            require(not path.exists(),"EXISTING_REGISTRY_FILE_UNEXPECTED")
            atomic_private(path,src.read_bytes())
        private_dir(PLANS)
        planpath=PLANS/(plan["plan_sha256"]+".json")
        require(not planpath.exists(),"PLAN_ALREADY_ENROLLED_RECONCILE_FIRST")
        atomic_private(planpath,registry.canonical(plan)+b"\n")
        # Index published LAST, after every binary, schema and plan passes.
        atomic_private(INSTALLED/"index.json",registry.canonical(index)+b"\n")
    if (INSTALLED/"index.json").exists():
        active=registry.Registry(INSTALLED)
        manifest=active.resolve("company.code.release")
        from worker_adapter import indexed_plans
        approved=indexed_plans(active,PLANS)
        require(active.command_sha256("company.code.release",plan["plan_sha256"])
                in approved,"ENROLLED_PLAN_NOT_DISCOVERABLE")
        require((INSTALLED/"index.json").read_bytes() ==
                registry.canonical(index)+b"\n","INSTALL_PROVENANCE_CHANGED")
        status["enrolled"]=True
        status["state"]="FIRST_PARTY_REGISTRY_AND_PLAN_INSTALLED"
        status["operation_id"]=manifest["operation_id"]
        status["command_sha256"]=active.command_sha256(
            "company.code.release",plan["plan_sha256"])
        status["plans_indexed"]=len(approved)
    return status

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--check",action="store_true")
    group.add_argument("--install",action="store_true")
    args=parser.parse_args()
    print(json.dumps(inspect(install=args.install),sort_keys=True))

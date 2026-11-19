#!/usr/bin/env python3
"""Upgrade an already root-enrolled dynamic provider to version 2, fail closed.

This does NOT change the original 306 Worker, issue Jobs/Grants, or mutate
company WordPress code. First-party installed registry continues using the
existing independent 313 verifier public key.
"""
from __future__ import annotations
import argparse
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import registry
from first_party_install import atomic_private

INSTALLED=Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
SOURCE=Path(__file__).resolve().parents[1]
BACKUP=Path("/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108/dynamic-registry-evidence-v2-backup")
COMPONENTS={
    "evidence.py": SOURCE/"evidence.py",
    "dynamic-operation-registry-v1/providers/company.code.release/evidence-schema.json":
        SOURCE/"providers/company.code.release/evidence-schema.json",
    "dynamic-operation-registry-v1/providers/company.code.release/manifest.json":
        SOURCE/"providers/company.code.release/manifest.json",
}

def upgrade(apply=False):
    existing=registry.Registry(INSTALLED)
    m=existing.resolve("company.code.release")
    if m["version"] != 1:
        raise registry.RegistryDenied("EXPECT_ENROLLED_SOURCE_PROVIDER_V1")
    existing_index=json.loads((INSTALLED/"index.json").read_text())
    old_pub=existing_index["independent_verifier_public_key_b64"]
    newm=json.loads((SOURCE/"providers/company.code.release/manifest.json").read_text())
    if not (newm["version"]==2 and newm["evidence_schema"]=="code_release.v2" and
            newm["operation_id"]=="company.code.release" and
            newm["command"]==m["command"]):
        raise registry.RegistryDenied("V2_PROVIDER_COMMAND_IDENTITY_CHANGED")
    for kind in ("handler","verifier"):
        if newm[kind] != m[kind]:
            raise registry.RegistryDenied("V2_RELEASE_HANDLER_UNEXPECTEDLY_CHANGED")
    if registry.sha((SOURCE/"providers/company.code.release/evidence-schema.json").read_bytes())!=newm["evidence"]["sha256"]:
        raise registry.RegistryDenied("V2_SCHEMA_NOT_PINNED")
    repo=SOURCE.parents[1]
    if subprocess.check_output(['git','-C',str(repo),'status','--porcelain']).strip():
        raise registry.RegistryDenied("UNCOMMITTED_REGISTRY_UPGRADE_SOURCE")
    head=subprocess.check_output(['git','-C',str(repo),'rev-parse','HEAD'],text=True).strip()
    rows=existing_index["providers"]
    if len(rows)!=1 or rows[0]["manifest"] not in COMPONENTS:
        raise registry.RegistryDenied("EXISTING_INDEX_UNEXPECTED")
    updated=dict(existing_index)
    updated["providers"]=[{
        "manifest":rows[0]["manifest"],
        "sha256":registry.sha((SOURCE/"providers/company.code.release/manifest.json").read_bytes())
    }]
    updated["upgraded_source_git_commit"]=head
    if updated["independent_verifier_public_key_b64"] != old_pub:
        raise registry.RegistryDenied("ORIGINAL_VERIFIER_SIGNING_KEY_DRIFT")
    result={"state":"V2_SOURCE_CHECKED_NOT_INSTALLED","version":2,
            "evidence_schema":"code_release.v2",
            "original_signing_key_unchanged":True,
            "original_worker_modified":False,
            "company_site_mutated":False,
            "source_git_commit":head}
    if not apply:
        return result
    if BACKUP.exists():
        raise registry.RegistryDenied("V2_UPGRADE_BACKUP_ALREADY_EXISTS")
    BACKUP.mkdir(mode=0o700,parents=True)
    os.chmod(BACKUP,0o700)
    old_files={str(p):p.read_bytes() for p in
               [INSTALLED/"index.json"]+[INSTALLED/name for name in COMPONENTS]}
    for path,blob in old_files.items():
        target=BACKUP/(Path(path).relative_to(INSTALLED))
        target.parent.mkdir(parents=True,exist_ok=True)
        os.chmod(target.parent,0o700)
        atomic_private(target,blob)
    try:
        # The index is published last: old readers can only defer while a
        # component upgrade is in progress, never import a fabricated signer.
        for relative,source in COMPONENTS.items():
            atomic_private(INSTALLED/relative,source.read_bytes())
        atomic_private(INSTALLED/"index.json",registry.canonical(updated)+b"\n")
        final=registry.Registry(INSTALLED)
        if final.resolve("company.code.release")["version"] != 2 or (
            final.independent_verifier_public_key != existing.independent_verifier_public_key
        ):
            raise registry.RegistryDenied("V2_INSTALLED_READBACK_INVALID")
    except BaseException:
        for name,blob in reversed(list(old_files.items())):
            atomic_private(Path(name),blob)
        raise
    result["state"]="FIRST_PARTY_PROVIDER_V2_INSTALLED"
    result["root_protected_backup"]=str(BACKUP)
    return result

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--check",action="store_true")
    group.add_argument("--install",action="store_true")
    args=parser.parse_args()
    print(json.dumps(upgrade(apply=args.install),sort_keys=True))

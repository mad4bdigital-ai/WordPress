#!/usr/bin/env python3
"""Read-only exact-source overlay acceptance, never an authorization issuer."""
from __future__ import annotations
import argparse
import hashlib
import json
import pathlib
import re
import subprocess
from typing import Any

SHA40=re.compile(r"[0-9a-f]{40}\Z")
SHA64=re.compile(r"[0-9a-f]{64}\Z")

class OverlayDenied(ValueError):pass

def git(repo:pathlib.Path,*args:str,success_required=True)->str|None:
    result=subprocess.run(["git","-C",str(repo),*args],
                          capture_output=True,text=True,timeout=15)
    if result.returncode:
        if success_required:raise OverlayDenied("GIT_OBJECT_UNAVAILABLE_OR_DRIFT:"+args[0])
        return None
    return result.stdout.strip()

def validate(repo:pathlib.Path,manifest:dict[str,Any])->dict:
    if manifest.get("contract")!="mad4b.git.local-only-source-overlay.v1":
        raise OverlayDenied("MANIFEST_CONTRACT_INVALID")
    base=manifest.get("base_commit")
    if not isinstance(base,str) or not SHA40.fullmatch(base):
        raise OverlayDenied("BASE_COMMIT_NOT_PINNED")
    if git(repo,"cat-file","-t",base)!="commit":
        raise OverlayDenied("BASE_COMMIT_UNKNOWN")
    rows=manifest.get("files")
    if not isinstance(rows,list) or len(rows)!=34:
        raise OverlayDenied("OVERLAY_PATH_COUNT_INVALID")
    allowed={"tenant":manifest.get("tenant_source_commit"),
             "original313":manifest.get("worker_source_commit")}
    if any(not isinstance(c,str) or not SHA40.fullmatch(c) for c in allowed.values()):
        raise OverlayDenied("DONOR_COMMIT_NOT_PINNED")
    for commit in allowed.values():
        if git(repo,"cat-file","-t",commit)!="commit":
            raise OverlayDenied("DONOR_COMMIT_UNKNOWN")
    seen=set()
    checked=0
    for row in rows:
        if not isinstance(row,dict) or set(row)!={
                "path","group","sha256","git_blob_sha"}:
            raise OverlayDenied("OVERLAY_ENTRY_INVALID")
        path=row["path"];group=row["group"]
        if (not isinstance(path,str) or not path or
            path.startswith("/") or path.startswith("-") or
            "\\" in path or any(part in ("",".","..") for part in path.split("/"))):
            raise OverlayDenied("OVERLAY_PATH_UNTRUSTED")
        if path in seen:raise OverlayDenied("DUPLICATE_PATH")
        seen.add(path)
        if group not in allowed or not isinstance(row["sha256"],str) or not SHA64.fullmatch(row["sha256"]) or not isinstance(row["git_blob_sha"],str) or not SHA40.fullmatch(row["git_blob_sha"]):
            raise OverlayDenied("OVERLAY_DIGEST_SHAPE_INVALID")
        if group=="tenant" and not (path.startswith("wp-content/plugins/mad4b-tenant-sync/") or path=="tests/test_tenant_environment_neutrality_source.py"):
            raise OverlayDenied("TENANT_PATH_OUTSIDE_EXACT_ALLOWLIST")
        if group=="original313" and not path.startswith("ops/dynamic-operation-registry-v1/"):
            raise OverlayDenied("WORKER_PATH_OUTSIDE_EXACT_ALLOWLIST")
        if git(repo,"cat-file","-e",base+":"+path,success_required=False) is not None:
            raise OverlayDenied("EXISTING_BASE_FILE_CANNOT_BE_REPLACED")
        source_oid=git(repo,"rev-parse",allowed[group]+":"+path)
        if source_oid!=row["git_blob_sha"]:
            raise OverlayDenied("DONOR_BLOB_DRIFT")
        target=repo/path
        if target.is_symlink() or not target.is_file() or target.stat().st_size>1_000_000:
            raise OverlayDenied("WORKTREE_BLOB_UNTRUSTED")
        if hashlib.sha256(target.read_bytes()).hexdigest()!=row["sha256"]:
            raise OverlayDenied("LOCAL_BLOB_DRIFT")
        if git(repo,"hash-object","--no-filters",str(target))!=source_oid:
            raise OverlayDenied("GIT_BLOB_MISMATCH")
        checked+=1
    if sum(row["group"]=="tenant" for row in rows)!=27 or sum(row["group"]=="original313" for row in rows)!=7:
        raise OverlayDenied("GROUP_COUNTS_CHANGED")
    return {"state":"EXACT_SOURCE_BLOBS_VERIFIED__NONAUTHORIZING",
            "source_blobs_verified":checked,"base_commit":base,
            "tenant_source_commit":allowed["tenant"],
            "worker_source_commit":allowed["original313"],
            "live_deployment_performed":False,
            "original313_receipt_signed":False,
            "runtime_grants_issued":False,
            "global_release_certified":False}

if __name__=="__main__":
    p=argparse.ArgumentParser()
    p.add_argument("--repo",type=pathlib.Path,default=pathlib.Path(__file__).resolve().parents[1])
    p.add_argument("--manifest",type=pathlib.Path)
    a=p.parse_args()
    manifest=a.manifest or (a.repo/"docs/release-coverage/source-overlay-20261111.json")
    try:
        print(json.dumps(validate(a.repo.resolve(),json.loads(manifest.read_text())),indent=2))
    except (OverlayDenied,ValueError,OSError,subprocess.TimeoutExpired) as exc:
        print(json.dumps({"state":"BLOCKED","reason":str(exc),
             "runtime_mutated":False,"global_release_certified":False}))
        raise SystemExit(2)

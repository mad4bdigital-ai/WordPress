#!/usr/bin/env python3
"""Queue-to-native executor bridge for one enrolled company MU stage.

This adapter ONLY prepares a manifest from a pinned server-side artifact
catalog. A queued job cannot choose source paths, and manifest preparation
never calls apply, changes a tenant, or issues an authority ticket.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import tempfile

ACTION="company.mu-providers.converge"
SHA=re.compile(r"^[a-f0-9]{64}$")
PAYLOAD_KEYS={
    "contract","company_code","site_uuid","origin","mode","stage","action",
    "plan_sha256","stage_revision","run_id","preserve_existing","mutation_authorized"
}

def canonical(value):
    return json.dumps(value,sort_keys=True,separators=(",",":"),ensure_ascii=False).encode()

def read(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))

def build(job,policy,catalog):
    p=job.get("payload")
    if not isinstance(p,dict) or set(p)!=PAYLOAD_KEYS:
        raise ValueError("QUEUE_PAYLOAD_SCHEMA_INVALID")
    if job.get("operation_id")!="company.stage.execute" or job.get("status")!="claimed":
        raise ValueError("NO_CLAIMED_SEMANTIC_JOB")
    if (job.get("cancel_requested_at") or job.get("reconciliation_required") or
        int(job.get("lease_expires_at_epoch") or 0)<=int(dt.datetime.now(dt.timezone.utc).timestamp())):
        raise ValueError("CLAIM_EXPIRED_OR_CANCELLED")
    if job.get("executor_id")!=policy.get("executor_id"):
        raise ValueError("EXECUTOR_BINDING_MISMATCH")
    if p.get("contract")!="mad4b.company-stage-job.v1" or p.get("action")!=ACTION or p.get("stage")!="mu_providers":
        raise ValueError("UNKNOWN_COMPANY_STAGE")
    if p.get("mode")!="repair_existing" or p.get("preserve_existing") is not True or p.get("mutation_authorized") is not False:
        raise ValueError("INVALID_EXECUTION_AUTHORITY")
    if p.get("company_code")!=policy.get("company") or p.get("site_uuid")!=policy.get("site_uuid") or p.get("origin")!=policy.get("canonical_origin"):
        raise ValueError("TENANT_SCOPE_MISMATCH")
    for key in ("run_id","plan_sha256","stage_revision"):
        if not SHA.fullmatch(str(p.get(key,""))):
            raise ValueError("UNBOUND_RUN_OR_PLAN")
    if catalog.get("contract")!="mad4b.portable-mu-artifact-catalog.v1":
        raise ValueError("ARTIFACT_CATALOG_INVALID")
    for key in ("company_code","site_uuid","origin","action"):
        if catalog.get(key)!=p.get(key):
            raise ValueError("ARTIFACT_CATALOG_COMPANY_MISMATCH")
    items=catalog.get("artifacts")
    if not isinstance(items,list) or len(items)==0:
        raise ValueError("NO_PORTABLE_ARTIFACTS")
    # Exact server-side SHA pin is independent of the claimed queue payload.
    if hashlib.sha256(canonical(catalog)).hexdigest()!=policy.get("catalog_sha256"):
        raise ValueError("CATALOG_SHA256_NOT_ENROLLED")
    if any(not isinstance(x,dict) or set(x)!={"path","sha256"} or
           not SHA.fullmatch(str(x.get("sha256",""))) for x in items):
        raise ValueError("ARTIFACT_RECORD_INVALID")
    manifest={k:p[k] for k in PAYLOAD_KEYS if k!="mutation_authorized"}
    manifest["artifacts"]=items
    return manifest

def prepare(args):
    job=read(args.job)
    policy=read(args.policy)
    catalog=read(args.catalog)
    manifest=build(job,policy,catalog)
    root=Path(policy["workspace_root"]).resolve(strict=True)
    if not root.is_dir() or root.is_symlink():
        raise ValueError("ENROLLED_WORKSPACE_UNAVAILABLE")
    filename=manifest["run_id"]+"-"+manifest["stage_revision"]+".json"
    destination=root/filename
    if destination.exists():
        if json.loads(destination.read_text())!=manifest:
            raise ValueError("EXISTING_RUN_MANIFEST_CONFLICT")
        return {"state":"ALREADY_PREPARED","manifest":str(destination),"mutation_performed":False}
    fd,tmp=tempfile.mkstemp(dir=root,prefix=".company-stage-")
    try:
        with os.fdopen(fd,"wb") as stream:
            stream.write(canonical(manifest))
            stream.flush();os.fsync(stream.fileno())
        os.chmod(tmp,0o600)
        os.replace(tmp,destination)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
    return {"state":"MANIFEST_PREPARED","manifest":str(destination),
        "company":manifest["company_code"],"action":ACTION,"mutation_performed":False,
        "next":"SIGNED_CENTRAL_PROVIDER_TICKET_REQUIRED"}

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("--job",required=True)
    parser.add_argument("--policy",required=True)
    parser.add_argument("--catalog",required=True)
    args=parser.parse_args()
    try:
        result=prepare(args)
    except (ValueError,KeyError,OSError,json.JSONDecodeError) as exc:
        result={"state":"DENIED","reason":str(exc)[:200],"mutation_performed":False}
    print(json.dumps(result,sort_keys=True))
    sys.exit(0 if result["state"] in ("MANIFEST_PREPARED","ALREADY_PREPARED") else 4)

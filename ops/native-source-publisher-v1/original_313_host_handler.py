#!/usr/bin/env python3
"""First-party 306/312-admitted local source release handler.

The installed 306 worker reserves the Job before invoking this root-only
subprocess. Missing original worker reservation, registered digest,
fresh 312 authority, independent Master Admin signature or signed Broker
release Grant DENIES.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import stat

import native_source_publisher as publisher

INDEX = publisher.REGISTRY_ROOT
JOB = re.compile(r"^[a-f0-9]{32}$")
HEX = publisher.HEX64
RESERVATIONS = Path("/opt/mad4b-company-transport/native-313-independent/reserved")
PLANS = Path("/var/lib/mad4b/dynamic-operation-registry/plans")
GRANTS = Path("/var/lib/mad4b/native-source-publisher/grants")
APPROVALS = Path("/var/lib/mad4b/native-source-publisher/approvals")
HOST = "srv2030908"


def protected_file(path: Path, parent: Path) -> bytes:
    if (not parent.is_absolute() or parent.is_symlink()
        or not parent.is_dir() or parent.stat().st_uid != 0
        or parent.stat().st_mode & 0o077):
        raise publisher.Denied("SOURCE_SECURE_STORE_UNTRUSTED")
    if path.parent != parent or path.is_symlink() or not path.is_file():
        raise publisher.Denied("SOURCE_SECURE_ARTIFACT_UNAVAILABLE")
    statinfo=path.stat()
    if (not stat.S_ISREG(statinfo.st_mode) or statinfo.st_uid != 0
        or statinfo.st_mode & 0o077 or not 0 < statinfo.st_size <= 200_000):
        raise publisher.Denied("SOURCE_SECURE_ARTIFACT_NOT_PRIVATE")
    return path.read_bytes()


def original_312_for_claim(job_id: str, plan: dict) -> dict:
    """Read original WordPress 312; do not accept caller supplied approval flags."""
    import sys
    module_file=INDEX/"admission.py"
    spec=importlib.util.spec_from_file_location("_mad4b_313_source_admission",module_file)
    if spec is None or spec.loader is None:
        raise publisher.Denied("SOURCE_ORIGINAL_ADMISSION_NOT_INSTALLED")
    before=list(sys.path)
    try:
        sys.path.insert(0,str(INDEX))
        module=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        manifest=publisher.enrolled_original_provider(
            plan[publisher.BINDING_KEY]["operation_id"])
        command=publisher.original_registry().command_argv(
            manifest["operation_id"],plan["plan_sha256"])
        command_sha=publisher.sha(json.dumps(command,
            separators=(",",":"),ensure_ascii=False).encode())
        reservation=protected_file(RESERVATIONS/(job_id+".reserved"),RESERVATIONS)
        if reservation != ("dynamic:"+manifest["operation_id"]+" "+command_sha+"\n").encode():
            raise publisher.Denied("SOURCE_ORIGINAL_JOB_RESERVATION_MISMATCH")
        signed=module.fresh_original_312(job_id,command_sha)
        candidate={"job_id":job_id,"operation_id":manifest["operation_id"],
          "command_sha256":command_sha,"company":plan["company"],
          "environment":plan["environment"],"site_uuid":plan["site_uuid"],
          "origin":plan["origin"]}
        verified=module.verify_claimed_312(candidate,signed)
        if verified["original_312_verified"] is not True:
            raise publisher.Denied("SOURCE_ORIGINAL_312_NOT_VERIFIED")
        return verified
    finally:
        sys.path[:]=before


def execute_once(job_id: str, plan_sha256: str) -> dict:
    if os.geteuid()!=0:
        raise publisher.Denied("SOURCE_FIRST_PARTY_ROOT_REQUIRED")
    if not JOB.fullmatch(job_id) or not HEX.fullmatch(plan_sha256):
        raise publisher.Denied("SOURCE_ORIGINAL_JOB_ID_INVALID")
    expected=(INDEX/"native-source-publisher-v1/original_313_host_handler.py")
    if (Path(__file__).absolute()!=expected or expected.is_symlink()):
        raise publisher.Denied("SOURCE_HOST_HANDLER_NOT_FIRST_PARTY_INSTALLED")
    policy=publisher.checked_policy()
    raw_plan=protected_file(PLANS/(plan_sha256+".json"),PLANS)
    plan=json.loads(raw_plan)
    if plan.get("plan_sha256")!=plan_sha256:
        raise publisher.Denied("SOURCE_ENROLLED_PLAN_IDENTITY_CHANGED")
    publisher.check_plan(plan,policy)
    original_312_for_claim(job_id,plan)
    raw_grant=protected_file(GRANTS/(job_id+".json"),GRANTS)
    raw_approval=protected_file(APPROVALS/(plan_sha256+".json"),APPROVALS)
    grant=json.loads(raw_grant)
    approval=json.loads(raw_approval)
    if grant.get("original_job_id")!=job_id:
        raise publisher.Denied("SOURCE_BROKER_GRANT_ORIGINAL_JOB_MISMATCH")
    result=publisher.executor(plan,policy,approval,grant,
                              Path(policy["receipt_dir"]))
    return {
        "contract":"mad4b.native-source-publisher.host-313-candidate.v1",
        "state":("HOST_APPLIED_PENDING_INDEPENDENT_ORIGINAL_313"
                 if result.get("state")=="APPLIED_VERIFIED" else
                 "HOST_APPLY_ROLLED_BACK"),
        "job_id":job_id,"plan_sha256":plan_sha256,
        "host_receipt_state":result.get("state"),
        "original_313_certified":False,
        "original_313_receipt_generated":False,
        "independent_original_313_required":True,
    }


def main() -> int:
    p=argparse.ArgumentParser()
    p.add_argument("--job",required=True)
    p.add_argument("--plan-sha256",required=True)
    args=p.parse_args()
    result=execute_once(args.job,args.plan_sha256)
    print(json.dumps(result,sort_keys=True))
    return 0 if result["state"]=="HOST_APPLIED_PENDING_INDEPENDENT_ORIGINAL_313" else 3


if __name__=="__main__":
    try:
        raise SystemExit(main())
    except (publisher.Denied,OSError,ValueError,KeyError,RuntimeError) as error:
        print(json.dumps({"state":"SOURCE_ORIGINAL_313_HOST_DENIED",
                          "reason":type(error).__name__,
                          "original_313_certified":False}))
        raise SystemExit(2)

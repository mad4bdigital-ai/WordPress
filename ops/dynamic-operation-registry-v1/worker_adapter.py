#!/usr/bin/env python3
"""Additive dynamic 306 worker adapter, gated by original first-party install.

The old 306 Worker may retain all legacy operations. Once FIRST-PARTY
installed, it can call digest_table() + execute_claimed(). Neither reads
arbitrary commands from Central nor executes an unclaimed original Job.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
from typing import Any

import admission
import registry

INSTALLED_REGISTRY = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled"
)
INSTALLED_PLANS = Path(
    "/var/lib/mad4b/dynamic-operation-registry/plans"
)
RESERVATIONS = Path(
    "/opt/mad4b-company-transport/native-313-independent/reserved"
)
HEX = re.compile(r"^[a-f0-9]{64}$")


def indexed_plans(index: registry.Registry, plan_store: Path) -> dict[str, dict]:
    """Return signed-command digests computed from *enrolled* immutable plans."""
    store = Path(plan_store)
    registry.require(store.is_absolute() and store.is_dir() and
                     not store.is_symlink() and store.stat().st_uid == 0 and
                     not store.stat().st_mode & 0o022,
                     "FIRST_PARTY_PLAN_STORE_NOT_INSTALLED")
    paths = sorted(store.glob("*.json"))
    registry.require(len(paths) <= 256, "DYNAMIC_PLAN_REGISTRY_LIMIT_EXCEEDED")
    rows: dict[str, dict] = {}
    for path in paths:
        digest=path.stem
        registry.require(HEX.fullmatch(digest) is not None,
                         "DYNAMIC_PLAN_FILENAME_INVALID")
        for operation, manifest in index.manifests.items():
            # The contract validator below filters mismatched plans.
            raw=registry.checked_json(path,store)
            if raw.get("contract") != manifest.get("plan_contract"):
                continue
            plan=admission.checked_plan(store,digest,manifest)
            sha=index.command_sha256(operation,plan["plan_sha256"])
            registry.require(sha not in rows,
                             "DYNAMIC_COMMAND_DIGEST_COLLISION")
            rows[sha]={"operation":operation,"plan":plan}
    return rows


def digest_table(index: registry.Registry, plan_store: Path) -> dict[str,str]:
    return {digest:row["operation"] for digest,row in
            indexed_plans(index,plan_store).items()}


def select(index: registry.Registry, store: Path,
           original_job: dict, original_pending: dict) -> dict:
    cmd=original_job.get("command_sha256")
    registry.require(isinstance(cmd,str) and HEX.fullmatch(cmd),
                     "DYNAMIC_JOB_COMMAND_MISSING")
    table=indexed_plans(index,store)
    registry.require(cmd in table, "DYNAMIC_OPERATION_NOT_REGISTERED")
    row=table[cmd]
    return admission.resolve_claimed(index,row["operation"],row["plan"],
                                     original_job,original_pending)


def execute_claimed(original_job: dict, original_pending: dict) -> dict:
    """Installed 306 hook only, never exposed as a public WordPress Ability."""
    registry.require(os.geteuid() == 0,"ORIGINAL_ROOT_EXECUTOR_REQUIRED")
    registry.require(INSTALLED_REGISTRY.exists() and
                     INSTALLED_PLANS.exists(),
                     "FIRST_PARTY_DYNAMIC_REGISTRY_NOT_INSTALLED")
    index=registry.Registry(INSTALLED_REGISTRY)
    candidate=select(index,INSTALLED_PLANS,original_job,original_pending)
    authority=admission.fresh_original_312(
        candidate["job_id"],candidate["command_sha256"])
    checked=admission.verify_claimed_312(candidate,authority)
    operation=checked["operation_id"]
    m=index.resolve(operation)
    handler=Path(checked["handler_path"])
    registry.require(registry.sha(registry.protected_file(
        handler,index.root,max_bytes=2_000_000)) ==
        m["handler"]["sha256"],"HANDLER_CHANGED_AFTER_ADMISSION")
    # Original job is reserved BEFORE execution, so timeout can never replay
    # an uncertain WordPress mutation. No operator may delete this as a retry.
    lock=RESERVATIONS
    registry.require(lock.is_dir() and not lock.is_symlink() and
                     lock.stat().st_uid==0 and not lock.stat().st_mode & 0o077,
                     "ORIGINAL_RESERVATION_STORE_UNTRUSTED")
    reservation=lock/(candidate["job_id"]+".reserved")
    fd=os.open(reservation,os.O_CREAT|os.O_EXCL|os.O_WRONLY|
               getattr(os,"O_NOFOLLOW",0),0o600)
    with os.fdopen(fd,"w") as stream:
        stream.write("dynamic:"+operation+" "+candidate["command_sha256"]+"\n")
        stream.flush();os.fsync(stream.fileno())
    # No shell, no argv sourced from the Job. Handler validates its own
    # exact broker release grant and pinned WordPress release policy again.
    p=subprocess.run([sys.executable,str(handler),
                      "--job",candidate["job_id"],
                      "--plan-sha256",candidate["plan_sha256"]],
                     capture_output=True,text=True,timeout=320,check=False)
    if p.returncode:
        raise registry.RegistryDenied(
            "DYNAMIC_HANDLER_FAILED_RECEIPT_RECONCILIATION_REQUIRED")
    try:
        result=json.loads(p.stdout.strip().splitlines()[-1])
    except (IndexError,ValueError) as exc:
        raise registry.RegistryDenied("DYNAMIC_HOST_RESULT_INVALID") from exc
    registry.require(result.get("state") ==
                     "HOST_APPLIED_PENDING_INDEPENDENT_ORIGINAL_313" and
                     result.get("job_id")==candidate["job_id"] and
                     result.get("plan_sha256")==candidate["plan_sha256"] and
                     result.get("original_313_certified") is False,
                     "DYNAMIC_HOST_READBACK_REQUIRED")
    return {
        "contract":"mad4b.dynamic-306-worker-result.v1",
        "state":"HOST_EXECUTED_PENDING_INDEPENDENT_ORIGINAL_313",
        "job_id":candidate["job_id"],"operation_id":operation,
        "original_312_verified":True,
        "original_313_certified":False,
        "grant_created":False,
        "central_313_receipt_created":False,
    }

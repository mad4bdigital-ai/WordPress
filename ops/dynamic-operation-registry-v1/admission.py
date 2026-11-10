#!/usr/bin/env python3
"""Dynamic 306 admission: resolve exact original claimed Job to registered provider.

The running original Broker still owns claims. This candidate cannot claim
Jobs or invent 312 authority. The handler path comes ONLY from the protected
first-party registry; immutable plan content pins the exact command SHA.
"""
from __future__ import annotations
import importlib.util
import json
import re
from pathlib import Path
import stat
import time
from typing import Callable
import registry

PLAN_ROOT = Path("/var/lib/mad4b/dynamic-operation-registry/plans")
VERIFIER_FILE = Path(
    "/opt/mad4b-company-transport/broker-v1/vps-broker-exact-authority.py"
)
HOST = "srv2030908"
JOB = re.compile(r"^[a-f0-9]{32}$")
ENV = re.compile(r"^[a-z][a-z0-9-]{1,63}$")


def checked_plan(store: Path, plan_sha256: str, manifest: dict) -> dict:
    registry.require(isinstance(plan_sha256, str) and
                     registry.SHA.fullmatch(plan_sha256),
                     "ORIGINAL_PLAN_REFERENCE_INVALID")
    store = Path(store)
    registry.require(store.is_absolute() and store.is_dir() and
                     not store.is_symlink() and store.stat().st_uid == 0 and
                     not store.stat().st_mode & 0o022,
                     "ORIGINAL_PLAN_STORE_UNTRUSTED")
    path = store / (plan_sha256 + ".json")
    doc = registry.checked_json(path, store)
    registry.require(doc.get("contract") == manifest.get("plan_contract") and
                     doc.get("plan_sha256") == plan_sha256 and
                     doc.get("environment") and
                     isinstance(doc.get("environment"), str) and
                     ENV.fullmatch(doc["environment"]) and
                     isinstance(doc.get("company"), str) and
                     ENV.fullmatch(doc["company"]) and
                     isinstance(doc.get("site_uuid"), str) and
                     isinstance(doc.get("origin"), str) and
                     doc["origin"].startswith("https://"),
                     "REGISTERED_PLAN_IDENTITY_INVALID")
    payload = {key: val for key, val in doc.items()
               if key not in ("plan_sha256", "changed_count")}
    registry.require(registry.sha(registry.canonical(payload)) == plan_sha256,
                     "ORIGINAL_PLAN_CONTENT_CHANGED")
    return doc


def resolve_claimed(
    index: registry.Registry,
    operation_id: str,
    plan: dict,
    original_job: dict,
    original_pending: dict,
    *,
    now: int | None = None,
) -> dict:
    """Non-executing decision. Does not accept signer/authority flags."""
    manifest = index.resolve(operation_id)
    plan_sha256 = plan.get("plan_sha256")
    registry.require(plan.get("contract") == manifest.get("plan_contract") and
                     isinstance(plan_sha256, str) and
                     registry.SHA.fullmatch(plan_sha256) and
                     registry.sha(registry.canonical({
                         k:v for k,v in plan.items()
                         if k not in ("plan_sha256","changed_count")})) ==
                     plan_sha256, "ORIGINAL_PLAN_CONTENT_CHANGED")
    registry.require(isinstance(original_job, dict) and
                     isinstance(original_pending, dict) and
                     isinstance(original_job.get("job_id"), str) and
                     JOB.fullmatch(original_job["job_id"]) and
                     original_pending.get("job_id") == original_job["job_id"],
                     "ORIGINAL_306_JOB_IDENTITY_INVALID")
    digest = index.command_sha256(operation_id, plan_sha256)
    for key in ("company", "environment", "site_uuid"):
        registry.require(plan.get(key) == original_job.get(key),
                         "ORIGINAL_306_SCOPE_MISMATCH:" + key)
    # A genuine original 306 Claim is available before execution.
    # The original 313 PENDING_INDEPENDENT_NATIVE_READBACK only exists
    # AFTER the Host executor; accepting/requiring it before execution
    # would create a circular dependency or encourage fabricated receipts.
    registry.require(
        original_pending.get("contract")=="mad4b.central-vps-broker.v1" and
        original_pending.get("state")==
            "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY" and
        original_pending.get("host_id")==HOST and
        original_pending.get("production_mutation_performed") is False and
        type(original_pending.get("claimed_epoch")) is int and
        original_pending.get("claimed_epoch")==original_job.get("claimed_epoch") and
        isinstance(original_pending.get("nonce"),str) and
        JOB.fullmatch(original_pending["nonce"]) is not None,
        "ORIGINAL_SIGNED_306_CLAIM_RECORD_NOT_VERIFIED")
    registry.require(
        original_job.get("origin") in (None, plan.get("origin")) and
        original_job.get("operation") == "company.remote.wpcli.full" and
        original_job.get("target_scope") == "wordpress" and
        original_job.get("job_type") == "signed_vps_wpcli" and
        original_job.get("command_format") == "wpcli.argv.json.v1" and
        original_job.get("command_sha256") == digest and
        original_job.get("state") ==
        "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY" and
        original_job.get("broker_host_id")==HOST and
        original_job.get("target_host_id")==HOST and
        original_job.get("executed") is False,
        "ORIGINAL_306_COMMAND_NOT_CLAIMED")
    instant = int(time.time()) if now is None else now
    registry.require(type(original_job.get("expires_epoch")) is int and
                     original_job["expires_epoch"] > instant + 120 and
                     original_job.get("claimed_epoch") <= instant and
                     original_job.get("claimed_epoch") > instant - 3600,
                     "ORIGINAL_306_CLAIM_EXPIRES_SOON")
    return {
        "contract":"mad4b.dynamic-306-claimed-admission.v1",
        "operation_id":operation_id,
        "job_id":original_job["job_id"],
        "plan_sha256":plan_sha256,
        "company":plan["company"],"environment":plan["environment"],
        "site_uuid":plan["site_uuid"],"origin":plan["origin"],
        "command_sha256":digest,
        "handler_path":str(index.root / manifest["handler"]["path"]),
        "verifier_path":str(index.root / manifest["verifier"]["path"]),
        "state":"CLAIM_METADATA_MATCHED_NEEDS_ORIGINAL_312",
        "execution_authorized":False,
    }


def fresh_original_312(job_id: str, command_sha256: str) -> dict:
    """Original signed WordPress authority, not caller-supplied booleans."""
    f=VERIFIER_FILE
    registry.require(f.is_file() and not f.is_symlink() and
                     f.stat().st_uid == 0 and not f.stat().st_mode & 0o022,
                     "ORIGINAL_312_VERIFIER_NOT_TRUSTED")
    spec=importlib.util.spec_from_file_location(
        "_mad4b_dynamic_first_party312",str(f))
    registry.require(spec is not None and spec.loader is not None,
                     "ORIGINAL_312_IMPORT_FAILED")
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.verify(job_id, HOST, command_sha256)


def verify_claimed_312(candidate: dict, signed: dict, *,
                       now: int | None = None) -> dict:
    instant=int(time.time()) if now is None else now
    registry.require(isinstance(signed, dict) and
                     signed.get("contract") ==
                     "mad4b.broker-exact-ssh-authority.v1" and
                     signed.get("verified") is True and
                     signed.get("eligible") is True and
                     signed.get("owner_enrolled_wpcli") is True and
                     signed.get("central_scope_only") is True and
                     signed.get("shell_executed") is False and
                     signed.get("job_id") == candidate["job_id"] and
                     signed.get("target_id") == HOST and
                     signed.get("operation") == "company.remote.wpcli.full" and
                     signed.get("target_scope") == "wordpress" and
                     signed.get("command_sha256") == candidate["command_sha256"] and
                     all(signed.get(k) == candidate[k]
                         for k in ("company","environment","site_uuid","origin")) and
                     type(signed.get("expires_epoch")) is int and
                     signed["expires_epoch"] > instant + 100,
                     "ORIGINAL_SIGNED_312_ADMISSION_DENIED")
    return {
        **candidate,
        "state":"ELIGIBLE_ORIGINAL_312_NOT_EXECUTED",
        "execution_authorized":False,
        "original_312_verified":True,
        "grant_issued":False,
    }

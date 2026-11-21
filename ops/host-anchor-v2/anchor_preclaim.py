"""Original306 Host Anchor v2 typed claim precondition. NOT an issuer.

Authenticated claimed Job and plan MUST originate from the first-party broker;
no WordPress, Host source or signed Original312 fallback is admitted.
"""
from __future__ import annotations
import importlib.util
from pathlib import Path
import re

OPS = frozenset(("company.host-authority.anchor.issue",
                 "company.host-authority.anchor.revoke"))
HEX64 = re.compile(r"^[a-f0-9]{64}$")
HEX32 = re.compile(r"^[a-f0-9]{32}$")


class AnchorClaimDenied(ValueError):
    pass


def need(yes, reason):
    if not yes:
        raise AnchorClaimDenied(reason)


def plan_module():
    p = Path(__file__).with_name("operation_plan_contract.py")
    need(p.is_file() and not p.is_symlink() and p.stat().st_uid == 0,
         "HOST_ANCHOR_FIRST_PARTY_PLAN_SOURCE_MISSING")
    spec = importlib.util.spec_from_file_location("_host_anchor_exact_plan", p)
    need(spec is not None and spec.loader is not None,
         "HOST_ANCHOR_PINNED_PLAN_UNAVAILABLE")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def verify_claimable(index, plan, job, original_claim):
    """Pure precondition: no Job, Grant, or Anchor can be issued here."""
    need(type(plan) is dict and type(job) is dict and
         type(original_claim) is dict, "HOST_ANCHOR_ORIGINAL_JOB_REQUIRED")
    operation = job.get("operation_id")
    need(operation in OPS, "HOST_ANCHOR_OPERATION_INVALID")
    m = index.resolve(operation)
    need(m.get("source_family") ==
         "mad4b.original-host-authority-anchor.v2"
         and m.get("resource_kind") == "host_anchor"
         and m.get("execution_enabled") is False
         and isinstance(index.host_anchor_312_public_key, bytes),
         "HOST_ANCHOR_REGISTERED_BUT_NOT_AUTHORIZED")
    verified = plan_module().verify_plan(plan, operation)
    need(verified.get("plan_sha256") == plan.get("plan_sha256")
         and verified.get("execution_authorized") is False,
         "HOST_ANCHOR_IMMUTABLE_PLAN_REQUIRED")
    for field in ("company", "environment", "site_uuid", "origin",
                  "target_host_id"):
        need(type(job.get(field)) is str
             and job.get(field) == plan.get(field),
             "HOST_ANCHOR_CLAIM_SCOPE_DRIFT:" + field)
    need(job.get("target_ssh_principal") == plan.get("ssh_principal")
         and job.get("job_type") == "signed_remote_hostinger_wpcli_v2"
         and job.get("principal_type") == "signed_host_principal_v2"
         and job.get("state") ==
            "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
         and job.get("executed") is False
         and job.get("plan_sha256") == plan.get("plan_sha256")
         and job.get("job_id") == original_claim.get("job_id")
         and original_claim.get("contract") == "mad4b.central-vps-broker.v1"
         and original_claim.get("host_id") ==
             job.get("broker_host_id") == "srv2030908"
         and original_claim.get("claimed_epoch") ==
             job.get("claimed_epoch")
         and type(original_claim.get("nonce")) is str and
         HEX32.fullmatch(original_claim["nonce"]) is not None
         and original_claim.get("production_mutation_performed") is False
         and type(job.get("job_id")) is str and
         HEX32.fullmatch(job["job_id"]) is not None
         and type(job.get("command_sha256")) is str and
         HEX64.fullmatch(job["command_sha256"]) is not None
         and index.command_sha256(operation, plan["plan_sha256"]) ==
            job["command_sha256"],
         "HOST_ANCHOR_ORIGINAL_SIGNED_CLAIM_REQUIRED")
    need(original_claim.get("state") ==
         "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
         and original_claim.get("production_mutation_performed") is False
         and set(original_claim) == {
             "contract", "job_id", "host_id", "nonce", "claimed_epoch",
             "state", "production_mutation_performed",
         },
         "HOST_ANCHOR_ORIGINAL306_CLAIM_NOT_ACTIVE")
    return {
        "contract": "mad4b.original306.host-anchor-v2-typed-preclaim.v1",
        "operation_id": operation, "job_id": job["job_id"],
        "state": "EXACT_ORIGINAL306_CLAIM_MATCHED__SIGNED_312_ISSUER_PENDING",
        "execution_authorized": False, "anchor_issued": False,
        "anchor_revoked": False, "original313_certified": False,
    }

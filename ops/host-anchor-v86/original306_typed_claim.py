#!/usr/bin/env python3
"""Original306 Host Anchor v2 -- read-only exact typed claimed Job validation.

This module classifies an already-Broker-claimed Host Anchor job. It neither
claims a Broker Job nor signs Original312, invokes the Host mutator, authorizes
execution, or certifies an Original313 receipt. All inputs to
inspect_claimed_original() must originate from original first-party runtime:
the exact immutable root Registry, the Broker's original claim/Job records
and the root-enrolled plan, never from a web-request payload.

Until a signed Original312 issuer + independent finalizer are released, every
matching Job remains HELD, including the first-Anchor bootstrap.
"""
from __future__ import annotations

import importlib.util
import re

ISSUE = "company.host-authority.anchor.issue"
REVOKE = "company.host-authority.anchor.revoke"
OPERATIONS = frozenset((ISSUE, REVOKE))
HEX32 = re.compile(r"[a-f0-9]{32}\Z")
HEX64 = re.compile(r"[a-f0-9]{64}\Z")


class HostV2ClaimDenied(ValueError):
    pass


def need(check: bool, reason: str) -> None:
    if not check:
        raise HostV2ClaimDenied(reason)


def inspect_claimed_original(registry_index, original_job: dict,
                             original_claim: dict, original_broker_row: dict,
                             original_plan: dict) -> dict:
    """Return evidence of exact matching scope, never execution admission."""
    import registry

    need(type(registry_index) is registry.Registry,
         "ORIGINAL306_ROOT_REGISTRY_REQUIRED")
    need(type(original_job) is dict and type(original_claim) is dict
         and type(original_broker_row) is dict and type(original_plan) is dict,
         "ORIGINAL306_INDEPENDENT_ORIGINALS_REQUIRED")
    job = original_job
    op = job.get("operation_id")
    need(op in OPERATIONS, "ORIGINAL306_HOST_V2_OPERATION_REQUIRED")
    manifest = registry_index.resolve(op)
    need(manifest.get("resource_kind") == "host_anchor"
         and manifest.get("target_scope") == "host"
         and manifest.get("job_type") == "signed_remote_hostinger_wpcli_v2"
         and manifest.get("command", {}).get("format") == "host.anchor.ref.v2"
         and manifest.get("source_family") ==
             "mad4b.original-host-authority-anchor.v2"
         and manifest.get("execution_requires_signed_originals") is True
         and manifest.get("execution_enabled") is False,
         "ORIGINAL306_HOST_ANCHOR_PROVIDER_NOT_COMMISSIONED")
    need(type(job.get("job_id")) is str
         and HEX32.fullmatch(job["job_id"]) is not None
         and type(job.get("plan_sha256")) is str
         and HEX64.fullmatch(job["plan_sha256"]) is not None
         and type(job.get("command_sha256")) is str
         and HEX64.fullmatch(job["command_sha256"]) is not None
         and job.get("job_type") == "signed_remote_hostinger_wpcli_v2"
         and job.get("principal_type") == "signed_host_principal_v2"
         and job.get("state") ==
             "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
         and job.get("executed") is False
         and job.get("target_scope") == "host"
         and job.get("command_format") == "host.anchor.ref.v2"
         and job.get("operation") == op,
         "ORIGINAL306_SIGNED_V2_JOB_SHAPE_REQUIRED")
    need(original_claim.get("job_id") == job["job_id"]
         and original_claim.get("command_sha256") == job["command_sha256"]
         and original_claim.get("state") ==
             "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
         and original_claim.get("executed") is False,
         "ORIGINAL306_SIGNED_CLAIM_INVALID")
    need(original_broker_row.get("job_id") == job["job_id"]
         and original_broker_row.get("command_sha256") ==
             job["command_sha256"]
         and original_broker_row.get("target_id") ==
             job.get("target_host_id"),
         "ORIGINAL306_INDEPENDENT_BROKER_ROW_MISMATCH")
    for field in ("company", "environment", "site_uuid", "origin",
                  "target_host_id"):
        need(type(job.get(field)) is str
             and job[field] == original_plan.get(field),
             "ORIGINAL306_HOST_SCOPE_MISMATCH:" + field)
    need(job.get("target_ssh_principal") == original_plan.get("ssh_principal")
         and job.get("broker_host_id") == "srv2030908"
         and original_broker_row.get("broker_host_id") == "srv2030908"
         and original_broker_row.get("company") == job.get("company")
         and original_broker_row.get("environment") == job.get("environment")
         and original_broker_row.get("site_uuid") == job.get("site_uuid"),
         "ORIGINAL306_HOST_PRINCIPAL_BINDING_MISMATCH")
    digest = original_plan.get("plan_sha256")
    need(type(digest) is str and HEX64.fullmatch(digest) is not None
         and digest == job.get("plan_sha256")
         and registry_index.command_sha256(op, digest) ==
             job["command_sha256"],
         "ORIGINAL306_IMMUTABLE_COMMAND_MISMATCH")
    # The installed first-party loader validates the source SHA again at call
    # time, including all declared transitive dependencies.
    verify = registry.load_provider_hook(
        registry_index, op, "preclaim",
        legacy_entrypoint="verify_claimable")
    checked = verify(registry_index, original_plan, job, original_claim)
    need(checked.get("operation_id") == op
         and checked.get("job_id") == job["job_id"]
         and checked.get("execution_authorized") is False
         and checked.get("original313_certified") is False
         and checked.get("anchor_issued") is False
         and checked.get("anchor_revoked") is False,
         "ORIGINAL306_ORIGINAL_PRECLAIM_NONAUTHORIZING_REQUIRED")
    return {
        "contract": "mad4b.original306.typed-host-anchor-claim-readback.v1",
        "operation_id": op,
        "job_id": job["job_id"],
        "plan_sha256": digest,
        "command_sha256": job["command_sha256"],
        "state": "HOST_V2_CLAIM_MATCHED__SIGNED_ORIGINAL312_ISSUER_PENDING",
        "original306_claim_matched": True,
        "issuer_commissioned": False,
        "execution_authorized": False,
        "grant_issued": False,
        "anchor_issued": False,
        "anchor_revoked": False,
        "original313_certified": False,
    }


def resolve_remote_host_v2_claim(*args, **kwargs):
    """Alias for root Worker linking; never an execution dispatcher."""
    return inspect_claimed_original(*args, **kwargs)

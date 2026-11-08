#!/usr/bin/env python3
"""Original 305/306/312 -> 313 exact Code Release operation contract.

Pure data validation. This module neither registers a WordPress ability nor
claims/jobs/grants, nor reads the authority key or deploys files. Source-only.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import re
import time
from typing import Any

CONTRACT = "mad4b.company-code-release.original313-contract.v1"
OPERATION = "company.code.release"
TRANSPORT_OPERATION = "company.remote.wpcli.full"
SCOPE = "wordpress"
COMMAND_FORMAT = "wpcli.argv.json.v1"
HOST = "srv2030908"
RX64 = re.compile(r"^[a-f0-9]{64}$")
RX40 = re.compile(r"^[a-f0-9]{40}$")
RX32 = re.compile(r"^[a-f0-9]{32}$")
COMPANY_RX = re.compile(r"^[a-z][a-z0-9-]{2,47}$")


class GateDenied(ValueError):
    pass


def require(condition: bool, code: str) -> None:
    if not condition:
        raise GateDenied(code)


def canonical(data: Any) -> bytes:
    return json.dumps(data, sort_keys=True, ensure_ascii=False,
                      separators=(",", ":")).encode("utf-8")


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def command_argv(plan_sha256: str) -> list[str]:
    """Immutable authority command identity, NOT executed as a raw shell."""
    require(isinstance(plan_sha256, str) and
            RX64.fullmatch(plan_sha256) is not None, "INVALID_PLAN_SHA256")
    return ["mad4b", "company-code-release", "apply",
            "--plan_sha256=" + plan_sha256]


def command_sha256(plan_sha256: str) -> str:
    return sha256(json.dumps(command_argv(plan_sha256),
                            ensure_ascii=False, separators=(",", ":")).encode())


def require_plan(plan: dict) -> dict:
    """Bind original authority to the SAME immutable release plan."""
    require(isinstance(plan, dict) and
            plan.get("contract") == "mad4b.company-code-release.plan.v1",
            "INVALID_RELEASE_PLAN")
    digest = plan.get("plan_sha256")
    git = plan.get("git_commit")
    require(isinstance(git, str) and RX40.fullmatch(git) is not None,
            "INVALID_FULL_GIT_COMMIT")
    require(isinstance(digest, str) and RX64.fullmatch(digest) is not None,
            "INVALID_RELEASE_PLAN_DIGEST")
    content = {k: v for k, v in plan.items()
               if k not in ("plan_sha256", "changed_count")}
    require(sha256(canonical(content)) == digest, "RELEASE_PLAN_CHANGED")
    company = plan.get("company")
    environment = plan.get("environment")
    require(isinstance(company, str) and COMPANY_RX.fullmatch(company) is not None
            and isinstance(environment, str) and
            re.fullmatch(r"[a-z][a-z0-9-]{1,63}", environment) is not None,
            "INVALID_COMPANY_ENVIRONMENT")
    require(isinstance(plan.get("site_uuid"), str) and
            re.fullmatch(r"[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}",
                         plan["site_uuid"]) is not None,
            "INVALID_SITE_UUID")
    require(isinstance(plan.get("origin"), str) and
            re.fullmatch(r"https://[a-z0-9.-]+", plan["origin"]) is not None,
            "INVALID_ORIGIN")
    require(isinstance(plan.get("files"), list) and
            1 <= len(plan["files"]) <= 24, "INVALID_RELEASE_FILE_COUNT")
    return plan


def candidate_original_operation(plan: dict) -> dict:
    """For authenticated original owner job creation only; no creation here."""
    p = require_plan(plan)
    args = command_argv(p["plan_sha256"])
    return {
        "contract": CONTRACT, "operation": OPERATION,
        "transport_operation": TRANSPORT_OPERATION,
        "target_scope": SCOPE, "command_format": COMMAND_FORMAT,
        "job_type": "signed_vps_wpcli",
        "company": p["company"], "environment": p["environment"],
        "site_uuid": p["site_uuid"], "origin": p["origin"],
        "target_host_id": HOST, "broker_host_id": HOST,
        "git_commit": p["git_commit"],
        "plan_sha256": p["plan_sha256"],
        "command_argv": args,
        "command_sha256": command_sha256(p["plan_sha256"]),
        "registrable": False,
        "write_authority_created": False,
        "original_313_receipt": False,
    }


def eligible_original_306_job(
    original_job: dict, original_pending: dict, plan: dict,
) -> None:
    """Reject if original signed Job differs from the requested release."""
    expected = candidate_original_operation(plan)
    require(isinstance(original_job, dict) and
            isinstance(original_pending, dict), "NO_ORIGINAL_JOB")
    for k in ("company", "environment", "site_uuid"):
        require(original_job.get(k) == expected[k] and
                original_pending.get(k) == expected[k],
                "ORIGINAL_306_JOB_IDENTITY_DRIFT:" + k)
    require(original_pending.get("broker_host_id") == HOST and
            original_pending.get("target_host_id") == HOST,
            "ORIGINAL_306_TARGET_HOST_DRIFT")
    require(original_job.get("operation") == TRANSPORT_OPERATION and
            original_job.get("target_scope") == SCOPE and
            original_job.get("command_format") == COMMAND_FORMAT and
            original_job.get("job_type") == "signed_vps_wpcli",
            "ORIGINAL_306_JOB_CONTRACT_INVALID")
    require(original_job.get("command_sha256") ==
            expected["command_sha256"], "ORIGINAL_306_COMMAND_HASH_MISMATCH")
    require(original_job.get("state") == "QUEUED_FOR_VPS_BROKER_CLAIM",
            "ORIGINAL_306_JOB_NOT_QUEUED")
    require(isinstance(original_pending.get("job_id"), str) and
            RX32.fullmatch(original_pending["job_id"]) is not None,
            "INVALID_ORIGINAL_JOB_ID")
    require(isinstance(original_pending.get("expires_epoch"), int) and
            original_pending["expires_epoch"] > time.time() + 90,
            "ORIGINAL_306_JOB_EXPIRED")


def eligible_original_312_claim(
    authority: dict, plan: dict, job_id: str,
    *,
    min_window_seconds: int = 360,
) -> None:
    """Only original WordPress verifier may mark a 312 grant eligible."""
    p = candidate_original_operation(plan)
    require(isinstance(job_id, str) and
            RX32.fullmatch(job_id) is not None, "INVALID_ORIGINAL_JOB_ID")
    require(isinstance(authority, dict) and authority.get("contract") ==
            "mad4b.broker-exact-ssh-authority.v1",
            "ORIGINAL_312_CONTRACT_INVALID")
    require(authority.get("verified") is True and
            authority.get("eligible") is True and
            authority.get("owner_enrolled_wpcli") is True and
            authority.get("central_scope_only") is True and
            authority.get("shell_executed") is False,
            "ORIGINAL_312_NOT_ELIGIBLE")
    for key, val in (("job_id", job_id), ("target_id", HOST),
                     ("company", p["company"]),
                     ("environment", p["environment"]),
                     ("site_uuid", p["site_uuid"]),
                     ("origin", p["origin"]),
                     ("command_sha256", p["command_sha256"]),
                     ("operation", TRANSPORT_OPERATION),
                     ("target_scope", SCOPE)):
        require(authority.get(key) == val,
                "ORIGINAL_312_SCOPE_DRIFT:" + key)
    require(isinstance(authority.get("expires_epoch"), int) and
            authority["expires_epoch"] >= time.time() + min_window_seconds,
            "ORIGINAL_312_WINDOW_TOO_SHORT")


def independent_release_readback(
    plan: dict, applied_receipt: dict, observed_sha256_by_target: dict,
) -> dict:
    """Host-only readback projection; never self-certifies central 313."""
    p = require_plan(plan)
    require(isinstance(applied_receipt, dict) and
            applied_receipt.get("contract") ==
            "mad4b.company-code-release.receipt.v1" and
            applied_receipt.get("state") == "APPLIED_VERIFIED" and
            applied_receipt.get("plan_sha256") == p["plan_sha256"] and
            applied_receipt.get("broker_grant_signature_verified") is True and
            applied_receipt.get("broker_receipt_verified") is False and
            applied_receipt.get("original_313_receipt") is False,
            "EXACT_LOCAL_RELEASE_RECEIPT_NOT_VERIFIED")
    require(isinstance(observed_sha256_by_target, dict),
            "INDEPENDENT_TARGET_DIGESTS_MISSING")
    expected = {x["target"]: x["source_sha256"] for x in p["files"]}
    require(observed_sha256_by_target == expected,
            "INDEPENDENT_POST_RELEASE_READBACK_DRIFT")
    return {
        "contract": CONTRACT,
        "state": "HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313",
        "plan_sha256": p["plan_sha256"],
        "git_commit": p["git_commit"],
        "company": p["company"], "environment": p["environment"],
        "file_count": len(expected),
        "readback_sha256": sha256(canonical(expected)),
        "central_313_registered": False,
        "original_313_certified": False,
        "production_authority_created": False,
    }

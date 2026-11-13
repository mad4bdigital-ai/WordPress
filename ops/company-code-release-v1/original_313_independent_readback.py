#!/usr/bin/env python3
"""Independent source-only Code Release file readback for original 313.

Observes actual WordPress files AFTER an independently authorized execution.
Never signs a Central receipt, creates a Job/Claim/Grant, or calls the mutating
Company Code Release operator. Original 313 finalization is a separate
first-party Broker path, not implied by this local evidence.
"""
from __future__ import annotations

import json
from pathlib import Path
from typing import Callable

import company_code_release as release
import original_313_code_release_contract as original
import original_313_host_handler as handler


def fresh_file_sha256(plan: dict) -> dict[str, str | None]:
    root = Path(plan["target_root"])
    result: dict[str, str | None] = {}
    for row in plan["files"]:
        rel = release.exact_relative(row["target"])
        file = root / rel
        release.no_link(file)
        if file.exists() and not file.is_file():
            raise handler.NotEnrolled("INDEPENDENT_TARGET_KIND_CHANGED")
        result[rel] = release.digest(release.safe_regular(file)) if file.is_file() else None
    return result


def independent_verify(
    job_id: str,
    plan: dict,
    policy: dict,
    grant: dict,
    authority_proof: dict,
    *,
    runtime_check: Callable[[dict, dict], None] = release.assert_runtime_binding,
    php_check: Callable[[dict], None] = release.php_lint,
    fresh_read: Callable[[dict], dict] = fresh_file_sha256,
    receipt_read: Callable[[Path], dict] = handler.trusted_load,
) -> dict:
    # No metadata-based authorization. Original authority still checks
    # one actual Job and the original command SHA.
    if grant.get("original_job_id") != job_id:
        raise handler.NotEnrolled("INDEPENDENT_JOB_AND_GRANT_MISMATCH")
    original.eligible_original_312_claim(
        authority_proof, plan, job_id, min_window_seconds=20
    )
    release.verify_grant(plan, policy, grant, "apply")
    receipts = Path(policy["receipt_dir"])
    release.assert_pinned_receipt_directory(policy, receipts)
    nonce = grant.get("nonce")
    if (not isinstance(nonce, str) or
            release.NONCE.fullmatch(nonce) is None):
        raise handler.NotEnrolled("ORIGINAL_RELEASE_GRANT_NONCE_INVALID")
    receipt = receipt_read(receipts / (nonce + ".json"))
    if (receipt.get("nonce") != nonce or
            receipt.get("company") != plan["company"] or
            receipt.get("environment") != plan["environment"] or
            receipt.get("site_uuid") != plan["site_uuid"] or
            receipt.get("git_commit") != plan["git_commit"]):
        raise handler.NotEnrolled("INDEPENDENT_HOST_RECEIPT_SCOPE_MISMATCH")
    # Do not reclassify an incomplete/partial Host Apply as an ordinary
    # postimage drift. It must first finish a separately signed recovery.
    if receipt.get("state") != "APPLIED_VERIFIED":
        raise handler.NotEnrolled("INDEPENDENT_HOST_APPLY_NOT_VERIFIED")
    release.confirm_plan(plan, policy, expect_preimage=False)
    runtime_check(plan, policy)
    php_check(plan)
    observed = fresh_read(plan)
    return original.independent_release_readback(plan, receipt, observed)

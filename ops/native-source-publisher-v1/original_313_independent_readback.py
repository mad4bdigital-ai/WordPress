#!/usr/bin/env python3
"""Unsigned, independent original313 evidence for the native source publisher.

Only reads the real root-owned host receipt and deployed JS after a claimed
signed Original312 job. Never creates a proof signature or Central313 receipt.
The separate first-party independent signer/finalizer owns that authority.
"""
from __future__ import annotations

import json
from pathlib import Path

import native_source_publisher as publisher
import original_313_host_handler as handler

FIELDS = {
    "result_status", "output_sha256", "readback_sha256", "expected_readback_sha256",
    "grant_id", "transport_id", "production_mutation", "target_sha256",
    "file_count",
}
SUCCESS = "HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313"


def receipt_bound_production_mutation(plan: dict, record: dict,
                                      broker_grant: dict) -> bool:
    """Derive environment flag only after checking the *actual host receipt*.

    This is a verifier field, not authority to write. A caller's
    `production_mutation` flag is never accepted as evidence. The caller must
    have first read the secure receipt, verified the signed grant and freshly
    checked the live target against the immutable plan.
    """
    if (not isinstance(plan, dict) or not isinstance(record, dict) or
        not isinstance(broker_grant, dict) or
        plan.get("environment") not in ("staging", "production")):
        raise publisher.Denied("SOURCE_INDEPENDENT_RECEIPT_ENVIRONMENT_INVALID")
    expected = {
        "contract": publisher.core.RECEIPT_CONTRACT,
        "state": "APPLIED_VERIFIED",
        "company": plan.get("company"),
        "environment": plan["environment"],
        "site_uuid": plan.get("site_uuid"),
        "git_commit": plan.get("git_commit"),
        "plan_sha256": plan.get("plan_sha256"),
        "nonce": broker_grant.get("nonce"),
        "files": [row["target"] for row in plan.get("files", [])],
        "failed_reason": None,
        "broker_grant_signature_verified": True,
        "broker_receipt_verified": False,
        "original_313_receipt": False,
    }
    if (not isinstance(plan.get("files"), list) or
        not plan["files"] or
        any(not isinstance(row, dict) or
                not isinstance(row.get("target"), str)
                for row in plan["files"]) or
        any(type(record.get(key)) is not type(value) or
                record.get(key) != value for key, value in expected.items())):
        raise publisher.Denied("SOURCE_INDEPENDENT_RECEIPT_SCOPE_OR_RESULT_DRIFT")
    # `check_plan(after=True)` plus the actual success receipt prove an apply;
    # the installed Original313 verifier binds this boolean to environment.
    return plan["environment"] == "production"


def evidence_for_original_job(job_id: str, plan: dict, policy: dict,
                              broker_grant: dict) -> dict:
    """Re-observe original signed 312 plus live target, not caller flags."""
    publisher.check_plan(plan,policy,after=True)
    signed = handler.original_312_for_claim(job_id,plan)
    if broker_grant.get("original_job_id") != job_id:
        raise publisher.Denied("SOURCE_INDEPENDENT_JOB_GRANT_MISMATCH")
    publisher.core.verify_grant(plan,publisher.company_policy(policy),
                                broker_grant,"apply")
    publisher.native_runtime_binding(plan,policy)
    receipts=Path(policy["receipt_dir"])
    publisher.core.assert_pinned_receipt_directory(
        publisher.company_policy(policy),receipts)
    nonce=broker_grant["nonce"]
    receipt_path=receipts/(nonce+".json")
    record=json.loads(handler.protected_file(receipt_path,receipts))
    production_mutation = receipt_bound_production_mutation(
        plan, record, broker_grant
    )
    digests=[]
    for source in plan["files"]:
        target=Path(plan["target_root"])/source["target"]
        publisher.core.no_link(target)
        observed=publisher.sha(publisher.core.safe_regular(target))
        if observed!=source["source_sha256"]:
            raise publisher.Denied("SOURCE_INDEPENDENT_READBACK_SHA_MISMATCH")
        digests.append({"target":source["target"],"sha256":observed})
    fresh=publisher.sha(publisher.canonical({"files":digests}))
    expected=fresh
    grant=signed.get("grant_id")
    transport=signed.get("transport_id")
    if not isinstance(grant,str) or not grant or not isinstance(transport,str) or not transport:
        raise publisher.Denied("SOURCE_INDEPENDENT_312_TRANSPORT_NOT_BOUND")
    payload={
       "result_status":SUCCESS,
       "output_sha256":fresh,
       "readback_sha256":fresh,
       "expected_readback_sha256":expected,
       "target_sha256":fresh,
       "grant_id":grant,
       "transport_id":transport,
       "production_mutation":production_mutation,
       "file_count":len(plan["files"])
    }
    if set(payload)!=FIELDS:
        raise publisher.Denied("SOURCE_INDEPENDENT_EVIDENCE_CONTRACT_DRIFT")
    return payload


def inspect_without_original_job(plan: dict, policy: dict) -> dict:
    """Pure file readback, explicitly insufficient for signed original313."""
    publisher.check_plan(plan,policy,after=True)
    publisher.native_runtime_binding(plan,policy)
    observed=[]
    for source in plan["files"]:
        path=Path(plan["target_root"])/source["target"]
        publisher.core.no_link(path)
        digest=publisher.sha(publisher.core.safe_regular(path))
        if digest!=source["source_sha256"]:
            raise publisher.Denied("SOURCE_READBACK_SHA_MISMATCH")
        observed.append({"target":source["target"],"sha256":digest})
    digest=publisher.sha(publisher.canonical({"files":observed}))
    return {
        "contract":"mad4b.native-source-publisher.file-readback-only.v1",
        "state":"EXACT_FILE_HASH_READBACK_ONLY",
        "site_uuid":plan["site_uuid"], "origin":plan["origin"],
        "plan_sha256":plan["plan_sha256"],
        "readback_sha256":digest,
        "original_312_signed_job_verified":False,
        "original_313_signed_receipt_issued":False,
        "signature_generated":False
    }

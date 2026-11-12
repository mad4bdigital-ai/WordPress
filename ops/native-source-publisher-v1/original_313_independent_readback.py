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
    "result_status", "readback_sha256", "expected_readback_sha256",
    "grant_id", "transport_id", "production_mutation", "target_sha256",
    "file_count",
}
SUCCESS = "HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313"


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
    if (record.get("state") != "APPLIED_VERIFIED" or
        record.get("nonce")!=nonce or
        record.get("plan_sha256")!=plan["plan_sha256"] or
        record.get("site_uuid")!=plan["site_uuid"] or
        record.get("git_commit")!=plan["git_commit"]):
        raise publisher.Denied("SOURCE_INDEPENDENT_NATIVE_RECEIPT_CHANGED")
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
       "readback_sha256":fresh,
       "expected_readback_sha256":expected,
       "target_sha256":fresh,
       "grant_id":grant,
       "transport_id":transport,
       "production_mutation":False,
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

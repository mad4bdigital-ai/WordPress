#!/usr/bin/env python3
"""Non-authorizing Original305/306 Job/Claim lifecycle classification.

Input must come from a separately authenticated first-party readback; this
module does not fetch WordPress data, accept readback-as-authority flags, issue
new Associations, claim jobs or replay Original312. All states are HOLD.
"""
from __future__ import annotations
import re
from typing import Any

SHA = re.compile(r"^[a-f0-9]{64}$")
JOB_ID = re.compile(r"^[a-f0-9]{32}$")
QUEUED = "QUEUED_FOR_VPS_BROKER_CLAIM"
CLAIMED = "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"


class LifecyclePreviewDenied(ValueError):
    pass


def classify(
    plan: dict[str, Any],
    expected_command_sha256: str,
    records: list[dict],
    claims: dict[str, dict],
    *,
    now_epoch: int,
) -> dict:
    """Classify *supplied* original records without asserting authenticity.

    A record is eligible for association only after independent approved
    first-party issuer readback and original306/312/313 verification.
    The result from this pure function NEVER grants that eligibility.
    """
    if (not isinstance(plan,dict) or not isinstance(records,list) or
        not isinstance(claims,dict) or type(now_epoch) is not int or
        not isinstance(expected_command_sha256,str) or
        not SHA.fullmatch(expected_command_sha256) or len(records)>500):
        raise LifecyclePreviewDenied("ORIGINAL_JOB_READBACK_INPUT_INVALID")
    required_scope=("company","environment","site_uuid")
    if (not all(isinstance(plan.get(k),str) and plan[k] for k in required_scope) or
        not isinstance(plan.get("origin"),str) or
        not plan["origin"].startswith("https://")):
        raise LifecyclePreviewDenied("ORIGINAL_PLAN_SCOPE_INVALID")

    matching=[]
    conflicting=[]
    for record in records:
        if not isinstance(record,dict):
            raise LifecyclePreviewDenied("ORIGINAL_JOB_RECORD_INVALID")
        if record.get("command_sha256")!=expected_command_sha256:
            continue
        if (not all(record.get(k)==plan[k] for k in required_scope) or
            record.get("origin",record.get("canonical_origin"))!=plan["origin"]):
            conflicting.append(record)
            continue
        matching.append(record)

    def output(state: str, *, count: int=0) -> dict:
        return {
            "contract":"mad4b.original305312.job-claim-lifecycle-preview.v1",
            "state":state,
            "matching_job_count":count,
            "conflicting_job_count":len(conflicting),
            "current_job_readback_authenticated_by_this_function":False,
            "original312_signed_eligibility_verified":False,
            "original313_independent_certification_verified":False,
            "new_association_issued":False,
            "job_claimed_or_replayed":False,
            "existing_original_job_changed":False,
            "execution_authorized":False,
        }

    if conflicting:
        return output("HOLD__COMMAND_SCOPE_CONFLICT",count=len(matching))
    if len(matching)==0:
        return output("HOLD__NO_ORIGINAL_JOB_READBACK")
    if len(matching)!=1:
        return output("HOLD__MULTIPLE_ORIGINAL_JOBS_SAME_COMMAND",count=len(matching))
    job=matching[0]
    job_id=job.get("job_id")
    created=job.get("created_epoch")
    expires=job.get("expires_epoch")
    if (not isinstance(job_id,str) or not JOB_ID.fullmatch(job_id) or
        type(created) is not int or type(expires) is not int or
        created<=0 or expires<=created or
        job.get("target_scope")!="wordpress" or
        job.get("executed") not in (True,False)):
        return output("HOLD__ORIGINAL_JOB_IDENTITY_INCOMPLETE",count=1)

    state=job.get("state")
    claim=claims.get(job_id)
    if state==QUEUED:
        if claim is not None or job.get("executed") is not False:
            return output("HOLD__QUEUED_BUT_PREVIOUS_CLAIM_OR_EXECUTION",count=1)
        if now_epoch>=expires:
            return output("HOLD__QUEUED_EXPIRED__ARCHIVE_ONLY",count=1)
        return output("HOLD__QUEUED__SIGNED_CLAIM_NOT_ISSUED",count=1)

    if state==CLAIMED:
        if (not isinstance(claim,dict) or
            claim.get("contract")!="mad4b.central-vps-broker.v1" or
            claim.get("state")!=CLAIMED or claim.get("job_id")!=job_id or
            claim.get("host_id")!=job.get("broker_host_id") or
            type(claim.get("claimed_epoch")) is not int or
            claim.get("claimed_epoch")!=job.get("claimed_epoch") or
            claim.get("production_mutation_performed") is not False):
            return output("HOLD__ORIGINAL_SIGNED_CLAIM_RECORD_NOT_CORRELATED",count=1)
        if now_epoch>=expires:
            return output("HOLD__CLAIMED_EXPIRED__RECEIPT_ONLY_RECONCILE",count=1)
        if job.get("executed") is not False:
            return output("HOLD__CLAIMED_EXECUTION_UNCERTAIN",count=1)
        return output("HOLD__CLAIMED__FRESH_ORIGINAL312_REQUIRED",count=1)

    return output("HOLD__UNKNOWN_OR_TERMINAL_JOB_STATE__REVIEW",count=1)

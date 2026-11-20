#!/usr/bin/env python3
"""Non-authorizing classifier for the *redacted* Original306 Job read surface.

Consumes the narrow WordPress source-only candidate output without requiring
Job IDs, broker tokens or grant contents. Caller MUST separately attest the
source, transport and original312 state before any real authorization.
"""
from __future__ import annotations
import re
from typing import Any

HEX64=re.compile(r"^[a-f0-9]{64}$")
READ_CONTRACT="mad4b.original305312.readonly-job-claim.v1"
READ_STATE="READ_ONLY__ORIGINAL312_INDEPENDENT_SIGNATURE_REQUIRED"
QUEUED="QUEUED_FOR_VPS_BROKER_CLAIM"
CLAIMED="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
EXPECTED_RECORD_FIELDS=frozenset((
    "state", "job_expired", "job_executed",
    "claim_present", "claim_exact", "route_reserved",
))
MAX_AGE_SECONDS=120


class RedactedReadbackDenied(ValueError):
    pass


def classify_redacted(
    plan: dict[str, Any],
    digest: str,
    snapshot: dict[str, Any],
    *,
    now_epoch: int,
) -> dict:
    """Conservative state classification. This output is NEVER an approval.

    Validated readback transport MUST be provided separately by first-party
    host admission; this routine cannot establish signature or trusted origin.
    """
    if (not isinstance(plan,dict) or not isinstance(digest,str) or
        HEX64.fullmatch(digest) is None or not isinstance(snapshot,dict) or
        type(now_epoch) is not int):
        raise RedactedReadbackDenied("INVALID_REDACTED_JOB_READBACK_INPUT")
    if (snapshot.get("contract")!=READ_CONTRACT or
        snapshot.get("state")!=READ_STATE):
        raise RedactedReadbackDenied("READBACK_CONTRACT_NOT_VERIFIED")
    for key in ("site_uuid","company","origin","environment"):
        if (not isinstance(plan.get(key),str) or not plan[key] or
            snapshot.get(key)!=plan[key]):
            raise RedactedReadbackDenied("REDACTED_READBACK_SITE_SCOPE_DRIFT")
    if any(snapshot.get(k) is not False for k in (
        "original312_eligible",
        "signed_original313_receipt_verified",
        "job_or_claim_mutated",
        "authority_created",
    )):
        raise RedactedReadbackDenied("READBACK_MAY_CLAIM_AUTHORITY_OR_MUTATION")
    observed=snapshot.get("observed_epoch")
    if type(observed) is not int:
        raise RedactedReadbackDenied("READBACK_OBSERVATION_CLOCK_MISSING")
    commands=snapshot.get("commands")
    if (not isinstance(commands,list) or len(commands)>24 or
        any(not isinstance(row,dict) for row in commands)):
        raise RedactedReadbackDenied("READBACK_BOUNDED_COMMANDS_INVALID")
    matched=[row for row in commands if row.get("command_sha256")==digest]
    if len(matched)!=1:
        raise RedactedReadbackDenied("READBACK_EXPECTED_COMMAND_NOT_UNIQUE")
    row=matched[0]
    count=row.get("job_count")
    conflicts=row.get("conflicting_scope_count")
    if (type(count) is not int or type(conflicts) is not int or
        count<0 or conflicts<0 or count>1000 or conflicts>1000):
        raise RedactedReadbackDenied("READBACK_JOB_COUNTS_NOT_TRUSTED")
    def result(state: str) -> dict:
        # A description of a Job is not permission to execute it.
        # Conflicting or duplicate source data is rejected as evidence.
        if state in (
            "HOLD__QUEUED_NOT_CLAIMED", "HOLD__CLAIMED_NEEDS_FRESH_ORIGINAL312",
        ):
            disposition = "DESCRIBABLE_NOT_EXECUTABLE"
        elif state in (
            "HOLD__COMMAND_SCOPE_COLLISION", "HOLD__MULTIPLE_JOBS_SHARE_COMMAND",
            "HOLD__READBACK_NOT_UNIQUE", "HOLD__QUEUED_JOB_WITH_CLAIM_OR_RESERVATION",
            "HOLD__CLAIMED_BUT_CORRELATION_MISSING",
        ):
            disposition = "CONFLICT_REJECTED"
        else:
            disposition = "RECONCILIATION_REQUIRED"
        return {
            "contract":"mad4b.original305312.redacted-job-lifecycle-preview.v1",
            "state":state,
            "disposition":disposition,
            "command_sha256":digest,
            "matching_job_count":count,
            "conflicting_scope_count":conflicts,
            "readback_observed_epoch":observed,
            "independent_readback_authenticity_verified":False,
            "original312_signed_eligibility_verified":False,
            "original313_independent_certification_verified":False,
            "association_issued":False,
            "job_claimed_or_replayed":False,
            "execution_authorized":False,
        }

    if observed>now_epoch+10 or now_epoch-observed>MAX_AGE_SECONDS:
        return result("HOLD__STALE_OR_FUTURE_READBACK")
    if conflicts:
        return result("HOLD__COMMAND_SCOPE_COLLISION")
    if count==0:
        return result("HOLD__NO_ORIGINAL_JOB_MATCH")
    if count>1:
        return result("HOLD__MULTIPLE_JOBS_SHARE_COMMAND")
    if row.get("state")!="HOLD__CURRENT_JOB_READ_ONLY":
        return result("HOLD__READBACK_NOT_UNIQUE")
    record=row.get("record")
    if (not isinstance(record,dict) or
        set(record)!=EXPECTED_RECORD_FIELDS):
        raise RedactedReadbackDenied("READBACK_REDACTED_RECORD_SHAPE_INVALID")
    if (not isinstance(record["state"],str) or len(record["state"])>100 or
        (record["job_expired"] is not None and
         type(record["job_expired"]) is not bool) or
        (record["job_executed"] is not None and
         type(record["job_executed"]) is not bool) or
        any(type(record[key]) is not bool for key in (
            "claim_present","claim_exact","route_reserved"
        ))):
        raise RedactedReadbackDenied("READBACK_REDACTED_RECORD_TYPES_INVALID")
    if record["job_executed"] is True:
        return result("HOLD__JOB_ALREADY_EXECUTED_OR_RESULT_UNCERTAIN")
    if record["job_expired"] is None:
        return result("HOLD__MISSING_JOB_EXPIRY")
    if record["state"]==QUEUED:
        if record["claim_present"] or record["route_reserved"]:
            return result("HOLD__QUEUED_JOB_WITH_CLAIM_OR_RESERVATION")
        if record["job_expired"]:
            return result("HOLD__QUEUED_EXPIRED_ARCHIVE_ONLY")
        return result("HOLD__QUEUED_NOT_CLAIMED")
    if record["state"]==CLAIMED:
        if (not record["claim_present"] or
            not record["claim_exact"] or
            not record["route_reserved"]):
            return result("HOLD__CLAIMED_BUT_CORRELATION_MISSING")
        if record["job_expired"]:
            return result("HOLD__CLAIMED_EXPIRED_RECEIPT_RECONCILIATION_ONLY")
        return result("HOLD__CLAIMED_NEEDS_FRESH_ORIGINAL312")
    return result("HOLD__UNKNOWN_OR_TERMINAL_JOB_STATUS")


def classify_batch(
    plan: dict[str, Any],
    expected_digests: list[str],
    snapshot: dict[str, Any],
    *,
    now_epoch: int,
) -> dict:
    """Fail-closed read-only classification of an entire first-party snapshot.

    Unlike per-command previews, a missing/extra/duplicate requested command
    invalidates the entire batch; no partial evidence is reported as complete.
    Neither this function nor its individual entries attest a transport,
    first-party origin, signed Original312 state or Original313 certification.
    """
    if (not isinstance(expected_digests, list) or
        not 1 <= len(expected_digests) <= 24 or
        any(not isinstance(x, str) or HEX64.fullmatch(x) is None
            for x in expected_digests) or
        len(set(expected_digests)) != len(expected_digests) or
        not isinstance(snapshot, dict)):
        raise RedactedReadbackDenied("READBACK_BATCH_EXPECTATIONS_INVALID")
    rows = snapshot.get("commands")
    if not isinstance(rows, list) or len(rows) != len(expected_digests):
        raise RedactedReadbackDenied("READBACK_BATCH_INCOMPLETE_OR_EXTRA")
    digests = [row.get("command_sha256") for row in rows
               if isinstance(row, dict)]
    if (len(digests) != len(rows) or
        any(not isinstance(x, str) or HEX64.fullmatch(x) is None for x in digests) or
        len(set(digests)) != len(digests) or
        set(digests) != set(expected_digests)):
        raise RedactedReadbackDenied("READBACK_BATCH_DIGEST_SET_MISMATCH")
    scanned = snapshot.get("job_store_scanned")
    if type(scanned) is not int or not 0 <= scanned <= 1000:
        raise RedactedReadbackDenied("READBACK_BATCH_JOB_SCAN_UNVERIFIED")
    forbidden = frozenset(("job_id", "grant", "grant_id", "nonce", "token",
                            "signature", "transport", "claim_secret"))
    if forbidden.intersection(snapshot):
        raise RedactedReadbackDenied("READBACK_BATCH_PRIVATE_FIELD_PRESENT")
    for row in rows:
        if forbidden.intersection(row):
            raise RedactedReadbackDenied("READBACK_BATCH_PRIVATE_FIELD_PRESENT")

    results = [classify_redacted(plan, digest, snapshot, now_epoch=now_epoch)
               for digest in expected_digests]
    counts = {key: 0 for key in (
        "DESCRIBABLE_NOT_EXECUTABLE", "RECONCILIATION_REQUIRED", "CONFLICT_REJECTED"
    )}
    for row in results:
        counts[row["disposition"]] += 1
    disposition = (
        "CONFLICT_REJECTED" if counts["CONFLICT_REJECTED"] else
        "RECONCILIATION_REQUIRED" if counts["RECONCILIATION_REQUIRED"] else
        "DESCRIBABLE_NOT_EXECUTABLE"
    )
    return {
        "contract": "mad4b.original305312.redacted-batch-preview.v1",
        "state": "HOLD__SIGNED_CURRENT_AUTHORITY_NOT_VERIFIED",
        "disposition": disposition,
        "command_count": len(expected_digests),
        "job_store_scanned": scanned,
        "readback_observed_epoch": snapshot["observed_epoch"],
        "disposition_counts": counts,
        "commands": results,
        "original312_signed_eligibility_verified": False,
        "original313_independent_certification_verified": False,
        "readback_transport_authenticated_by_classifier": False,
        "association_issued": False,
        "job_claimed_or_replayed": False,
        "execution_authorized": False,
    }

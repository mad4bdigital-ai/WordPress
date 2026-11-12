#!/usr/bin/env python3
"""Read-only original Broker grant evidence for existing CAS plan inventory.

Verifies signature, scope, expiry, job ID/file naming and distinct plan
coverage. Does NOT verify current WordPress Job/Claim/Original312 eligibility,
sign an association, refresh a grant, or certify Original313.
"""
from __future__ import annotations
import base64
from collections import Counter
from datetime import datetime, timezone, timedelta
from pathlib import Path
import json
import re
import stat
from typing import Any, Callable
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

SHA = re.compile(r"^[0-9a-f]{64}$")
JOB = re.compile(r"^[0-9a-f]{32}$")
MAX_GRANTS=256


class HistoricalGrantDenied(ValueError):
    pass


def trusted_directory(folder: Path) -> None:
    if (not folder.is_absolute() or folder.is_symlink() or
        not folder.is_dir() or folder.stat().st_uid!=0 or
        stat.S_IMODE(folder.stat().st_mode)&0o077):
        raise HistoricalGrantDenied("FIRST_PARTY_GRANT_STORE_UNTRUSTED")


def read_exact_grant(path: Path, folder: Path) -> dict:
    if (path.parent!=folder or path.is_symlink() or not path.is_file() or
        not JOB.fullmatch(path.stem)):
        raise HistoricalGrantDenied("GRANT_PATH_UNTRUSTED")
    st=path.stat()
    if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or
        stat.S_IMODE(st.st_mode)&0o077 or st.st_size<1 or st.st_size>32_768):
        raise HistoricalGrantDenied("GRANT_ROOT_OWNERSHIP_REQUIRED")
    try:
        doc=json.loads(path.read_bytes())
    except (ValueError,UnicodeError) as exc:
        raise HistoricalGrantDenied("GRANT_JSON_INVALID") from exc
    if not isinstance(doc,dict):
        raise HistoricalGrantDenied("GRANT_NOT_OBJECT")
    return doc


def when(value: Any) -> datetime:
    if not isinstance(value,str):
        raise ValueError("timestamp required")
    stamp=datetime.fromisoformat(value.replace("Z","+00:00"))
    if stamp.tzinfo is None:
        raise ValueError("timezone required")
    return stamp.astimezone(timezone.utc)


def summarize(
    plans: dict[str,dict],
    grant_folder: Path,
    *,
    broker_public_key_b64: str,
    signed_message: Callable[[dict],bytes],
    expected_contract: str,
    now: datetime,
) -> dict:
    """Count DISTINCT plans and separate duplicate historical grant attempts."""
    root=Path(grant_folder)
    trusted_directory(root)
    if (not isinstance(plans,dict) or
        any(not isinstance(sha,str) or not SHA.fullmatch(sha) or
            not isinstance(p,dict) or p.get("plan_sha256")!=sha
            for sha,p in plans.items()) or
        not isinstance(expected_contract,str) or
        not expected_contract or
        not isinstance(now,datetime) or now.tzinfo is None):
        raise HistoricalGrantDenied("HISTORICAL_PLAN_INPUT_INVALID")
    try:
        key_bytes=base64.b64decode(broker_public_key_b64,validate=True)
        if len(key_bytes)!=32:raise ValueError("bad key")
        key=Ed25519PublicKey.from_public_bytes(key_bytes)
    except (ValueError,TypeError) as exc:
        raise HistoricalGrantDenied("BROKER_PUBLIC_KEY_INVALID") from exc
    files=sorted(root.glob("*.json"))
    if len(files)>MAX_GRANTS:
        raise HistoricalGrantDenied("GRANT_STORE_COUNT_EXCEEDED")
    matched=Counter()
    invalid=set()
    valid_signed_count=0
    expired_count=0
    active_count=0
    unrelated_count=0
    for file in files:
        grant=read_exact_grant(file,root)
        sha=grant.get("plan_sha256")
        if sha not in plans:
            unrelated_count+=1
            continue
        plan=plans[sha]
        matched[sha]+=1
        try:
            if (grant.get("contract")!=expected_contract or
                grant.get("action")!="apply" or
                grant.get("issuer")!="mad4b-company-broker" or
                grant.get("original_job_id")!=file.stem or
                not all(grant.get(k)==plan.get(k)
                        for k in ("company","environment","site_uuid","origin"))):
                raise ValueError("original grant scope mismatch")
            issue=when(grant["issued_at"])
            expiry=when(grant["expires_at"])
            if not issue<expiry<=issue+timedelta(minutes=30):
                raise ValueError("original grant historical lifetime invalid")
            key.verify(
                base64.b64decode(grant["signature"],validate=True),
                signed_message(grant),
            )
            valid_signed_count+=1
            if expiry<=now:
                expired_count+=1
            else:
                active_count+=1
        except Exception:
            invalid.add(sha)
    unique_valid=set(matched)-invalid
    return {
        "contract":"mad4b.original305.historical-signed-grant-audit.v1",
        "state":"HISTORICAL_SIGNED_GRANTS_ONLY__CURRENT_AUTHORITY_UNVERIFIED",
        "original_plans_in_scope":len(plans),
        "matched_grant_file_count":sum(matched.values()),
        "distinct_plans_with_matching_grant_files":len(matched),
        "distinct_plans_with_valid_grant_evidence":len(unique_valid),
        "distinct_plans_without_matching_grant_evidence":len(set(plans)-unique_valid),
        "plans_with_multiple_historical_grant_files":
            sum(count>1 for count in matched.values()),
        "additional_historical_grant_files_due_to_retries":
            sum(max(0,count-1) for count in matched.values()),
        "historical_grants_valid_signature_and_scope":valid_signed_count,
        "historical_grants_expired":expired_count,
        "historical_grants_unexpired_not_authorized":active_count,
        "plans_with_invalid_or_conflicting_grant_files":len(invalid),
        "unrelated_grant_files_skipped":unrelated_count,
        "current_original305_job_verified":False,
        "fresh_original312_verified":False,
        "original313_final_certificate_verified":False,
        "grants_extended_or_issued":False,
        "plans_or_wordpress_mutated":False,
    }

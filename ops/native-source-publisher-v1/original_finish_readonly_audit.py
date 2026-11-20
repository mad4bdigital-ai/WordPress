#!/usr/bin/env python3
"""Read-only Original305/312 evidence matching without granting eligibility.

Matches installed Original306 command digests to historical signed Host FINISH
attempts under a trusted root-owned receipt directory. A signed FINISH is NOT
an independently certified Original313 receipt, nor a current 305/312 Job.
"""
from __future__ import annotations

import base64
from collections import Counter
from pathlib import Path
import json
import re
import sqlite3
import stat
from typing import Any

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

FINISH = "mad4b.broker-wpcli-native-finish.v1"
FIELDS = (
    "contract","host_id","job_id","target_id","grant_id","transport_id",
    "site_uuid","origin","environment","command_sha256",
    "native_receipt_sha256","expected_readback_sha256","readback_sha256",
    "output_sha256","exit_code","issued","expires","nonce"
)
SHA = re.compile(r"^[a-f0-9]{64}$")
HEX32 = re.compile(r"^[a-f0-9]{32}$")
MAX_RECEIPTS = 300
MAX_SIZE = 32_768
DEFAULT_RECEIPTS = Path(
    "/opt/mad4b-company-transport/native-313-independent/receipts"
)
DEFAULT_JOURNAL = Path(
    "/opt/mad4b-company-transport/dispatch-queue-v1.sqlite3"
)


class EvidenceUnverified(ValueError):
    pass


def trusted_file(path: Path, *, root: Path, maximum: int=MAX_SIZE) -> bytes:
    if not path.is_absolute() or path.is_symlink() or path.parent != root:
        raise EvidenceUnverified("UNTRUSTED_RECEIPT_PATH")
    if not path.is_file():
        raise EvidenceUnverified("UNTRUSTED_RECEIPT_NOT_REGULAR")
    st=path.stat()
    if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or
        stat.S_IMODE(st.st_mode)&0o077 or not 1<=st.st_size<=maximum):
        raise EvidenceUnverified("UNTRUSTED_RECEIPT_PERMISSIONS_OR_SIZE")
    return path.read_bytes()


def trusted_root(root: Path) -> None:
    if (not root.is_absolute() or root.is_symlink() or not root.is_dir() or
        root.stat().st_uid!=0 or stat.S_IMODE(root.stat().st_mode)&0o077):
        raise EvidenceUnverified("TRUSTED_RECEIPT_DIRECTORY_REQUIRED")


def verify_finish(
    record: dict, candidate: dict, command_sha256: str,
    public_key: Ed25519PublicKey,
) -> bool:
    """Historical signature and scope only, never original312 eligibility."""
    if (not isinstance(record,dict) or
        record.get("contract")!=FINISH or
        set(record)!=set(FIELDS)|{"signature"} or
        not isinstance(record.get("signature"),str) or
        not isinstance(record.get("job_id"),str) or
        not HEX32.fullmatch(record["job_id"])):
        return False
    plan=candidate.get("plan")
    if not isinstance(plan,dict):
        return False
    for field in ("site_uuid","environment","origin"):
        if record.get(field)!=plan.get(field):
            return False
    if (record.get("command_sha256")!=command_sha256 or
        record.get("exit_code")!=0 or
        not isinstance(record.get("native_receipt_sha256"),str) or
        not SHA.fullmatch(record["native_receipt_sha256"]) or
        not all(isinstance(record.get(key),str) and
                SHA.fullmatch(record[key]) for key in (
                "expected_readback_sha256","readback_sha256","output_sha256")) or
        not (record["expected_readback_sha256"] ==
             record["readback_sha256"] == record["output_sha256"])):
        return False
    issued,expires=record.get("issued"),record.get("expires")
    if (type(issued) is not int or type(expires) is not int or
        not 0<expires-issued<=120):
        return False
    try:
        message="\n".join(str(record[key]) for key in FIELDS).encode("utf-8")
        public_key.verify(
            base64.b64decode(record["signature"],validate=True),message
        )
    except Exception:
        return False
    return True


def count_journal_matching_commands(
    journal: Path, command_digests: set[str],
) -> int | None:
    if not journal.is_file() or journal.is_symlink():
        return None
    matched=set()
    try:
        with sqlite3.connect(
            "file:"+str(journal)+"?mode=ro",uri=True,timeout=5,
        ) as db:
            rows=db.execute("SELECT detail FROM events LIMIT 50000")
            for (raw,) in rows:
                try:
                    document=json.loads(raw)
                    if isinstance(document,dict) and (
                        document.get("command_sha256") in command_digests
                    ):
                        matched.add(document["command_sha256"])
                except (TypeError,ValueError):
                    continue
    except (sqlite3.DatabaseError,sqlite3.OperationalError):
        return None
    return len(matched)


def summarize(
    installed_command_index: dict[str,dict],
    receipt_dir: Path,
    broker_public_key_b64: str,
    *,
    journal: Path | None=None,
) -> dict:
    """Only reports aggregate counts. Does not expose a Job or plan ID."""
    root=Path(receipt_dir)
    trusted_root(root)
    if not isinstance(installed_command_index,dict):
        raise EvidenceUnverified("ORIGINAL306_COMMAND_INDEX_REQUIRED")
    digests=set(installed_command_index)
    if any(not isinstance(s,str) or not SHA.fullmatch(s) for s in digests):
        raise EvidenceUnverified("ORIGINAL306_COMMAND_DIGEST_INVALID")
    try:
        key_bytes=base64.b64decode(broker_public_key_b64,validate=True)
        if len(key_bytes)!=32:
            raise ValueError("invalid")
        key=Ed25519PublicKey.from_public_bytes(key_bytes)
    except Exception as exc:
        raise EvidenceUnverified("BROKER_PUBLIC_KEY_UNTRUSTED") from exc
    files=sorted(root.glob("*.json"))
    if len(files)>MAX_RECEIPTS:
        raise EvidenceUnverified("ORIGINAL313_RECEIPT_COUNT_LIMIT")
    verified:set[str]=set()
    candidates:set[str]=set()
    invalid:set[str]=set()
    duplicate:set[str]=set()
    for path in files:
        blob=trusted_file(path,root=root)
        try:
            record=json.loads(blob)
        except (ValueError,UnicodeError) as exc:
            raise EvidenceUnverified("ORIGINAL_RECEIPT_JSON_INVALID") from exc
        if not isinstance(record,dict):
            raise EvidenceUnverified("ORIGINAL_RECEIPT_NOT_OBJECT")
        digest=record.get("command_sha256")
        if digest not in digests:
            continue
        candidates.add(digest)
        named_correctly=(
            isinstance(record.get("job_id"),str) and
            HEX32.fullmatch(record["job_id"]) is not None and
            path.name==record["job_id"]+"-dynamic313-finish.json"
        )
        if named_correctly and verify_finish(
            record,installed_command_index[digest],digest,key
        ):
            if digest in verified:
                duplicate.add(digest)
            verified.add(digest)
        else:
            invalid.add(digest)
    final=verified-invalid-duplicate
    per_operation=Counter(
        row.get("operation","<unregistered>")
        for digest,row in installed_command_index.items() if digest in final
    )
    without_per_operation=Counter(
        row.get("operation","<unregistered>")
        for digest,row in installed_command_index.items() if digest not in final
    )
    if journal is None:
        journal=DEFAULT_JOURNAL
    journal_matched=count_journal_matching_commands(journal,digests)
    return {
        "contract":"mad4b.original305312.historical-finish-correlation.v1",
        "state":"HISTORICAL_FINISH_SIGNATURES_ONLY__CURRENT_JOB_UNVERIFIED",
        "installed_command_count":len(digests),
        "commands_with_finish_candidate":len(candidates),
        "commands_with_verified_historical_signed_finish":len(final),
        "verified_historical_finishes_by_operation":dict(sorted(per_operation.items())),
        "without_verified_finish_by_operation":dict(
            sorted(without_per_operation.items())
        ),
        "commands_with_invalid_or_conflicting_evidence":len(invalid|duplicate),
        "commands_without_cryptographically_verified_finish":
            len(digests-final),
        "commands_with_matching_dispatch_journal_events":journal_matched,
        "original305_current_state_verified":False,
        "original312_current_eligibility_verified":False,
        "original313_signed_final_certificate_issued_by_audit":False,
        "legacy_associations_issued":False,
        "source_provider_enrolled":False,
        "live_site_mutated":False,
    }

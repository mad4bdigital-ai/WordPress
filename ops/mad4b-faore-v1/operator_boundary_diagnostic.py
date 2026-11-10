#!/usr/bin/env python3
"""Read-only evidence for a root Host Operator execution boundary.

Never invokes apply, reads credentials, creates grants or runs target commands.
A tool safety refusal is supplied as an observation, not inferred from the VPS.
"""
import argparse
import hashlib
import json
import re
import sqlite3
from pathlib import Path

ROOT = Path("/opt/mad4b-company-transport/native-operator-v1")
JOURNAL = Path("/opt/mad4b-company-transport/dispatch-queue-v1.sqlite3")
IDENT = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{7,89}$")
EXPECTED = ("apply.sh", "verify.sh", "rollback.sh", "backup.json")


def classify(correlation: str, *, root: Path = ROOT,
             journal: Path = JOURNAL, platform_refusal: bool = False) -> dict:
    if not IDENT.fullmatch(correlation):
        raise ValueError("CORRELATION_ID_INVALID")
    root = Path(root)
    folder = root / correlation
    if folder.is_symlink() or folder.resolve(strict=True).parent != root.resolve(strict=True):
        raise RuntimeError("CASE_PATH_UNSAFE")
    manifest_path = folder / "manifest.json"
    requirements_path = folder / "operator-requirements.json"
    if not manifest_path.is_file() or not requirements_path.is_file():
        raise RuntimeError("CASE_EVIDENCE_MISSING")
    meta = json.loads(manifest_path.read_text())
    req = json.loads(requirements_path.read_text())
    if meta.get("canary_id") != correlation or req.get("operator", {}).get("correlation_id") != correlation:
        raise RuntimeError("CASE_CORRELATION_MISMATCH")
    integrity = {}
    for filename in EXPECTED:
        file = folder / filename
        acceptable = file.is_file() and not file.is_symlink()
        if acceptable:
            content = file.read_bytes()
            stat = file.stat()
            acceptable = (stat.st_uid == 0 and not (stat.st_mode & 0o077)
                          and hashlib.sha256(content).hexdigest() == meta.get("digests", {}).get(filename))
        integrity[filename] = acceptable
    receipt = root / "receipts" / (correlation + ".json")
    receipt_present = receipt.exists()
    uri = f"file:{journal}?mode=ro"
    try:
        with sqlite3.connect(uri, uri=True, timeout=5) as db:
            count = db.execute("SELECT count(*) FROM events WHERE job_id = ?",
                               ("operator:" + correlation,)).fetchone()[0]
    except (sqlite3.Error, OSError) as exc:
        raise RuntimeError("HOST_JOURNAL_UNAVAILABLE") from exc
    if receipt_present or count:
        state = "EXECUTION_EVIDENCE_PRESENT_RECONCILE_BEFORE_ACTION"
    elif not all(integrity.values()):
        state = "INPUT_INTEGRITY_FAILED"
    elif platform_refusal:
        state = "PLATFORM_REFUSAL_RECORDED_NO_HOST_ATTEMPT"
    else:
        state = "NO_HOST_EXECUTION_EVIDENCE_CAUSE_UNDETERMINED"
    resolutions = {
        "PLATFORM_REFUSAL_RECORDED_NO_HOST_ATTEMPT":
            ("EXTERNAL_PLATFORM_SAFETY", "REVIEW_ORIGINAL_PLATFORM_REFUSAL"),
        "INPUT_INTEGRITY_FAILED":
            ("HOST_INPUT_INTEGRITY", "REBUILD_AND_REPLAN_INPUTS"),
        "EXECUTION_EVIDENCE_PRESENT_RECONCILE_BEFORE_ACTION":
            ("NATIVE_HOST_EXECUTION", "RECONCILE_RECEIPT_AND_READBACK"),
        "NO_HOST_EXECUTION_EVIDENCE_CAUSE_UNDETERMINED":
            ("UNDETERMINED", "INSPECT_ORIGINAL_DELIVERY"),
    }
    blocker_owner, resolution_code = resolutions[state]
    return {
        "contract": "mad4b.faore.operator-boundary-observation.v1",
        "blocker_owner": blocker_owner,
        "resolution_code": resolution_code,
        "automatic_retry_allowed": False,
        "alternative_execution_lane_allowed": False,
        "host_grant_change_required": False,
        "platform_refusal_provenance": (
            "CALLER_REPORTED" if platform_refusal else "NOT_REPORTED"
        ),
        "correlation_id": correlation,
        "state": state,
        "platform_refusal_reported": platform_refusal,
        "platform_policy_rule_known": False,
        "ssh_key_name": req.get("tool_arguments", {}).get("key_name"),
        "site_scope": req.get("site_scope", {}).get("company", "") + ":" +
                      req.get("site_scope", {}).get("environment", ""),
        "plan_state": req.get("status", {}).get("operator_plan"),
        "input_integrity": integrity,
        "operator_receipt_present": receipt_present,
        "host_journal_events": count,
        "wordpress_option_state": "NOT_READ_IN_THIS_DIAGNOSTIC",
        "write_execution_proved": False if not receipt_present and count == 0 else None,
        "can_change_platform_safety_decision": False,
        "performs_target_action": False,
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--correlation-id", required=True)
    parser.add_argument("--platform-refusal-reported", action="store_true")
    args = parser.parse_args()
    try:
        print(json.dumps(classify(args.correlation_id,
                                  platform_refusal=args.platform_refusal_reported),
                         sort_keys=True))
    except (OSError, RuntimeError, ValueError, TypeError, KeyError) as exc:
        print(json.dumps({"state": "DIAGNOSTIC_UNAVAILABLE",
                          "reason": str(exc)[:120],
                          "performs_target_action": False}, sort_keys=True))
        raise SystemExit(2)

#!/usr/bin/env python3
"""Read-only bridge between MAD4B Original Core and continuous Remote Resolve.

Connectivity is an observation, not a site identity, transport grant or 313.
The worker only reads existing root-owned reports and writes its own status.
"""
from __future__ import annotations
import argparse
import json
import os
import re
import stat
import tempfile
from pathlib import Path
from urllib.parse import urlsplit

CONTRACT = "mad4b.remote-resolve.live-evidence.v3"
ROOT = Path("/opt/mad4b-company-provisioning/reports")
FILES = {
    "policy": "company-unified-operation-policy-current.json",
    "identity": "company-signed-v4-enrollment-plan-current.json",
    "native": "company-native-contract-reconciliation-current.json",
    "crossssh": "company-crossssh-wpcli-readiness-current.json",
    "precert": "native-external-readonly-precert-current.json",
}
OUTPUT = ROOT / "company-remote-resolve-live-current.json"
NAME = re.compile(r"^[a-z][a-z0-9-]{2,47}$")
UUID = re.compile(r"^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$", re.I)

class InvalidEvidence(ValueError):
    pass

def read_protected(path, required=True):
    try:
        s = path.lstat()
    except FileNotFoundError:
        if not required:
            return {}
        raise InvalidEvidence("ORIGINAL_REPORT_MISSING")
    if not stat.S_ISREG(s.st_mode) or s.st_uid != 0 or s.st_mode & 0o077 or s.st_size > 2_000_000:
        raise InvalidEvidence("ORIGINAL_REPORT_NOT_TRUSTED")
    try:
        obj = json.loads(path.read_text())
    except (ValueError, UnicodeError):
        raise InvalidEvidence("ORIGINAL_REPORT_NOT_JSON")
    if not isinstance(obj, dict):
        raise InvalidEvidence("ORIGINAL_REPORT_NOT_OBJECT")
    return obj

def _origin(value):
    if not isinstance(value, str):
        return False
    try:
        p = urlsplit(value)
        port = p.port
    except ValueError:
        return False
    return (p.scheme == "https" and bool(p.hostname) and port is None
            and not p.username and not p.password and not p.path
            and not p.query and not p.fragment and value == "https://" + p.hostname)

def target_key(row):
    if not isinstance(row, dict) or not isinstance(row.get("target"), str):
        raise InvalidEvidence("ORIGINAL_TARGET_INVALID")
    parts = row["target"].split(":")
    if len(parts) != 2 or not NAME.fullmatch(parts[0]) or parts[1] not in ("staging", "production"):
        raise InvalidEvidence("ORIGINAL_TARGET_SCOPE_INVALID")
    if not _origin(row.get("canonical_origin")):
        raise InvalidEvidence("ORIGINAL_CANONICAL_ORIGIN_INVALID")
    uid = row.get("site_uuid")
    if uid is not None and (not isinstance(uid, str) or not UUID.fullmatch(uid)):
        raise InvalidEvidence("ORIGINAL_SITE_UUID_INVALID")
    return parts

def _matches(rows, target):
    return [r for r in rows if isinstance(r, dict) and
            (r.get("target") == target or
             (r.get("company") + ":" + r.get("environment") == target
              if isinstance(r.get("company"), str) and isinstance(r.get("environment"), str)
              else False))]

def _channel(row, evidence):
    if not isinstance(evidence, dict) or evidence.get("origin") != row["canonical_origin"]:
        return False
    if evidence.get("target") != row["target"] and (
            evidence.get("company") + ":" + evidence.get("environment") != row["target"]
            if isinstance(evidence.get("company"), str) and isinstance(evidence.get("environment"), str)
            else True):
        return False
    if row.get("site_uuid") is not None and evidence.get("site_uuid") != row["site_uuid"]:
        return False
    return evidence.get("remote_wpcli_readback_verified") is True or evidence.get("read_only_ssh_execution_verified") is True

def inspect_target(row, reports):
    company, env = target_key(row)
    name = row["target"]
    native = [r for r in reports.get("native", {}).get("standing_execution_enrollment", [])
              if isinstance(r, dict) and r.get("company_code") == company and r.get("environment") == env]
    observations = []
    for source in ("crossssh", "precert"):
        entries = reports.get(source, {}).get("targets", [])
        if isinstance(entries, list):
            observations += _matches(entries, name)
    observations = [v for v in observations if _channel(row, v)]
    if len(native) > 1:
        state, action, retry = "REVIEW_REQUIRED", "RESOLVE_AMBIGUOUS_HOST_PROOF", False
    elif row.get("site_uuid") is None:
        state, action, retry = "RECOVERY_ACTIVE", "DISCOVER_SITE_PROFILE_VIA_REGISTERED_CROSS_SSH", True
    elif row.get("identity_registered_by_original_305") is not True:
        state, action, retry = "RECOVERY_ACTIVE", "RECONCILE_ORIGINAL_EXACT_SITE_IDENTITY", True
    elif native and native[0].get("host_proof_receipt_state") == "ORIGINAL_SIGNED_HOST_PROOF_FRESH":
        state, action, retry = "RECOVERY_ACTIVE", "REQUEST_ORIGINAL_306_312_SIGNED_READ_CANARY", True
    else:
        state, action, retry = "RECOVERY_ACTIVE", "RENEW_ORIGINAL_HOST_PROOF_AND_305", True
    if name == "tours-central:staging":
        action = "RECONCILE_CENTRAL_DEMO_ORIGINAL_305_SEPARATELY"
    return {
        "target": name, "environment": env, "canonical_origin": row["canonical_origin"],
        "site_uuid": row.get("site_uuid"),
        "remote_wpcli_readback_observed": bool(observations),
        "remote_wpcli_is_not_grant": True,
        "original_site_registered": row.get("identity_registered_by_original_305") is True,
        "state": state, "next_action": action, "retry_automatically": retry,
        "grants_created": False, "jobs_dispatched": False, "original_313_certified": False,
        "production_mutated": False
    }

def project(reports):
    original = reports.get("policy")
    if not isinstance(original, dict) or original.get("contract") != "mad4b.unified-operation-policy-projection.v1":
        raise InvalidEvidence("ORIGINAL_POLICY_CONTRACT_INVALID")
    rows = original.get("targets")
    if not isinstance(rows, list) or original.get("target_count") != len(rows):
        raise InvalidEvidence("ORIGINAL_TARGET_COUNT_INVALID")
    if original.get("grants_created") is not False or original.get("production_mutated") is not False:
        raise InvalidEvidence("ORIGINAL_POLICY_NOT_READONLY")
    results, seen = [], set()
    for row in rows:
        key = row.get("target") if isinstance(row, dict) else None
        if key in seen:
            raise InvalidEvidence("DUPLICATE_ORIGINAL_TARGET")
        seen.add(key)
        results.append(inspect_target(row, reports))
    return {
        "contract": CONTRACT, "target_count": len(results), "targets": results,
        "controller_issued_grants": False, "controller_dispatched_jobs": False,
        "controller_mutated_production": False
    }

def atomic_write(report, output=OUTPUT):
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.exists() or output.is_symlink():
        s = output.lstat()
        if not stat.S_ISREG(s.st_mode) or s.st_uid != 0 or stat.S_IMODE(s.st_mode) != 0o600:
            raise InvalidEvidence("OUTPUT_FILE_NOT_TRUSTED")
    fd, tmp = tempfile.mkstemp(dir=output.parent, prefix=".remote-resolve-")
    try:
        with os.fdopen(fd, "w") as f:
            os.fchmod(f.fileno(), 0o600)
            json.dump(report, f, sort_keys=True)
            f.write("\n")
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, output)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("status", "once"))
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise InvalidEvidence("ROOT_ORIGINAL_EVIDENCE_REQUIRED")
    reports = {k: read_protected(ROOT / path, required=k in ("policy", "identity", "native"))
               for k, path in FILES.items()}
    result = project(reports)
    if args.action == "once":
        atomic_write(result)
    print(json.dumps(result, sort_keys=True))

if __name__ == "__main__":
    try:
        main()
    except (InvalidEvidence, OSError, TypeError) as e:
        print("REMOTE_RESOLVE_READ_ONLY_FAIL:" + str(e)[:120], file=__import__("sys").stderr)
        raise SystemExit(2)

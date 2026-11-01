#!/usr/bin/env python3
"""MAD4B Issue 724 — exact-site signed remote 305 request adapter.

A signed broker request authenticates the transport principal, NOT a company's
manager or owner. Enrollment still passes the ORIGINAL WordPress manager issuer,
the signed native enrollment CLI, and independent post-enrollment readback.
No shell execution, arbitrary commands, SQL, production writes or user spoofing.
"""
from __future__ import annotations
import base64
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

CONTRACT = "mad4b.remote-host-305-request.v1"
RESPONSE = "mad4b.remote-host-305-readback.v1"
HOST = "srv2030908"
PUBLIC_KEY = Path("/opt/mad4b-company-transport/broker-v1/host-ed25519-public.base64")
ENROLLER = Path("/opt/mad4b-company-transport/broker-v1/native-company-wpcli-enroll.py")
BRIDGE = Path("/opt/mad4b-company-provisioning/source-candidates/full-host-authority-pr723")
REPLAY_LEDGER = Path("/opt/mad4b-company-provisioning/source-candidates/issue724-remote-authority/replay-ledger.json")
ACTIONS = {"preflight", "reconcile305", "enroll305"}
FIELDS = ("contract", "action", "host_id", "company", "environment",
          "site_uuid", "canonical_origin", "issued", "expires", "nonce")
COMPANY = re.compile(r"[a-z][a-z0-9-]{2,47}\Z")
UUID = re.compile(r"[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\Z")
NONCE = re.compile(r"[a-f0-9]{32,64}\Z")
ORIGIN = re.compile(r"https://[a-z0-9.-]+(?::[0-9]{2,5})?\Z")


class Denied(ValueError):
    pass


def root_file(path: Path, limit: int = 65536) -> bytes:
    st = path.lstat()
    if not path.is_file() or path.is_symlink() or st.st_uid != 0 or st.st_mode & 0o077 or st.st_size > limit:
        raise Denied("ROOT_PROTECTED_SOURCE_REQUIRED")
    return path.read_bytes()


def wire_bytes(row: dict) -> bytes:
    return json.dumps({k: row[k] for k in FIELDS}, sort_keys=True,
                      separators=(",", ":"), ensure_ascii=True).encode("ascii")


def check_request(row: dict, public_raw: bytes, now: int | None = None) -> dict:
    if not isinstance(row, dict) or set(row) != set(FIELDS) | {"signature"}:
        raise Denied("REQUEST_CONTRACT_INVALID")
    if row["contract"] != CONTRACT or row["action"] not in ACTIONS or row["host_id"] != HOST:
        raise Denied("REQUEST_HOST_OR_ACTION_INVALID")
    if not isinstance(row["company"], str) or not COMPANY.fullmatch(row["company"]):
        raise Denied("COMPANY_INVALID")
    if row["environment"] != "staging":
        raise Denied("PRODUCTION_ENROLLMENT_NOT_ALLOWED")
    if not isinstance(row["site_uuid"], str) or not UUID.fullmatch(row["site_uuid"]):
        raise Denied("EXACT_SITE_UUID_REQUIRED")
    if not isinstance(row["canonical_origin"], str) or not ORIGIN.fullmatch(row["canonical_origin"]):
        raise Denied("CANONICAL_ORIGIN_INVALID")
    if type(row["issued"]) is not int or type(row["expires"]) is not int:
        raise Denied("TIME_CONTRACT_INVALID")
    current = int(time.time()) if now is None else now
    if not current - 10 <= row["issued"] <= current + 10 or not current < row["expires"] <= row["issued"] + 60:
        raise Denied("REQUEST_EXPIRED_OR_NOT_YET_VALID")
    if not isinstance(row["nonce"], str) or not NONCE.fullmatch(row["nonce"]):
        raise Denied("NONCE_INVALID")
    try:
        signature = base64.b64decode(row["signature"], validate=True)
        if len(signature) != 64 or len(public_raw) != 32:
            raise ValueError("invalid length")
        Ed25519PublicKey.from_public_bytes(public_raw).verify(signature, wire_bytes(row))
    except (ValueError, TypeError) as exc:
        raise Denied("BROKER_SIGNATURE_INVALID") from exc
    except Exception as exc:
        raise Denied("BROKER_SIGNATURE_INVALID") from exc
    return {k: row[k] for k in FIELDS}


def exact_source(row: dict, report: dict) -> dict:
    target = row["company"] + ":" + row["environment"]
    if report.get("contract") != "mad4b.full-host-authority-live-reconciliation.v1" or \
       report.get("grants_created") is not False or \
       report.get("operations_executed") is not False or report.get("production_mutated") is not False:
        raise Denied("ORIGINAL_CORE_RECONCILIATION_UNTRUSTED")
    matches = [t for t in report.get("targets", []) if isinstance(t, dict) and t.get("target") == target]
    if len(matches) != 1:
        raise Denied("TARGET_NOT_UNIQUELY_REGISTERED")
    item = matches[0]
    if item.get("site_uuid") != row["site_uuid"] or item.get("canonical_origin") != row["canonical_origin"]:
        raise Denied("SIGNED_REQUEST_SITE_BINDING_MISMATCH")
    if item.get("site_identity_registered") is not True:
        raise Denied("ORIGINAL_SITE_IDENTITY_NOT_REGISTERED")
    return item


def original_native(action: str, company: str, environment: str, *, timeout=55) -> tuple[bool, str, dict]:
    if action not in ("plan", "enroll", "status") or environment != "staging":
        raise Denied("ORIGINAL_ENROLLMENT_ACTION_INVALID")
    if action == "status":
        command = ["docker", "exec", "-u", "33:33", "etg-commerce-staging-php",
                   "php", "-d", "memory_limit=512M", "/usr/local/bin/wp",
                   "mad4b", "company-native-wpcli", "status",
                   "--company=" + company, "--environment=" + environment,
                   "--path=/var/www/html", "--skip-themes"]
    else:
        st = ENROLLER.lstat()
        if not ENROLLER.is_file() or ENROLLER.is_symlink() or st.st_uid != 0 or st.st_mode & 0o022:
            raise Denied("ORIGINAL_305_SCRIPT_UNTRUSTED")
        command = [sys.executable, str(ENROLLER), action, "--company", company,
                   "--environment", environment]
    result = subprocess.run(command, capture_output=True, text=True,
                            timeout=timeout, check=False)
    if result.returncode:
        m = re.search(r"ORIGINAL_COMPANY_NATIVE_ENROLLMENT_DENIED:([A-Z0-9_]+)", result.stderr)
        return False, m.group(1) if m else "ORIGINAL_305_READBACK_FAILED", {}
    rows = []
    for line in result.stdout.splitlines():
        try:
            value = json.loads(line)
            if isinstance(value, dict):
                rows.append(value)
        except ValueError:
            pass
    if len(rows) != 1:
        return False, "ORIGINAL_305_RECEIPT_INVALID", {}
    record = rows[0]
    if action == "status":
        if record.get("state") == "DENIED" and record.get("reason") == "NOT_ENROLLED":
            return False, "NOT_ENROLLED", record
        if record.get("state") != "ORIGINAL_305_PERSISTENT_PAIR_ENROLLED":
            return False, str(record.get("reason", "ORIGINAL_305_STATUS_INVALID")), record
    return True, str(record.get("state", "UNKNOWN")), record


def reserve_nonce(nonce: str, now: int, path: Path = REPLAY_LEDGER) -> None:
    # Durable single-use action ledger. A request with uncertain outcome cannot
    # be retried under the same nonce; operator must reconcile 305 readback.
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd = os.open(str(path), os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "r+") as f:
        fcntl.flock(f, fcntl.LOCK_EX)
        st = os.fstat(f.fileno())
        if st.st_uid != 0 or st.st_mode & 0o077:
            raise Denied("REPLAY_LEDGER_NOT_ROOT_PROTECTED")
        try:
            saved = f.read()
            found = json.loads(saved) if saved else {}
            if not isinstance(found, dict):
                raise ValueError("bad ledger")
        except json.JSONDecodeError as exc:
            raise Denied("REPLAY_LEDGER_CORRUPT") from exc
        except ValueError as exc:
            raise Denied("REPLAY_LEDGER_CORRUPT") from exc
        key = hashlib.sha256(nonce.encode("ascii")).hexdigest()
        if key in found:
            raise Denied("SIGNED_REQUEST_REPLAY")
        found = {k: v for k, v in found.items() if type(v) is int and v > now - 86400}
        found[key] = now
        f.seek(0)
        json.dump(found, f, separators=(",", ":"))
        f.truncate()
        f.flush()
        os.fsync(f.fileno())


def _original_status(native, row: dict) -> tuple[bool, str, dict]:
    ok, state, details = native("status", row["company"], "staging")
    if not isinstance(details, dict):
        raise Denied("ORIGINAL_305_STATUS_INVALID")
    if ok:
        if state != "ORIGINAL_305_PERSISTENT_PAIR_ENROLLED":
            raise Denied("ORIGINAL_305_STATUS_INVALID")
        if (details.get("contract") != "mad4b.company-native-wpcli-enrollment.v1"
            or details.get("company") != row["company"]
            or details.get("environment") != "staging"
            or details.get("site_uuid") != row["site_uuid"]):
            raise Denied("ORIGINAL_305_STATUS_SCOPE_MISMATCH")
    elif state != "NOT_ENROLLED":
        raise Denied("ORIGINAL_305_STATUS_UNCERTAIN")
    return ok, state, details


def _record_registered(result: dict, status: dict, newly_issued: bool) -> dict:
    active = (status.get("host_authority_verified") is True
              and status.get("full_wpcli_active") is True)
    result.update({
        "state": ("GRANT_ISSUED_CONFIRMED" if newly_issued
                  else "GRANT_EXISTING_CONFIRMED"),
        "grant_issued": newly_issued,
        "grant_registered": True,
        "grant_active": active,
        "blockers": ([] if active else [
            "INDEPENDENT_EXECUTOR_CERTIFICATION_AND_312_313_REQUIRED"]),
    })
    return result


def process(request: dict, public_raw: bytes, report: dict,
            native=original_native, now: int | None = None,
            ledger: Path = REPLAY_LEDGER, refresh=None) -> dict:
    row = check_request(request, public_raw, now)
    item = exact_source(row, report)
    result = {
        "contract": RESPONSE, "target": row["company"] + ":staging",
        "action": row["action"], "site_bound": True,
        "original_manager_authority_inferred_from_ssh": False,
        "grant_issued": False, "grant_registered": False,
        "grant_active": False, "execution_performed": False,
        "production_mutated": False,
        "state": "BLOCKED", "blockers": [],
    }
    if item.get("verified_host_scope_id") != HOST:
        result["blockers"].append("EXACT_HOST_SCOPE_AND_BINDING_PENDING")
    elif item.get("full_host_scope_observed") is not True:
        result["blockers"].append("FULL_SHELL_SQL_AND_HOST_SCOPE_PENDING")
    if result["blockers"]:
        return result

    # Presence of Original 305 grant != active native executor (projection).
    try:
        registered, status, existing = _original_status(native, row)
    except Exception:
        result["state"] = "ORIGINAL_305_STATUS_UNCERTAIN"
        result["blockers"] = ["INDEPENDENT_305_RECONCILIATION_REQUIRED"]
        return result
    if registered:
        return _record_registered(result, existing, newly_issued=False)
    ok, state, plan = native("plan", row["company"], "staging")
    if not ok or state != "ORIGINAL_NATIVE_SIGNED_ENROLLMENT_ADMISSIBLE":
        result["blockers"].append(state if not ok else "ORIGINAL_MANAGER_PLAN_NOT_ADMISSIBLE")
        return result
    if row["action"] == "preflight":
        result["state"] = "GRANT_ISSUANCE_ADMISSIBLE"
        result["blockers"].append("SIGNED_RECONCILE305_REQUEST_REQUIRED")
        return result
    if refresh is None:
        raise Denied("INDEPENDENT_READBACK_REQUIRED")
    if row["expires"] <= int(time.time()):
        raise Denied("REQUEST_EXPIRED_BEFORE_ENROLLMENT")
    reserve_nonce(row["nonce"], int(time.time()), ledger)
    try:
        issued, receipt_state, receipt = native("enroll", row["company"], "staging")
    except Exception:
        result["state"] = "ORIGINAL_305_OUTCOME_UNCERTAIN"
        result["blockers"] = ["INDEPENDENT_305_RECONCILIATION_REQUIRED"]
        return result
    if (not issued or receipt_state != "ORIGINAL_305_PERSISTENT_PAIR_ENROLLED"
        or receipt.get("grant_issued") is not True):
        result["state"] = "ORIGINAL_305_OUTCOME_UNCERTAIN"
        result["blockers"] = ["INDEPENDENT_305_RECONCILIATION_REQUIRED"]
        return result
    try:
        after = exact_source(row, refresh())
        if after.get("site_identity_registered") is not True:
            raise Denied("ORIGINAL_SITE_IDENTITY_NOT_REGISTERED")
        verified, code, status_row = _original_status(native, row)
        if not verified:
            raise Denied("ORIGINAL_305_NEW_PAIR_NOT_VERIFIED")
    except Exception:
        result["state"] = "ORIGINAL_305_OUTCOME_UNCERTAIN"
        result["blockers"] = ["INDEPENDENT_305_RECONCILIATION_REQUIRED"]
        return result
    return _record_registered(result, status_row, newly_issued=True)


def live_report() -> dict:
    sys.path.insert(0, str(BRIDGE))
    from live_full_host_authority import live_once
    return live_once()


def main() -> None:
    if os.geteuid() != 0:
        raise Denied("REGISTERED_ROOT_BROKER_REQUIRED")
    raw = sys.stdin.buffer.read(16385)
    if len(raw) > 16384:
        raise Denied("REQUEST_TOO_LARGE")
    received_at = int(time.time())
    request = json.loads(raw)
    public_raw = base64.b64decode(root_file(PUBLIC_KEY, 1024).strip(), validate=True)
    # Validate on ingress, before the possibly slow Original Core refresh.
    check_request(request, public_raw, received_at)
    report = live_report()
    response = process(request, public_raw, report, now=received_at, refresh=live_report)
    print(json.dumps(response, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (OSError, Denied, ValueError, TypeError, subprocess.SubprocessError) as exc:
        print(json.dumps({"contract": RESPONSE, "state": "DENIED",
                          "reason": str(exc)[:130], "grant_issued": False,
                          "execution_performed": False, "production_mutated": False}),
              file=sys.stderr)
        raise SystemExit(2)


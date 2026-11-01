#!/usr/bin/env python3
"""Read-only forensic gate for an EXPIRED 306 Job with a direct Native Operator receipt.

This does NOT extend a grant, make a 312 preflight eligible, execute Shell, or
write/forge a central 314 completion. Only a separately authorized, original,
cryptographically verified reconciliation path could close that gap.
"""
import argparse
import datetime
import hashlib
import json
import pathlib
import re

HOST = "srv2030908"
COMPANY = "tours-central"
ORIGIN = "https://tours.dev.mad4b.com"
CONTRACT = "mad4b.expired-native-evidence-gate.v1"
HEX = re.compile(r"^[0-9a-f]{64}$")


def epoch(iso):
    if not isinstance(iso, str):
        raise ValueError("NATIVE_TIMESTAMP_INVALID")
    when = datetime.datetime.fromisoformat(iso.replace("Z", "+00:00"))
    if when.tzinfo is None:
        raise ValueError("NATIVE_TIMESTAMP_MUST_BE_UTC_AWARE")
    return when.timestamp()


def inspect(job, native, correlation, now):
    base = {
        "contract": CONTRACT, "state": "RECONCILIATION_NOT_PROVEN",
        "can_execute": False, "central_completion_recorded": False,
        "can_extend_job": False, "may_claim_314_verified": False,
        "requires_signed_original_broker_receipt": True,
    }
    if not isinstance(job, dict) or not isinstance(native, dict):
        return dict(base, reason="ORIGINAL_EVIDENCE_MISSING")
    if not isinstance(now, (int, float)) or isinstance(now, bool):
        return dict(base, reason="CLOCK_INVALID")
    job_id = job.get("job_id")
    digest = job.get("command_sha256")
    exp = job.get("expires_epoch")
    if (not isinstance(job_id, str) or not re.fullmatch(r"[0-9a-f]{32}", job_id)
        or not isinstance(digest, str) or not HEX.fullmatch(digest)
        or type(exp) is not int or exp >= now):
        return dict(base, reason="NOT_AN_EXPIRED_EXACT_JOB")
    for field, expected in (
        ("company", COMPANY), ("environment", "staging"),
        ("canonical_origin", ORIGIN), ("principal_type", "vps_native"),
        ("principal_id", "vps-native:srv2030908"),
        ("target_host_id", HOST), ("target_scope", "host"),
        ("operation", "maintenance.shell"),
        ("state", "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"),
    ):
        if job.get(field) != expected:
            return dict(base, reason="ORIGINAL_JOB_SCOPE_MISMATCH_" + field)
    if job.get("executed") is not False:
        return dict(base, reason="JOB_ALREADY_CHANGED")
    if (not isinstance(correlation, str) or correlation != job.get("correlation_id")
        or not re.fullmatch("[A-Za-z0-9_.-]{8,90}", correlation)):
        return dict(base, reason="CORRELATION_NOT_BOUND")
    for field, expected in (
        ("contract", "mad4b.native-vps-operator.v1"),
        ("host_id", HOST), ("company", COMPANY),
        ("environment", "staging"), ("lane", "vps_ssh"),
        ("kind", "shell"), ("intent", "diagnostic"),
        ("state", "VERIFIED"),
        ("command_sha256", digest),
    ):
        if native.get(field) != expected:
            return dict(base, reason="NATIVE_SCOPE_MISMATCH_" + field)
    if (native.get("execution_performed") is not True
        or native.get("readback_matches_expected") is not True
        or native.get("exit_code") != 0 or native.get("readback_exit_code") != 0
        or native.get("mutating") is not False
        or native.get("mutation_performed") is not False
        or not isinstance(native.get("readback_sha256"), str)
        or not HEX.fullmatch(native["readback_sha256"])
        or native.get("readback_sha256") != native.get("expected_readback_sha256")):
        return dict(base, reason="NATIVE_EXECUTION_OR_READBACK_INVALID")
    try:
        started, finished = epoch(native.get("started_at")), epoch(native.get("finished_at"))
    except (ValueError, TypeError, OverflowError):
        return dict(base, reason="NATIVE_TIMESTAMPS_INVALID")
    if (finished < started or started > exp or finished > exp):
        return dict(base, reason="EXECUTION_NOT_PROVEN_BEFORE_EXPIRY")
    # A root-owned direct-operator receipt is supporting evidence, not a
    # cryptographically signed 314 completion. Never upgrade it to VERIFIED.
    return dict(base, state="EVIDENCE_TIME_AND_SCOPE_CONSISTENT",
                reason="ORIGINAL_SIGNED_314_RECONCILIATION_STILL_REQUIRED",
                job_id=job_id, correlation_id=correlation,
                command_sha256=digest, native_readback_sha256=native["readback_sha256"],
                execution_before_expiry=True)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--job-json", type=pathlib.Path, required=True)
    p.add_argument("--native-json", type=pathlib.Path, required=True)
    p.add_argument("--correlation", required=True)
    p.add_argument("--now-epoch", type=int, required=True)
    args = p.parse_args()
    # No network, WordPress calls, Shell, or mutations. Redact raw evidence.
    job = json.loads(args.job_json.read_text())
    native = json.loads(args.native_json.read_text())
    print(json.dumps(inspect(job, native, args.correlation, args.now_epoch),
                     sort_keys=True))


if __name__ == "__main__":
    main()

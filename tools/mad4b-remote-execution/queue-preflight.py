#!/usr/bin/env python3
"""Bounded read-only preflight for an enrolled Staging Host Bridge queue.

This is not a command executor, authority issuer, or acceptance receipt.
Before installing a consumer, validate existing submissions without changing
queued/running/receipts. No network access or external dependencies.
"""
import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

JOB_ID = re.compile(r"^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")
FIXTURE = re.compile(r"^[A-Za-z0-9_-]{1,60}$")
EXCLUDED = {
    "plan_sha256", "repair_plan_sha256", "submission_sha256",
    "authorizing", "mutation_performed", "queued", "replayed",
}
OPERATIONS = {"health", "wordpress_version", "active_plugin_inventory", "php_lint"}
PROFILE_ID = re.compile(r"^[a-z][a-z0-9-]{2,63}$")
MAX_BYTES = 131072

def digest(record):
    payload = {k: v for k, v in record.items() if k not in EXCLUDED}
    data = json.dumps(payload, sort_keys=True, separators=(",", ":"),
                      ensure_ascii=False, allow_nan=False).encode("utf-8")
    return hashlib.sha256(data).hexdigest()

def check_submission(item, fingerprint, filename, runner_profile_id):
    if not isinstance(runner_profile_id, str) or not PROFILE_ID.fullmatch(runner_profile_id):
        return "runner_profile_unconfigured"
    if not isinstance(item, dict) or item.get("contract") != "mad4b.host-bridge-submission.v1":
        return "invalid_submission_contract"
    id_ = item.get("job_id")
    if not isinstance(id_, str) or not JOB_ID.fullmatch(id_) or filename != id_ + ".json":
        return "invalid_job_identity"
    plan = item.get("plan")
    if not isinstance(plan, dict) or plan.get("contract") != "mad4b.host-operation-plan.v1":
        return "invalid_plan_contract"
    if item.get("plan_sha256") != plan.get("plan_sha256") or digest(plan) != plan.get("plan_sha256"):
        return "plan_digest_mismatch"
    submitted_digest = item.get("submission_sha256")
    if not isinstance(submitted_digest, str) or not SHA256.fullmatch(submitted_digest) or digest(item) != submitted_digest:
        return "submission_digest_mismatch"
    target = plan.get("target")
    if not isinstance(target, dict) or target.get("environment") != "staging":
        return "staging_binding_missing"
    if not isinstance(fingerprint, str) or not SHA256.fullmatch(fingerprint) or target.get("target_fingerprint") != fingerprint:
        return "target_fingerprint_mismatch"
    if plan.get("runner_profile_id") != runner_profile_id:
        return "runner_profile_mismatch"
    if plan.get("operation_id") != "runtime.status.read" or plan.get("operation_version") != 1:
        return "operation_not_allowlisted"
    if plan.get("risk") != "read_only" or plan.get("approval_required") is not False:
        return "write_risk_denied"
    if any(plan.get(k) != "host_runner" for k in ("execution_location", "commit_location")):
        return "execution_location_invalid"
    if plan.get("production_authorized") is not False or item.get("production_authorized") is not False:
        return "production_denied"
    if any((item.get("authority") or {}).values()) or item.get("approval_ref") not in (None, ""):
        return "unexpected_mutation_authority"
    args = plan.get("arguments")
    if not isinstance(args, dict) or args.get("diagnostic") not in OPERATIONS:
        return "diagnostic_not_allowlisted"
    if args["diagnostic"] == "php_lint":
        if set(args) != {"diagnostic", "fixture"} or not isinstance(args["fixture"], str) or not FIXTURE.fullmatch(args["fixture"]):
            return "unsafe_fixture"
    elif set(args) != {"diagnostic"}:
        return "unexpected_arguments"
    return "verified_read_only_eligible"

def inspect(directory, fingerprint, runner_profile_id):
    jobs = []
    if directory.is_symlink() or not directory.is_dir():
        raise ValueError("queue_path_invalid")
    for f in sorted(directory.glob("*.json")):
        try:
            if f.is_symlink() or not f.is_file() or f.stat().st_size > MAX_BYTES:
                raise ValueError("invalid_file_type_or_size")
            item = json.loads(f.read_text(encoding="utf-8"))
            result = check_submission(item, fingerprint, f.name, runner_profile_id)
        except (OSError, ValueError, TypeError, KeyError) as e:
            result = "invalid_queue_entry:" + type(e).__name__
        jobs.append({"filename": f.name, "state": result})
    return jobs

def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--queued-dir", type=Path, required=True)
    ap.add_argument("--expected-target-fingerprint", required=True)
    ap.add_argument("--runner-profile-id", required=True)
    args = ap.parse_args(argv)
    jobs = inspect(args.queued_dir, args.expected_target_fingerprint, args.runner_profile_id)
    ok = all(row["state"] == "verified_read_only_eligible" for row in jobs)
    print(json.dumps({
        "contract": "mad4b.host-queue-preflight.v1",
        "readonly": True, "mutation_performed": False,
        "shell_enabled": False, "sql_enabled": False,
        "all_verified": ok, "job_count": len(jobs), "jobs": jobs
    }, separators=(",", ":")))
    return 0 if ok else 2

if __name__ == "__main__":
    sys.exit(main())

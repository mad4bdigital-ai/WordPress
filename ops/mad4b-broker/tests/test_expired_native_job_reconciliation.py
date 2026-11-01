#!/usr/bin/env python3
"""Hermetic denial tests for post-expiry direct-native evidence."""
import datetime
import importlib.util
from pathlib import Path

source = Path(__file__).resolve().parents[1] / "expired-native-job-reconciliation.py"
spec = importlib.util.spec_from_file_location("native_evidence", source)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

date = lambda n: datetime.datetime.fromtimestamp(n, datetime.timezone.utc).isoformat()
digest = "a" * 64
job = {
    "job_id": "b" * 32, "company": "tours-central",
    "canonical_origin": "https://tours.dev.mad4b.com",
    "environment": "staging", "principal_type": "vps_native",
    "principal_id": "vps-native:srv2030908",
    "target_host_id": "srv2030908", "target_scope": "host",
    "operation": "maintenance.shell", "command_sha256": digest,
    "correlation_id": "signed-canary-20261101", "expires_epoch": 2000000100,
    "state": "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
    "executed": False,
}
native = {
    "contract": "mad4b.native-vps-operator.v1",
    "host_id": "srv2030908", "company": "tours-central",
    "environment": "staging", "lane": "vps_ssh",
    "kind": "shell", "intent": "diagnostic",
    "state": "VERIFIED", "command_sha256": digest,
    "execution_performed": True, "readback_matches_expected": True,
    "exit_code": 0, "readback_exit_code": 0,
    "mutating": False, "mutation_performed": False,
    "readback_sha256": "c" * 64, "expected_readback_sha256": "c" * 64,
    "started_at": date(2000000000), "finished_at": date(2000000002),
}
corr = job["correlation_id"]
def check(j=job, n=native, correlation=corr, now=2000000120):
    result = module.inspect(j, n, correlation, now)
    assert result["can_execute"] is False
    assert result["central_completion_recorded"] is False
    assert result["can_extend_job"] is False
    assert result["may_claim_314_verified"] is False
    return result

good = check()
assert good["state"] == "EVIDENCE_TIME_AND_SCOPE_CONSISTENT"
assert good["reason"] == "ORIGINAL_SIGNED_314_RECONCILIATION_STILL_REQUIRED"
assert good["execution_before_expiry"] is True

for key, value in [
    ("principal_type", "wordpress_owner"),
    ("principal_id", "1"),
    ("environment", "production"),
    ("canonical_origin", "https://other.example"),
    ("state", "GENERAL_SHELL_EXECUTION_READBACK_VERIFIED"),
    ("command_sha256", "d" * 64),
]:
    altered = dict(job, **{key: value})
    assert check(j=altered)["state"] == "RECONCILIATION_NOT_PROVEN", key

for key, value in [
    ("state", "FAILED_EXECUTION_OR_READBACK"),
    ("readback_sha256", "d" * 64),
    ("exit_code", 1),
    ("mutating", True),
    ("finished_at", date(2000000101)),
    ("started_at", "not-a-date"),
    ("command_sha256", "d" * 64),
]:
    altered = dict(native, **{key: value})
    assert check(n=altered)["state"] == "RECONCILIATION_NOT_PROVEN", key

assert check(correlation="wrong-correlation")["state"] == "RECONCILIATION_NOT_PROVEN"
assert check(now=2000000090)["reason"] == "NOT_AN_EXPIRED_EXACT_JOB"
print("PASS: 17+ exact native reconciliation cases; no job mutation or 314 completion")

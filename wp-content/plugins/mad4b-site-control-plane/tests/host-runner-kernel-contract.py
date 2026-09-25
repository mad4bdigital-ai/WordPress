#!/usr/bin/env python3
"""Executable contract for the minimal read-only Host Runner kernel."""

from __future__ import annotations

import importlib.util
import json
import tempfile
import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


runner = load("mad4b_host_runner", ROOT / "tools/mad4b_host_runner.py")
SOURCE = (ROOT / "tools/mad4b_host_runner.py").read_text(encoding="utf-8")

if runner.SUPPORTED_ENVIRONMENTS != {"staging"}:
    raise SystemExit("Host Runner repository slice must remain Staging-only")
if any(x in SOURCE for x in ("subprocess.", "os.system(", "shell=True", "bash -c", "sh -c", "powershell", "curl_exec")):
    raise SystemExit("Host Runner kernel exposes a forbidden command/network execution primitive")
if set(runner.OPERATIONS) != {
    "runtime.status.read",
    "filesystem.hash.read",
    "package.integrity.verify",
}:
    raise SystemExit("Host Runner kernel operation registry widened unexpectedly")
if any(row.get("risk") != "read_only" for row in runner.OPERATIONS.values()):
    raise SystemExit("Host Runner kernel contains non-read operation")


def iso(dt):
    return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def make_job(profile, operation_id, inputs, *, job_id=None, created=None, expires=None):
    now = datetime.now(timezone.utc)
    job = {
        "contract": runner.JOB_CONTRACT,
        "job_id": job_id or str(uuid.uuid4()),
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "operation_id": operation_id,
        "operation_version": runner.OPERATIONS[operation_id]["version"],
        "operation_fingerprint": runner.operation_fingerprint(operation_id),
        "created_at": iso(created or (now - timedelta(seconds=1))),
        "expires_at": iso(expires or (now + timedelta(minutes=5))),
        "input": inputs,
        "input_sha256": runner.sha256_bytes(runner.canonical_json(inputs)),
        "idempotency_key": "idem-" + (job_id or "new-" + str(uuid.uuid4())),
        "actor_ref": "ci:operator",
        "authority_ref": "ci:read-authority",
        "submission_location": "contract_test",
    }
    job["mac_sha256"] = runner.job_mac(job, profile["_integrity_key"])
    return job


with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    wp = tmp / "wordpress"
    plugin = wp / "wp-content" / "plugins" / "mad4b-site-control-plane"
    plugin.mkdir(parents=True)
    (wp / "wp-config.php").write_text("<?php // runner fixture\n", encoding="utf-8")
    (plugin / "mad4b-site-control-plane.php").write_text("<?php // runner plugin\n", encoding="utf-8")
    (plugin / "includes").mkdir()
    (plugin / "includes" / "health.php").write_text("<?php return true;\n", encoding="utf-8")

    key = tmp / "runner.key"
    key.write_bytes(b"k" * 64)
    profile_path = tmp / "profile.json"
    profile_path.write_text(json.dumps({
        "contract": runner.PROFILE_CONTRACT,
        "profile_id": "ci-host-runner",
        "site_uuid": "11111111-2222-4333-8444-555555555555",
        "environment": "staging",
        "wordpress_root": str(wp),
        "integrity_key_file": str(key),
        "receipt_root": str(wp / "wp-content" / "mad4b-runner" / "receipts"),
        "allowed_operations": sorted(runner.OPERATIONS),
    }), encoding="utf-8")
    profile = runner.load_profile(profile_path)

    doctor = runner.doctor(profile_path)
    assert doctor["generic_shell_available"] is False
    assert doctor["write_operations_available"] is False
    assert doctor["network_available_to_runner_contract"] is False
    assert doctor["mutation_performed"] is False

    # Authenticated read-only runtime status.
    job = make_job(profile, "runtime.status.read", {})
    job_path = tmp / "runtime-job.json"
    job_path.write_text(json.dumps(job), encoding="utf-8")
    receipt = runner.run_job(profile_path, job_path)
    assert receipt["execution_location"] == "host_runner"
    assert receipt["mutation_performed"] is False
    assert receipt["result"]["plugin_present"] is True
    assert receipt["readback_verdict"] == "PASS"

    # Exact replay reuses durable receipt rather than re-executing a new logical write/read.
    replay = runner.run_job(profile_path, job_path)
    assert replay["replayed"] is True
    assert replay["job_id"] == receipt["job_id"]

    # Same job_id with different operation/input is a conflict.
    conflict = make_job(profile, "package.integrity.verify", {}, job_id=job["job_id"])
    conflict_path = tmp / "conflict.json"
    conflict_path.write_text(json.dumps(conflict), encoding="utf-8")
    try:
        runner.run_job(profile_path, conflict_path)
        raise SystemExit("Host Runner accepted job_id replay with different operation")
    except ValueError as exc:
        if "different operation" not in str(exc):
            raise

    # Fixed-zone file hash succeeds and returns a normalized relative path.
    hash_job = make_job(profile, "filesystem.hash.read", {
        "zone": "plugin_root",
        "relative_path": "includes/health.php",
    })
    hash_path = tmp / "hash-job.json"
    hash_path.write_text(json.dumps(hash_job), encoding="utf-8")
    hashed = runner.run_job(profile_path, hash_path)
    assert hashed["result"]["relative_path"] == "includes/health.php"
    assert len(hashed["result"]["sha256"]) == 64
    assert hashed["mutation_performed"] is False

    # Path traversal cannot escape a named zone.
    traversal = make_job(profile, "filesystem.hash.read", {
        "zone": "plugin_root",
        "relative_path": "../../wp-config.php",
    })
    traversal_path = tmp / "traversal.json"
    traversal_path.write_text(json.dumps(traversal), encoding="utf-8")
    try:
        runner.run_job(profile_path, traversal_path)
        raise SystemExit("Host Runner accepted path traversal")
    except ValueError as exc:
        if "traversal denied" not in str(exc):
            raise

    # A symlink inside an otherwise allowed zone cannot redirect the read.
    outside = tmp / "outside.txt"
    outside.write_text("outside-secret", encoding="utf-8")
    (plugin / "linked.txt").symlink_to(outside)
    symlink_job = make_job(profile, "filesystem.hash.read", {
        "zone": "plugin_root",
        "relative_path": "linked.txt",
    })
    symlink_path = tmp / "symlink-job.json"
    symlink_path.write_text(json.dumps(symlink_job), encoding="utf-8")
    try:
        runner.run_job(profile_path, symlink_path)
        raise SystemExit("Host Runner followed symlink outside zone")
    except ValueError as exc:
        if "symlink" not in str(exc):
            raise

    # MAC tampering fails before operation execution.
    tampered = make_job(profile, "runtime.status.read", {})
    tampered["mac_sha256"] = "0" * 64
    tampered_path = tmp / "tampered.json"
    tampered_path.write_text(json.dumps(tampered), encoding="utf-8")
    try:
        runner.run_job(profile_path, tampered_path)
        raise SystemExit("Host Runner accepted invalid job MAC")
    except ValueError as exc:
        if "integrity MAC mismatch" not in str(exc):
            raise

    # Expired envelopes fail closed.
    now = datetime.now(timezone.utc)
    expired = make_job(
        profile,
        "runtime.status.read",
        {},
        created=now - timedelta(minutes=10),
        expires=now - timedelta(minutes=5),
    )
    expired_path = tmp / "expired.json"
    expired_path.write_text(json.dumps(expired), encoding="utf-8")
    try:
        runner.run_job(profile_path, expired_path)
        raise SystemExit("Host Runner accepted expired job")
    except ValueError as exc:
        if "expired" not in str(exc):
            raise

    # Unknown operation cannot be smuggled through the job.
    unknown = make_job(profile, "runtime.status.read", {})
    unknown["operation_id"] = "shell.execute"
    unknown["operation_fingerprint"] = "0" * 64
    unknown["mac_sha256"] = runner.job_mac(unknown, profile["_integrity_key"])
    unknown_path = tmp / "unknown.json"
    unknown_path.write_text(json.dumps(unknown), encoding="utf-8")
    try:
        runner.run_job(profile_path, unknown_path)
        raise SystemExit("Host Runner accepted unknown/generic shell operation")
    except ValueError as exc:
        if "operation is not allowed" not in str(exc):
            raise

    # Package integrity never accepts caller-defined paths.
    pkg = make_job(profile, "package.integrity.verify", {"path": "/etc"})
    pkg_path = tmp / "pkg-path.json"
    pkg_path.write_text(json.dumps(pkg), encoding="utf-8")
    try:
        runner.run_job(profile_path, pkg_path)
        raise SystemExit("package.integrity.verify accepted caller path")
    except ValueError as exc:
        if "takes no caller-defined paths" not in str(exc):
            raise

print("mad4b.host-runner.readonly-kernel.v1: PASS")

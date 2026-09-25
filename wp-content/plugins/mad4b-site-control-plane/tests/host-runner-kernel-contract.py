#!/usr/bin/env python3
"""Executable contract for the bounded Host Runner kernel."""

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
    "workspace.file.replace",
}:
    raise SystemExit("Host Runner kernel operation registry widened unexpectedly")
writes = {op for op, row in runner.OPERATIONS.items() if row.get("risk") != "read_only"}
if writes != {"workspace.file.replace"}:
    raise SystemExit("Host Runner kernel widened write operations unexpectedly")
if runner.OPERATIONS["workspace.file.replace"].get("zones") != ["runner_workspace"]:
    raise SystemExit("Host Runner write escaped dedicated runner workspace")
if runner.OPERATIONS["workspace.file.replace"].get("requires_plan") is not True:
    raise SystemExit("Host Runner write does not require exact plan")
if runner.OPERATIONS["workspace.file.replace"].get("requires_approval") is not True:
    raise SystemExit("Host Runner write does not require approval")


def iso(dt):
    return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def make_job(
    profile,
    operation_id,
    inputs,
    *,
    job_id=None,
    created=None,
    expires=None,
    plan_sha256="",
    approval_ref="",
    authority_ref="ci:read-authority",
):
    now = datetime.now(timezone.utc)
    job = {
        "contract": runner.JOB_CONTRACT,
        "job_id": job_id or str(uuid.uuid4()),
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "executor_fingerprint": profile["executor_fingerprint"],
        "operation_id": operation_id,
        "operation_version": runner.OPERATIONS[operation_id]["version"],
        "operation_fingerprint": runner.operation_fingerprint(operation_id),
        "created_at": iso(created or (now - timedelta(seconds=1))),
        "expires_at": iso(expires or (now + timedelta(minutes=5))),
        "input": inputs,
        "input_sha256": runner.sha256_bytes(runner.canonical_json(inputs)),
        "idempotency_key": "idem-" + (job_id or "new-" + str(uuid.uuid4())),
        "actor_ref": "ci:operator",
        "authority_ref": authority_ref,
        "submission_location": "contract_test",
    }
    if plan_sha256:
        job["plan_sha256"] = plan_sha256
    if approval_ref:
        job["approval_ref"] = approval_ref
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
    runner_workspace = wp / "wp-content" / "mad4b-runner" / "workspace"
    runner_workspace.mkdir(parents=True)

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
        "expected_runner_sha256": runner.sha256_file(Path(runner.__file__).resolve()),
        "receipt_root": str(wp / "wp-content" / "mad4b-runner" / "receipts"),
        "allowed_operations": sorted(runner.OPERATIONS),
    }), encoding="utf-8")
    profile = runner.load_profile(profile_path)

    # Canonicalization must not hide a caller-supplied symlinked WordPress root.
    root_link = tmp / "wordpress-link"
    root_link.symlink_to(wp, target_is_directory=True)
    root_link_profile = json.loads(profile_path.read_text(encoding="utf-8"))
    root_link_profile["wordpress_root"] = str(root_link)
    root_link_profile_path = tmp / "profile-root-link.json"
    root_link_profile_path.write_text(json.dumps(root_link_profile), encoding="utf-8")
    try:
        runner.load_profile(root_link_profile_path)
        raise SystemExit("Host Runner accepted symlinked WordPress root")
    except ValueError as exc:
        if "root symlink is forbidden" not in str(exc):
            raise

    # Receipt evidence location cannot be a symlink even when it resolves inside the runner zone.
    real_receipts = wp / "wp-content" / "mad4b-runner" / "real-receipts"
    real_receipts.mkdir(parents=True)
    receipt_link = wp / "wp-content" / "mad4b-runner" / "receipt-link"
    receipt_link.symlink_to(real_receipts, target_is_directory=True)
    receipt_link_profile = json.loads(profile_path.read_text(encoding="utf-8"))
    receipt_link_profile["receipt_root"] = str(receipt_link)
    receipt_link_profile_path = tmp / "profile-receipt-link.json"
    receipt_link_profile_path.write_text(json.dumps(receipt_link_profile), encoding="utf-8")
    try:
        runner.load_profile(receipt_link_profile_path)
        raise SystemExit("Host Runner accepted symlinked receipt root")
    except ValueError as exc:
        if "receipt_root symlink is forbidden" not in str(exc):
            raise

    doctor = runner.doctor(profile_path)
    assert doctor["generic_shell_available"] is False
    assert doctor["write_operations_available"] is True
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

    # Same job id/input/operation with changed authority identity is not an exact replay.
    authority_conflict = dict(job)
    authority_conflict["authority_ref"] = "ci:different-read-authority"
    authority_conflict["mac_sha256"] = runner.job_mac(authority_conflict, profile["_integrity_key"])
    authority_conflict_path = tmp / "authority-conflict.json"
    authority_conflict_path.write_text(json.dumps(authority_conflict), encoding="utf-8")
    try:
        runner.run_job(profile_path, authority_conflict_path)
        raise SystemExit("Host Runner accepted same job id with changed authority")
    except ValueError as exc:
        if "different authority_ref" not in str(exc):
            raise

    # Operations reject undeclared fields instead of silently ignoring caller input.
    status_extra = make_job(profile, "runtime.status.read", {"unexpected": True})
    status_extra_path = tmp / "status-with-extra-input.json"
    status_extra_path.write_text(json.dumps(status_extra), encoding="utf-8")
    try:
        runner.run_job(profile_path, status_extra_path)
        raise SystemExit("runtime.status.read accepted undeclared input")
    except ValueError as exc:
        if "takes no input fields" not in str(exc):
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

    # Executor identity is approval material: a different runner fingerprint fails even with a valid MAC.
    executor_drift = make_job(profile, "runtime.status.read", {})
    executor_drift["executor_fingerprint"] = "0" * 64
    executor_drift["mac_sha256"] = runner.job_mac(executor_drift, profile["_integrity_key"])
    executor_drift_path = tmp / "executor-drift.json"
    executor_drift_path.write_text(json.dumps(executor_drift), encoding="utf-8")
    try:
        runner.run_job(profile_path, executor_drift_path)
        raise SystemExit("Host Runner accepted executor drift after plan binding")
    except ValueError as exc:
        if "executor fingerprint mismatch" not in str(exc):
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

    # Reversible payload budget is lower than the signed envelope budget so base64+plan fit safely.
    try:
        runner.build_workspace_replace_plan(
            profile,
            "oversized.txt",
            b"x" * (runner.MAX_WRITE_BYTES + 1),
            "oversized payload must fail",
        )
        raise SystemExit("Host Runner accepted oversized reversible write payload")
    except ValueError as exc:
        if "too large" not in str(exc):
            raise

    # Dedicated runner-workspace write: exact plan + approval + readback + durable receipt.
    replacement = b"managed workspace state v2\n"
    plan = runner.build_workspace_replace_plan(
        profile,
        "state.txt",
        replacement,
        "exercise reversible host write",
    )
    write_job = make_job(
        profile,
        "workspace.file.replace",
        {
            "plan": plan,
            "new_content_b64": __import__("base64").b64encode(replacement).decode(),
        },
        plan_sha256=plan["plan_sha256"],
        approval_ref="approval:ci-workspace-write",
        authority_ref="ci:workspace-write-authority",
    )
    write_path = tmp / "workspace-write.json"
    write_path.write_text(json.dumps(write_job), encoding="utf-8")
    write_receipt = runner.run_job(profile_path, write_path)
    assert write_receipt["mutation_performed"] is True
    assert write_receipt["readback_verdict"] == "PASS"
    assert (runner_workspace / "state.txt").read_bytes() == replacement
    assert write_receipt["result"]["relative_path"] == "state.txt"
    assert write_receipt["result"]["plan_sha256"] == plan["plan_sha256"]
    journal = wp / "wp-content" / "mad4b-runner" / "journals" / f"{write_job['job_id']}.json"
    journal_row = json.loads(journal.read_text(encoding="utf-8"))
    assert journal_row["state"] == "DURABLE_VERIFIED_RECEIPT"
    assert journal_row["terminal"] is True
    assert journal_row["blind_retry_allowed"] is False

    # Exact replay returns the durable receipt and cannot re-execute the write.
    write_replay = runner.run_job(profile_path, write_path)
    assert write_replay["replayed"] is True
    assert (runner_workspace / "state.txt").read_bytes() == replacement

    # Approval identity is replay material and may not drift.
    changed_approval = dict(write_job)
    changed_approval["approval_ref"] = "approval:different"
    changed_approval["mac_sha256"] = runner.job_mac(changed_approval, profile["_integrity_key"])
    changed_approval_path = tmp / "workspace-write-approval-drift.json"
    changed_approval_path.write_text(json.dumps(changed_approval), encoding="utf-8")
    try:
        runner.run_job(profile_path, changed_approval_path)
        raise SystemExit("Host Runner accepted write replay with changed approval")
    except ValueError as exc:
        if "different approval_ref" not in str(exc):
            raise

    # Missing approval fails before mutation.
    missing_approval = make_job(
        profile,
        "workspace.file.replace",
        {
            "plan": plan,
            "new_content_b64": __import__("base64").b64encode(replacement).decode(),
        },
        plan_sha256=plan["plan_sha256"],
        authority_ref="ci:workspace-write-authority",
    )
    missing_approval_path = tmp / "missing-approval.json"
    missing_approval_path.write_text(json.dumps(missing_approval), encoding="utf-8")
    try:
        runner.run_job(profile_path, missing_approval_path)
        raise SystemExit("Host Runner accepted write without approval")
    except ValueError as exc:
        if "approval_ref is required" not in str(exc):
            raise

    # Caller command/shell/argv cannot be smuggled into a semantic write.
    shell_smuggle = dict(write_job)
    shell_smuggle["job_id"] = str(uuid.uuid4())
    shell_smuggle["idempotency_key"] = "idem-shell-smuggle"
    shell_smuggle["input"] = dict(write_job["input"])
    shell_smuggle["input"]["command"] = "sh -c 'id'"
    shell_smuggle["input_sha256"] = runner.sha256_bytes(runner.canonical_json(shell_smuggle["input"]))
    shell_smuggle["mac_sha256"] = runner.job_mac(shell_smuggle, profile["_integrity_key"])
    shell_smuggle_path = tmp / "shell-smuggle.json"
    shell_smuggle_path.write_text(json.dumps(shell_smuggle), encoding="utf-8")
    try:
        runner.run_job(profile_path, shell_smuggle_path)
        raise SystemExit("Host Runner accepted caller shell/command field")
    except ValueError as exc:
        if "input fields are invalid" not in str(exc):
            raise

    # Stale plan is denied after target state changes.
    stale_bytes = b"stale-plan-new\n"
    stale_plan = runner.build_workspace_replace_plan(
        profile,
        "stale.txt",
        stale_bytes,
        "stale plan fixture",
    )
    (runner_workspace / "stale.txt").write_bytes(b"drifted after planning\n")
    stale_job = make_job(
        profile,
        "workspace.file.replace",
        {
            "plan": stale_plan,
            "new_content_b64": __import__("base64").b64encode(stale_bytes).decode(),
        },
        plan_sha256=stale_plan["plan_sha256"],
        approval_ref="approval:stale-plan",
        authority_ref="ci:workspace-write-authority",
    )
    stale_job_path = tmp / "stale-write.json"
    stale_job_path.write_text(json.dumps(stale_job), encoding="utf-8")
    try:
        runner.run_job(profile_path, stale_job_path)
        raise SystemExit("Host Runner accepted stale workspace plan")
    except ValueError as exc:
        if "precondition changed since plan" not in str(exc):
            raise

    # Symlink swap between plan and apply fails at the immediate commit boundary.
    symlink_bytes = b"must-not-write-outside\n"
    symlink_plan = runner.build_workspace_replace_plan(
        profile,
        "swap.txt",
        symlink_bytes,
        "symlink swap fixture",
    )
    outside_write = tmp / "outside-write.txt"
    outside_write.write_bytes(b"outside-original\n")
    (runner_workspace / "swap.txt").symlink_to(outside_write)
    symlink_write_job = make_job(
        profile,
        "workspace.file.replace",
        {
            "plan": symlink_plan,
            "new_content_b64": __import__("base64").b64encode(symlink_bytes).decode(),
        },
        plan_sha256=symlink_plan["plan_sha256"],
        approval_ref="approval:symlink-swap",
        authority_ref="ci:workspace-write-authority",
    )
    symlink_write_path = tmp / "symlink-write.json"
    symlink_write_path.write_text(json.dumps(symlink_write_job), encoding="utf-8")
    try:
        runner.run_job(profile_path, symlink_write_path)
        raise SystemExit("Host Runner accepted symlink swap before commit")
    except ValueError as exc:
        if "symlink" not in str(exc):
            raise
    assert outside_write.read_bytes() == b"outside-original\n"
    (runner_workspace / "swap.txt").unlink()

    # Receipt persistence failure after a write forces verified rollback.
    rollback_target = runner_workspace / "rollback.txt"
    rollback_target.write_bytes(b"before-rollback\n")
    rollback_new = b"after-rollback\n"
    rollback_plan = runner.build_workspace_replace_plan(
        profile,
        "rollback.txt",
        rollback_new,
        "receipt failure rollback fixture",
    )
    rollback_job = make_job(
        profile,
        "workspace.file.replace",
        {
            "plan": rollback_plan,
            "new_content_b64": __import__("base64").b64encode(rollback_new).decode(),
        },
        plan_sha256=rollback_plan["plan_sha256"],
        approval_ref="approval:rollback",
        authority_ref="ci:workspace-write-authority",
    )
    rollback_job_path = tmp / "rollback-write.json"
    rollback_job_path.write_text(json.dumps(rollback_job), encoding="utf-8")
    original_atomic_json = runner.atomic_json_write
    def fail_write_receipt(path, data):
        if path.parent.name == "receipts" and data.get("job_id") == rollback_job["job_id"]:
            raise OSError("simulated Host Runner receipt persistence failure")
        return original_atomic_json(path, data)
    runner.atomic_json_write = fail_write_receipt
    try:
        runner.run_job(profile_path, rollback_job_path)
        raise SystemExit("Host Runner write unexpectedly succeeded without durable receipt")
    except OSError as exc:
        if "receipt persistence failure" not in str(exc):
            raise
    finally:
        runner.atomic_json_write = original_atomic_json
    assert rollback_target.read_bytes() == b"before-rollback\n"
    rollback_journal = json.loads(
        (wp / "wp-content" / "mad4b-runner" / "journals" / f"{rollback_job['job_id']}.json")
        .read_text(encoding="utf-8")
    )
    assert rollback_journal["state"] == "ROLLED_BACK_AFTER_FAILURE"
    assert rollback_journal["rollback_verified"] is True
    assert rollback_journal["blind_retry_allowed"] is False

    # If both receipt persistence and rollback fail, uncertainty is durable and never blind-retried.
    uncertain_target = runner_workspace / "uncertain.txt"
    uncertain_target.write_bytes(b"uncertain-before\n")
    uncertain_new = b"uncertain-after\n"
    uncertain_plan = runner.build_workspace_replace_plan(
        profile,
        "uncertain.txt",
        uncertain_new,
        "rollback failure uncertainty fixture",
    )
    uncertain_job = make_job(
        profile,
        "workspace.file.replace",
        {
            "plan": uncertain_plan,
            "new_content_b64": __import__("base64").b64encode(uncertain_new).decode(),
        },
        plan_sha256=uncertain_plan["plan_sha256"],
        approval_ref="approval:uncertain",
        authority_ref="ci:workspace-write-authority",
    )
    uncertain_job_path = tmp / "uncertain-write.json"
    uncertain_job_path.write_text(json.dumps(uncertain_job), encoding="utf-8")
    original_rollback = runner._rollback_workspace_replace
    def fail_rollback(result):
        return False
    def fail_uncertain_receipt(path, data):
        if path.parent.name == "receipts" and data.get("job_id") == uncertain_job["job_id"]:
            raise OSError("simulated receipt failure with rollback failure")
        return original_atomic_json(path, data)
    runner._rollback_workspace_replace = fail_rollback
    runner.atomic_json_write = fail_uncertain_receipt
    try:
        runner.run_job(profile_path, uncertain_job_path)
        raise SystemExit("Host Runner uncertainty fixture unexpectedly succeeded")
    except OSError as exc:
        if "rollback failure" not in str(exc):
            raise
    finally:
        runner._rollback_workspace_replace = original_rollback
        runner.atomic_json_write = original_atomic_json
    uncertain_journal = json.loads(
        (wp / "wp-content" / "mad4b-runner" / "journals" / f"{uncertain_job['job_id']}.json")
        .read_text(encoding="utf-8")
    )
    assert uncertain_journal["state"] == "MUTATED_BUT_EVIDENCE_UNCERTAIN"
    assert uncertain_journal["rollback_verified"] is False
    assert uncertain_journal["blind_retry_allowed"] is False
    assert uncertain_target.read_bytes() == uncertain_new

print("mad4b.host-runner.bounded-kernel.v2: PASS")

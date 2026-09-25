#!/usr/bin/env python3
"""End-to-end repository contract for WordPress Host Bridge -> Host Runner."""

from __future__ import annotations

import base64
import importlib.util
import json
import tempfile
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


runner = load("mad4b_host_runner", ROOT / "tools/mad4b_host_runner.py")

with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    wp = tmp / "wordpress"
    plugin = wp / "wp-content" / "plugins" / "mad4b-site-control-plane"
    plugin.mkdir(parents=True)
    (wp / "wp-config.php").write_text("<?php // bridge-runner fixture\n", encoding="utf-8")
    (plugin / "mad4b-site-control-plane.php").write_text("<?php // fixture\n", encoding="utf-8")

    key = tmp / "runner.key"
    key.write_bytes(b"k" * 64)
    profile_path = tmp / "profile.json"
    site_uuid = "11111111-2222-4333-8444-555555555555"
    profile_path.write_text(json.dumps({
        "contract": runner.PROFILE_CONTRACT,
        "profile_id": "ci-bridge-runner",
        "site_uuid": site_uuid,
        "environment": "staging",
        "wordpress_root": str(wp),
        "integrity_key_file": str(key),
        "expected_runner_sha256": runner.sha256_file(Path(runner.__file__).resolve()),
        "receipt_root": str(wp / "wp-content" / "mad4b-runner" / "receipts"),
        "allowed_operations": ["runtime.status.read", "workspace.file.replace"],
    }), encoding="utf-8")
    profile = runner.load_profile(profile_path)
    bridge = Path(profile["bridge_root"])
    (bridge / "queued").mkdir(parents=True, exist_ok=True)

    plan = {
        "contract": "mad4b.host-operation-plan.v1",
        "operation_id": "runtime.status.read",
        "operation_version": 1,
        "risk": "read_only",
        "approval_required": False,
        "runner_profile_id": profile["profile_id"],
        "target": {
            "site_uuid": site_uuid,
            "environment": "staging",
            "wordpress_root": str(wp.resolve()),
            "target_fingerprint": profile["target_fingerprint"],
        },
        "arguments": {},
        "submission_location": "wordpress_request",
        "execution_location": "host_runner",
        "commit_location": "host_runner",
        "production_authorized": False,
        "created_at": "2026-09-25T00:00:00+00:00",
        "authorizing": False,
        "mutation_performed": False,
    }
    plan["plan_sha256"] = runner._bridge_digest(plan)

    job_id = str(uuid.uuid4())
    submission = {
        "contract": "mad4b.host-bridge-submission.v1",
        "job_id": job_id,
        "idempotency_key": "bridge-ci-read-1",
        "plan": plan,
        "plan_sha256": plan["plan_sha256"],
        "approval_ref": "",
        "authority": {
            "policy_decision_sha256": "",
            "agent_public_id": "",
            "approval_ticket_id": "",
        },
        "submission_location": "wordpress_request",
        "execution_location": "host_runner",
        "commit_location": "host_runner",
        "created_at": plan["created_at"],
        "production_authorized": False,
    }
    submission["submission_sha256"] = runner._bridge_digest(submission)
    (bridge / "queued" / f"{job_id}.json").write_text(
        json.dumps(submission, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )

    result = runner.consume_bridge_spool(profile_path, 10)
    assert result["processed_count"] == 1, result
    assert result["processed"][0]["state"] == "SUCCEEDED", result
    assert not (bridge / "queued" / f"{job_id}.json").exists()
    assert not (bridge / "running" / f"{job_id}.json").exists()
    bridge_receipt = json.loads((bridge / "receipts" / f"{job_id}.json").read_text(encoding="utf-8"))
    assert bridge_receipt["bridge_submission_sha256"] == submission["submission_sha256"]
    assert bridge_receipt["execution_location"] == "host_runner"
    assert bridge_receipt["submission_location"] == "wordpress_request"
    assert bridge_receipt["commit_location"] == "host_runner"
    assert bridge_receipt["mutation_performed"] is False
    assert bridge_receipt["result"]["plugin_present"] is True

    local_receipt = json.loads(
        (Path(profile["receipt_root"]) / f"{job_id}.json").read_text(encoding="utf-8")
    )
    assert local_receipt["bridge_submission_sha256"] == submission["submission_sha256"]

    # Reversible write parity: WordPress-style Host Bridge submission binds the
    # exact runner plan, approval, policy decision and actor, then the Host
    # Runner performs the same bounded workspace.file.replace semantic operation.
    workspace_payload = b"bridge-governed-write-v1\n"
    execution_plan = runner.build_workspace_replace_plan(
        profile,
        "bridge-write.txt",
        workspace_payload,
        "Host Bridge reversible write parity fixture",
    )
    write_outer_plan = {
        "contract": "mad4b.host-operation-plan.v1",
        "operation_id": "workspace.file.replace",
        "operation_version": runner.OPERATIONS["workspace.file.replace"]["version"],
        "risk": runner.OPERATIONS["workspace.file.replace"]["risk"],
        "approval_required": True,
        "runner_profile_id": profile["profile_id"],
        "target": {
            "site_uuid": site_uuid,
            "environment": "staging",
            "wordpress_root": str(wp.resolve()),
            "target_fingerprint": profile["target_fingerprint"],
        },
        "arguments": {
            "plan": execution_plan,
            "new_content_b64": base64.b64encode(workspace_payload).decode(),
        },
        "submission_location": "wordpress_request",
        "execution_location": "host_runner",
        "commit_location": "host_runner",
        "production_authorized": False,
        "created_at": "2026-09-25T00:00:30+00:00",
        "authorizing": False,
        "mutation_performed": False,
    }
    write_outer_plan["plan_sha256"] = runner._bridge_digest(write_outer_plan)
    write_id = str(uuid.uuid4())
    write_submission = {
        "contract": "mad4b.host-bridge-submission.v1",
        "job_id": write_id,
        "idempotency_key": "bridge-ci-write-1",
        "plan": write_outer_plan,
        "plan_sha256": write_outer_plan["plan_sha256"],
        "approval_ref": "approval:bridge-ci-write",
        "authority": {
            "policy_decision_sha256": "a" * 64,
            "agent_public_id": "agent:bridge-ci",
            "approval_ticket_id": "ticket:bridge-ci",
        },
        "submission_location": "wordpress_request",
        "execution_location": "host_runner",
        "commit_location": "host_runner",
        "created_at": write_outer_plan["created_at"],
        "production_authorized": False,
    }
    write_submission["submission_sha256"] = runner._bridge_digest(write_submission)
    (bridge / "queued" / f"{write_id}.json").write_text(
        json.dumps(write_submission, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    result = runner.consume_bridge_spool(profile_path, 10)
    assert result["processed_count"] == 1, result
    assert result["processed"][0]["state"] == "SUCCEEDED", result
    write_receipt = json.loads(
        (bridge / "receipts" / f"{write_id}.json").read_text(encoding="utf-8")
    )
    assert write_receipt["bridge_submission_sha256"] == write_submission["submission_sha256"]
    assert write_receipt["operation_id"] == "workspace.file.replace"
    assert write_receipt["plan_sha256"] == execution_plan["plan_sha256"]
    assert write_receipt["approval_ref"] == "approval:bridge-ci-write"
    assert write_receipt["authority_ref"] == "a" * 64
    assert write_receipt["actor_ref"] == "agent:bridge-ci"
    assert write_receipt["mutation_performed"] is True
    assert write_receipt["readback_verdict"] == "PASS"
    assert write_receipt["submission_location"] == "wordpress_request"
    assert write_receipt["execution_location"] == "host_runner"
    assert write_receipt["commit_location"] == "host_runner"
    assert (Path(profile["runner_workspace"]) / "bridge-write.txt").read_bytes() == workspace_payload

    # Commit/executor location is plan identity. A material location change
    # changes the approved plan digest and is rejected until a new admitted
    # executor/location policy and fresh authorization are produced.
    location_drift_plan = dict(write_outer_plan)
    original_plan_sha = location_drift_plan["plan_sha256"]
    location_drift_plan["commit_location"] = "wordpress_request"
    location_drift_plan.pop("plan_sha256", None)
    location_drift_plan["plan_sha256"] = runner._bridge_digest(location_drift_plan)
    assert location_drift_plan["plan_sha256"] != original_plan_sha
    location_drift_id = str(uuid.uuid4())
    location_drift = {
        "contract": "mad4b.host-bridge-submission.v1",
        "job_id": location_drift_id,
        "idempotency_key": "bridge-ci-location-drift",
        "plan": location_drift_plan,
        "plan_sha256": location_drift_plan["plan_sha256"],
        "approval_ref": "approval:bridge-ci-write",
        "authority": {
            "policy_decision_sha256": "a" * 64,
            "agent_public_id": "agent:bridge-ci",
            "approval_ticket_id": "ticket:bridge-ci",
        },
        "submission_location": "wordpress_request",
        "execution_location": "host_runner",
        "commit_location": "wordpress_request",
        "created_at": location_drift_plan["created_at"],
        "production_authorized": False,
    }
    location_drift["submission_sha256"] = runner._bridge_digest(location_drift)
    (bridge / "queued" / f"{location_drift_id}.json").write_text(
        json.dumps(location_drift, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    result = runner.consume_bridge_spool(profile_path, 10)
    assert result["processed_count"] == 1, result
    assert result["processed"][0]["state"] == "DEAD_LETTERED", result
    location_incident = json.loads(
        (bridge / "dead-letter" / f"{location_drift_id}.json").read_text(encoding="utf-8")
    )
    assert location_incident["blind_retry_allowed"] is False
    assert (Path(profile["runner_workspace"]) / "bridge-write.txt").read_bytes() == workspace_payload

    # Missing write approval/authority never reaches the operation and is dead-lettered.
    denied_payload = b"must-not-be-written\n"
    denied_execution_plan = runner.build_workspace_replace_plan(
        profile,
        "bridge-denied.txt",
        denied_payload,
        "Host Bridge missing approval denial fixture",
    )
    denied_outer = dict(write_outer_plan)
    denied_outer["created_at"] = "2026-09-25T00:01:00+00:00"
    denied_outer["arguments"] = {
        "plan": denied_execution_plan,
        "new_content_b64": base64.b64encode(denied_payload).decode(),
    }
    denied_outer.pop("plan_sha256", None)
    denied_outer["plan_sha256"] = runner._bridge_digest(denied_outer)
    denied_id = str(uuid.uuid4())
    denied = {
        "contract": "mad4b.host-bridge-submission.v1",
        "job_id": denied_id,
        "idempotency_key": "bridge-ci-denied-write",
        "plan": denied_outer,
        "plan_sha256": denied_outer["plan_sha256"],
        "approval_ref": "",
        "authority": {
            "policy_decision_sha256": "",
            "agent_public_id": "",
            "approval_ticket_id": "",
        },
        "submission_location": "wordpress_request",
        "execution_location": "host_runner",
        "commit_location": "host_runner",
        "created_at": denied_outer["created_at"],
        "production_authorized": False,
    }
    denied["submission_sha256"] = runner._bridge_digest(denied)
    (bridge / "queued" / f"{denied_id}.json").write_text(
        json.dumps(denied, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    result = runner.consume_bridge_spool(profile_path, 10)
    assert result["processed_count"] == 1, result
    assert result["processed"][0]["state"] == "DEAD_LETTERED", result
    assert not (Path(profile["runner_workspace"]) / "bridge-denied.txt").exists()
    denied_incident = json.loads(
        (bridge / "dead-letter" / f"{denied_id}.json").read_text(encoding="utf-8")
    )
    assert denied_incident["blind_retry_allowed"] is False
    assert denied_incident["payload_persisted"] is False

    # Stale running claim before any mutation intent is safe to requeue.
    crash_before_id = str(uuid.uuid4())
    crash_before_plan = dict(plan)
    crash_before_plan["created_at"] = "2026-09-25T00:02:00+00:00"
    crash_before_plan.pop("plan_sha256", None)
    crash_before_plan["plan_sha256"] = runner._bridge_digest(crash_before_plan)
    crash_before_submission = {
        "contract": "mad4b.host-bridge-submission.v1",
        "job_id": crash_before_id,
        "idempotency_key": "bridge-ci-crash-before-mutation",
        "plan": crash_before_plan,
        "plan_sha256": crash_before_plan["plan_sha256"],
        "approval_ref": "",
        "authority": {
            "policy_decision_sha256": "",
            "agent_public_id": "",
            "approval_ticket_id": "",
        },
        "submission_location": "wordpress_request",
        "execution_location": "host_runner",
        "commit_location": "host_runner",
        "created_at": crash_before_plan["created_at"],
        "production_authorized": False,
    }
    crash_before_submission["submission_sha256"] = runner._bridge_digest(crash_before_submission)
    crash_before_claim = runner._bridge_running_claim(profile, crash_before_submission, 30)
    crash_before_claim["lease_expires_at"] = "2020-01-01T00:00:00Z"
    (bridge / "running" / f"{crash_before_id}.json").write_text(
        json.dumps(crash_before_claim, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    reconcile = runner.reconcile_bridge_spool(profile_path, stale_seconds=30, limit=10)
    states = {row["job_id"]: row["state"] for row in reconcile["reconciled"]}
    assert states[crash_before_id] == "SAFE_REQUEUED_BEFORE_MUTATION", reconcile
    assert (bridge / "queued" / f"{crash_before_id}.json").is_file()
    assert not (bridge / "running" / f"{crash_before_id}.json").exists()
    result = runner.consume_bridge_spool(profile_path, 10)
    assert result["processed_count"] == 1, result
    assert result["processed"][0]["state"] == "SUCCEEDED", result

    # Crash after local durable execution receipt but before bridge receipt:
    # reconcile evidence, never execute the semantic operation a second time.
    crash_receipt_id = str(uuid.uuid4())
    crash_receipt_plan = dict(plan)
    crash_receipt_plan["created_at"] = "2026-09-25T00:03:00+00:00"
    crash_receipt_plan.pop("plan_sha256", None)
    crash_receipt_plan["plan_sha256"] = runner._bridge_digest(crash_receipt_plan)
    crash_receipt_submission = {
        "contract": "mad4b.host-bridge-submission.v1",
        "job_id": crash_receipt_id,
        "idempotency_key": "bridge-ci-crash-after-local-receipt",
        "plan": crash_receipt_plan,
        "plan_sha256": crash_receipt_plan["plan_sha256"],
        "approval_ref": "",
        "authority": {
            "policy_decision_sha256": "",
            "agent_public_id": "",
            "approval_ticket_id": "",
        },
        "submission_location": "wordpress_request",
        "execution_location": "host_runner",
        "commit_location": "host_runner",
        "created_at": crash_receipt_plan["created_at"],
        "production_authorized": False,
    }
    crash_receipt_submission["submission_sha256"] = runner._bridge_digest(crash_receipt_submission)
    local_job = runner._bridge_submission_to_job(profile, crash_receipt_submission)
    local_job_path = Path(profile["bridge_job_root"]) / f"{crash_receipt_id}.json"
    local_job_path.parent.mkdir(parents=True, exist_ok=True)
    local_job_path.write_text(json.dumps(local_job, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    local_receipt = runner.run_job(profile_path, local_job_path)
    assert local_receipt["bridge_submission_sha256"] == crash_receipt_submission["submission_sha256"]
    assert not (bridge / "receipts" / f"{crash_receipt_id}.json").exists()
    crash_receipt_claim = runner._bridge_running_claim(profile, crash_receipt_submission, 30)
    crash_receipt_claim["lease_expires_at"] = "2020-01-01T00:00:00Z"
    (bridge / "running" / f"{crash_receipt_id}.json").write_text(
        json.dumps(crash_receipt_claim, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    reconcile = runner.reconcile_bridge_spool(profile_path, stale_seconds=30, limit=10)
    states = {row["job_id"]: row["state"] for row in reconcile["reconciled"]}
    assert states[crash_receipt_id] == "BRIDGE_RECEIPT_REPAIRED_FROM_LOCAL_RECEIPT", reconcile
    repaired = json.loads((bridge / "receipts" / f"{crash_receipt_id}.json").read_text(encoding="utf-8"))
    assert repaired["reconciled_from_stale_running"] is True
    assert repaired["bridge_submission_sha256"] == crash_receipt_submission["submission_sha256"]
    assert repaired["replayed"] is False

    # Stale running write with a mutation-intent journal is never blind-retried.
    crash_after_id = str(uuid.uuid4())
    crash_after_payload = b"must-not-blind-retry\n"
    crash_after_execution_plan = runner.build_workspace_replace_plan(
        profile,
        "bridge-crash-after-side-effect.txt",
        crash_after_payload,
        "stale running write mutation evidence fixture",
    )
    crash_after_outer = dict(write_outer_plan)
    crash_after_outer["created_at"] = "2026-09-25T00:04:00+00:00"
    crash_after_outer["arguments"] = {
        "plan": crash_after_execution_plan,
        "new_content_b64": base64.b64encode(crash_after_payload).decode(),
    }
    crash_after_outer.pop("plan_sha256", None)
    crash_after_outer["plan_sha256"] = runner._bridge_digest(crash_after_outer)
    crash_after_submission = {
        "contract": "mad4b.host-bridge-submission.v1",
        "job_id": crash_after_id,
        "idempotency_key": "bridge-ci-crash-after-mutation-intent",
        "plan": crash_after_outer,
        "plan_sha256": crash_after_outer["plan_sha256"],
        "approval_ref": "approval:bridge-ci-crash-after",
        "authority": {
            "policy_decision_sha256": "b" * 64,
            "agent_public_id": "agent:bridge-ci-crash-after",
            "approval_ticket_id": "ticket:bridge-ci-crash-after",
        },
        "submission_location": "wordpress_request",
        "execution_location": "host_runner",
        "commit_location": "host_runner",
        "created_at": crash_after_outer["created_at"],
        "production_authorized": False,
    }
    crash_after_submission["submission_sha256"] = runner._bridge_digest(crash_after_submission)
    crash_after_claim = runner._bridge_running_claim(profile, crash_after_submission, 30)
    crash_after_claim["lease_expires_at"] = "2020-01-01T00:00:00Z"
    (bridge / "running" / f"{crash_after_id}.json").write_text(
        json.dumps(crash_after_claim, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    journal_root = Path(profile["journal_root"])
    journal_root.mkdir(parents=True, exist_ok=True)
    (journal_root / f"{crash_after_id}.json").write_text(
        json.dumps(
            {
                "contract": "mad4b.host-runner-mutation-journal.v1",
                "job_id": crash_after_id,
                "operation_id": "workspace.file.replace",
                "plan_sha256": crash_after_execution_plan["plan_sha256"],
                "approval_ref": crash_after_submission["approval_ref"],
                "relative_path": "bridge-crash-after-side-effect.txt",
                "before_sha256": "ABSENT",
                "expected_after_sha256": runner.sha256_bytes(crash_after_payload),
                "state": "MUTATION_STARTED",
                "terminal": False,
                "blind_retry_allowed": False,
                "created_at": "2026-09-25T00:04:01Z",
            },
            sort_keys=True,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    reconcile = runner.reconcile_bridge_spool(profile_path, stale_seconds=30, limit=10)
    states = {row["job_id"]: row["state"] for row in reconcile["reconciled"]}
    assert states[crash_after_id] == "RECOVERY_REQUIRED", reconcile
    assert not (bridge / "queued" / f"{crash_after_id}.json").exists()
    assert not (bridge / "running" / f"{crash_after_id}.json").exists()
    crash_after_incident = json.loads(
        (bridge / "recovery-required" / f"{crash_after_id}.json").read_text(encoding="utf-8")
    )
    assert crash_after_incident["blind_retry_allowed"] is False
    assert crash_after_incident["reconciliation_required"] is True
    assert crash_after_incident["journal_state"] == "MUTATION_STARTED"
    assert not (Path(profile["runner_workspace"]) / "bridge-crash-after-side-effect.txt").exists()

    # Tampered bridge evidence dead-letters rather than executing.
    bad_id = str(uuid.uuid4())
    bad = dict(submission)
    bad["job_id"] = bad_id
    bad["idempotency_key"] = "bridge-ci-bad"
    bad["submission_sha256"] = "0" * 64
    (bridge / "queued" / f"{bad_id}.json").write_text(
        json.dumps(bad, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    result = runner.consume_bridge_spool(profile_path, 10)
    assert result["processed_count"] == 1, result
    assert result["processed"][0]["state"] == "DEAD_LETTERED", result
    incident = json.loads((bridge / "dead-letter" / f"{bad_id}.json").read_text(encoding="utf-8"))
    assert incident["blind_retry_allowed"] is False
    assert incident["payload_persisted"] is False
    assert not (Path(profile["receipt_root"]) / f"{bad_id}.json").exists()

print("mad4b.host-bridge-runner.v1: PASS")

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
    assert not (Path(profile["runner_workspace"]) / "bridge-write.txt").read_bytes() == b""

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

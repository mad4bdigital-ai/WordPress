#!/usr/bin/env python3
"""End-to-end repository contract for WordPress Host Bridge -> Host Runner."""

from __future__ import annotations

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
        "allowed_operations": ["runtime.status.read"],
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
            "target_fingerprint": "bridge-target-evidence-only",
        },
        "arguments": {},
        "submission_location": "wordpress_request",
        "execution_location": "host_runner",
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
    assert bridge_receipt["mutation_performed"] is False
    assert bridge_receipt["result"]["plugin_present"] is True

    local_receipt = json.loads(
        (Path(profile["receipt_root"]) / f"{job_id}.json").read_text(encoding="utf-8")
    )
    assert local_receipt["bridge_submission_sha256"] == submission["submission_sha256"]

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

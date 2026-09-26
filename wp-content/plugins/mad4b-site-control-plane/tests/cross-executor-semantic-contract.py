#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import subprocess
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


runner = load("mad4b_host_runner_cross_executor", ROOT / "tools/mad4b_host_runner.py")
php_fixture = ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/wp-cli-semantic-runtime.php"


def iso(dt):
    return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    wp = tmp / "wordpress"
    plugin = wp / "wp-content" / "plugins" / "mad4b-site-control-plane"
    plugin.mkdir(parents=True)
    (wp / "wp-config.php").write_text("<?php // semantic parity fixture\n", encoding="utf-8")
    (plugin / "mad4b-site-control-plane.php").write_text("<?php // semantic parity plugin\n", encoding="utf-8")
    (wp / "wp-content" / "mad4b-runner" / "workspace").mkdir(parents=True)

    key = tmp / "runner.key"
    key.write_bytes(b"k" * 64)
    profile_path = tmp / "profile.json"
    profile_path.write_text(
        json.dumps(
            {
                "contract": runner.PROFILE_CONTRACT,
                "profile_id": "ci-cross-executor",
                "site_uuid": "11111111-2222-4333-8444-555555555555",
                "environment": "staging",
                "wordpress_root": str(wp),
                "integrity_key_file": str(key),
                "expected_runner_sha256": runner.sha256_file(Path(runner.__file__).resolve()),
                "receipt_root": str(wp / "wp-content" / "mad4b-runner" / "receipts"),
                "allowed_operations": ["runtime.status.read"],
            }
        ),
        encoding="utf-8",
    )
    profile = runner.load_profile(profile_path)

    now = datetime.now(timezone.utc)
    inputs = {}
    job = {
        "contract": runner.JOB_CONTRACT,
        "job_id": str(uuid.uuid4()),
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "executor_fingerprint": profile["executor_fingerprint"],
        "operation_id": "runtime.status.read",
        "operation_version": runner.OPERATIONS["runtime.status.read"]["version"],
        "operation_fingerprint": runner.operation_fingerprint("runtime.status.read"),
        "created_at": iso(now - timedelta(seconds=1)),
        "expires_at": iso(now + timedelta(minutes=5)),
        "input": inputs,
        "input_sha256": runner.sha256_bytes(runner.canonical_json(inputs)),
        "idempotency_key": "idem-cross-executor",
        "actor_ref": "ci:cross-executor",
        "authority_ref": "ci:read-authority",
        "submission_location": "contract_test",
    }
    job["mac_sha256"] = runner.job_mac(job, profile["_integrity_key"])
    job_path = tmp / "job.json"
    job_path.write_text(json.dumps(job), encoding="utf-8")
    host_receipt = runner.run_job(profile_path, job_path)
    host = host_receipt["result"]

    proc = subprocess.run(
        ["php", str(php_fixture), str(wp)],
        check=True,
        capture_output=True,
        text=True,
        timeout=20,
    )
    cli = json.loads(proc.stdout.strip())

    keys = [
        "contract",
        "operation_id",
        "wordpress_root",
        "wp_config_present",
        "wp_config_sha256",
        "plugin_present",
        "environment",
        "readonly",
        "authorizing",
        "authority_class",
        "production_authorized",
        "mutation_performed",
    ]
    for key_name in keys:
        if host.get(key_name) != cli.get(key_name):
            raise SystemExit(
                "cross-executor semantic mismatch "
                f"{key_name}: host={host.get(key_name)!r} cli={cli.get(key_name)!r}"
            )

    if host_receipt["execution_location"] != "host_runner":
        raise SystemExit("Host Runner execution location lost truthfulness")
    if host["contract"] != "mad4b.runtime-status-read.v1":
        raise SystemExit("runtime status semantic contract drift")
    if host["mutation_performed"] is not False:
        raise SystemExit("runtime status semantic read mutated target")
    if host["authority_class"] != "read_only_non_authorizing":
        raise SystemExit("runtime status authority class drift")
    if host["readonly"] is not True or host["authorizing"] is not False:
        raise SystemExit("runtime status authority semantics drift")
    if host["production_authorized"] is not False:
        raise SystemExit("runtime status unexpectedly authorizes Production")

print("mad4b.cross-executor.runtime-status.v1: PASS")

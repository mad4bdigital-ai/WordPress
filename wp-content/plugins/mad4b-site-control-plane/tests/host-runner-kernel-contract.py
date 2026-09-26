#!/usr/bin/env python3
"""Executable contract for the bounded Host Runner kernel."""

from __future__ import annotations

import importlib.util
import json
import tempfile
import uuid
import zipfile
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
    "workspace.file.rollback",
    "wordpress_plugin_deploy",
    "wordpress_plugin_rollback",
}:
    raise SystemExit("Host Runner kernel operation registry widened unexpectedly")
writes = {op for op, row in runner.OPERATIONS.items() if row.get("risk") != "read_only"}
if writes != {"workspace.file.replace", "workspace.file.rollback", "wordpress_plugin_deploy", "wordpress_plugin_rollback"}:
    raise SystemExit("Host Runner kernel widened write operations unexpectedly")
for write_operation in sorted(writes):
    expected_zones = (
        ["plugin_root", "package_staging"]
        if write_operation == "wordpress_plugin_deploy"
        else (["plugin_root"] if write_operation == "wordpress_plugin_rollback" else ["runner_workspace"])
    )
    if runner.OPERATIONS[write_operation].get("zones") != expected_zones:
        raise SystemExit(f"Host Runner write escaped its named zones: {write_operation}")
    if runner.OPERATIONS[write_operation].get("requires_plan") is not True:
        raise SystemExit(f"Host Runner write does not require exact plan: {write_operation}")
    if runner.OPERATIONS[write_operation].get("requires_approval") is not True:
        raise SystemExit(f"Host Runner write does not require approval: {write_operation}")



def write_plugin_fixture(root: Path, source: str, build: str, manifest: str, version: str, marker: str):
    (root / "includes").mkdir(parents=True, exist_ok=True)
    files = {
        "mad4b-site-control-plane.php": f"<?php // {marker}\n".encode(),
        "includes/health.php": f"<?php return '{marker}';\n".encode(),
    }
    for rel, raw in files.items():
        target = root / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(raw)
    package_files = [
        {"path": rel, "bytes": len(raw), "sha256": runner.sha256_bytes(raw)}
        for rel, raw in sorted(files.items())
    ]
    provenance = {
        "contract": "mad4b.build-provenance.v1",
        "source_commit_sha": source,
        "build_fingerprint": build,
        "package_manifest_digest": manifest,
        "control_plane_version": version,
        "package_files": package_files,
    }
    (root / "MAD4B-BUILD-PROVENANCE.json").write_text(
        json.dumps(provenance, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    return provenance, files


def stage_candidate_bundle(profile, source: str, build: str, manifest: str, version: str, marker: str):
    staging = Path(profile["package_staging_root"])
    staging.mkdir(parents=True, exist_ok=True)
    bundle = staging / source
    bundle.mkdir()
    temp_plugin = bundle / "_fixture-plugin"
    temp_plugin.mkdir()
    provenance, files = write_plugin_fixture(temp_plugin, source, build, manifest, version, marker)
    archive_name = f"mad4b-site-control-plane-{version}.zip"
    archive = bundle / archive_name
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_STORED) as zf:
        for rel, raw in sorted(files.items()):
            zf.writestr(f"{runner.PLUGIN_SLUG}/{rel}", raw)
        zf.writestr(
            f"{runner.PLUGIN_SLUG}/MAD4B-BUILD-PROVENANCE.json",
            (json.dumps(provenance, sort_keys=True, indent=2) + "\n").encode(),
        )
    __import__("shutil").rmtree(temp_plugin)
    archive_sha = runner.sha256_file(archive)
    receipt = {
        "contract": "mad4b.deterministic-control-plane-package.v1",
        "source_commit_sha": source,
        "build_fingerprint": build,
        "package_manifest_digest": manifest,
        "archive_sha256": archive_sha,
        "control_plane_version": version,
    }
    receipt_path = bundle / "CANONICAL-PACKAGE-RECEIPT.json"
    receipt_path.write_text(json.dumps(receipt, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    install = {
        "contract": "mad4b.site-control-plane.general-distribution-kit.v1",
        "repository": "mad4bdigital-ai/WordPress",
        "commit": source,
        "build_fingerprint": build,
        "package_manifest_digest": manifest,
        "control_plane": {
            "version": version,
            "archive": archive_name,
            "sha256": archive_sha,
            "provenance_contract": "mad4b.build-provenance.v1",
        },
        "canonical_package": {
            "contract": "mad4b.deterministic-control-plane-package.v1",
            "archive_sha256": archive_sha,
            "receipt_sha256": runner.sha256_file(receipt_path),
        },
    }
    (bundle / "install-manifest.json").write_text(
        json.dumps(install, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    (bundle / "BUILD-FINGERPRINT.txt").write_text(build + "\n", encoding="utf-8")
    (bundle / "PACKAGE-MANIFEST-DIGEST.txt").write_text(manifest + "\n", encoding="utf-8")
    return {
        "source_commit_sha": source,
        "build_fingerprint": build,
        "package_manifest_digest": manifest,
        "archive_sha256": archive_sha,
        "control_plane_version": version,
        "artifact_identity": f"mad4b-site-control-plane-general-distribution-kit-{source}",
    }


def make_plugin_deploy_plan(profile, current, candidate, reason):
    plan = {
        "contract": runner.PLUGIN_DEPLOY_PLAN_CONTRACT,
        "operation_id": "wordpress_plugin_deploy",
        "operation_version": runner.OPERATIONS["wordpress_plugin_deploy"]["version"],
        "runner_profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "plugin_slug": runner.PLUGIN_SLUG,
        "bundle_key": candidate["source_commit_sha"],
        "current": runner._control_plane_identity(current),
        "candidate": candidate,
        "active_runtime_observed": True,
        "backup_before_replace": True,
        "atomic_replace_required": True,
        "same_cycle_file_readback_required": True,
        "rollback_on_failed_readback": True,
        "caller_supplied_path_allowed": False,
        "caller_supplied_url_allowed": False,
        "caller_supplied_credentials_allowed": False,
        "production_authorized": False,
        "reason": reason,
    }
    plan["plan_sha256"] = runner.plan_digest(plan)
    return plan


def make_plugin_rollback_plan(profile, deploy_receipt, bridge_receipt_sha, reason):
    result = deploy_receipt["result"]
    previous = dict(result["previous_identity"])
    plan = {
        "contract": runner.PLUGIN_ROLLBACK_PLAN_CONTRACT,
        "operation_id": "wordpress_plugin_rollback",
        "operation_version": runner.OPERATIONS["wordpress_plugin_rollback"]["version"],
        "runner_profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "plugin_slug": runner.PLUGIN_SLUG,
        "source_job_id": deploy_receipt["job_id"],
        "source_bridge_receipt_sha256": bridge_receipt_sha,
        "expected_current": {
            "source_commit_sha": result["source_commit_sha"],
            "build_fingerprint": result["build_fingerprint"],
            "package_manifest_digest": result["package_manifest_digest"],
        },
        "restore": previous,
        "expected_current_sha256": result["after_sha256"],
        "restore_sha256": result["before_sha256"],
        "caller_supplied_path_allowed": False,
        "caller_supplied_url_allowed": False,
        "caller_supplied_credentials_allowed": False,
        "production_authorized": False,
        "reason": reason,
    }
    plan["plan_sha256"] = runner.plan_digest(plan)
    return plan

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
    current_source = "a" * 40
    current_build = "b" * 64
    current_manifest = "c" * 64
    current_version = "0.4.0-rc.58"
    write_plugin_fixture(
        plugin,
        current_source,
        current_build,
        current_manifest,
        current_version,
        "current-runtime",
    )
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
    if not isinstance(profile.get("_integrity_key"), (bytes, bytearray)):
        raise SystemExit("Host Runner normalized profile lost binary HMAC key material")
    expected_target_fingerprint = runner.sha256_bytes(runner.canonical_json({
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "wordpress_root": profile["wordpress_root"],
        "wp_config_sha256": profile["wp_config_sha256"],
    }))
    if profile["target_fingerprint"] != expected_target_fingerprint:
        raise SystemExit("Host Runner target fingerprint canonical material drifted")

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
        message = str(exc)
        if (
            "root symlink is forbidden" not in message
            and "link/reparse path component forbidden" not in message
        ):
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
        message = str(exc)
        if (
            "receipt_root symlink is forbidden" not in message
            and "link/reparse path component forbidden" not in message
        ):
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
        message = str(exc)
        if (
            "symlink" not in message
            and "link/reparse path component forbidden" not in message
        ):
            raise
    (plugin / "linked.txt").unlink()

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
        runner.run_job_with_failure_evidence(profile_path, unknown_path)
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

    # Exact pre-staged General Distribution bundle deploys the Control Plane with
    # backup, atomic directory swap, file/provenance readback and durable replay.
    candidate = stage_candidate_bundle(
        profile,
        "d" * 40,
        "e" * 64,
        "f" * 64,
        "0.4.0-rc.59",
        "candidate-runtime",
    )
    deploy_plan = make_plugin_deploy_plan(
        profile,
        {
            "source_commit_sha": current_source,
            "build_fingerprint": current_build,
            "package_manifest_digest": current_manifest,
        },
        candidate,
        "exact General Distribution candidate",
    )
    deploy_job = make_job(
        profile,
        "wordpress_plugin_deploy",
        {"plan": deploy_plan},
        plan_sha256=deploy_plan["plan_sha256"],
        approval_ref="approval:ci-plugin-deploy",
        authority_ref="ci:plugin-deploy-authority",
    )
    deploy_path = tmp / "plugin-deploy.json"
    deploy_path.write_text(json.dumps(deploy_job), encoding="utf-8")
    deploy_receipt = runner.run_job(profile_path, deploy_path)
    assert deploy_receipt["mutation_performed"] is True
    assert deploy_receipt["readback_verdict"] == "PASS"
    assert deploy_receipt["result"]["source_commit_sha"] == candidate["source_commit_sha"]
    assert deploy_receipt["result"]["build_fingerprint"] == candidate["build_fingerprint"]
    assert deploy_receipt["result"]["package_manifest_digest"] == candidate["package_manifest_digest"]
    assert deploy_receipt["result"]["activation_state_preserved"] is True
    installed = runner._installed_control_plane_identity(plugin)
    assert installed["source_commit_sha"] == candidate["source_commit_sha"]
    assert installed["build_fingerprint"] == candidate["build_fingerprint"]
    assert installed["package_manifest_digest"] == candidate["package_manifest_digest"]
    rollback_dir = plugin.parent / f".{runner.PLUGIN_SLUG}.rollback-{deploy_job['job_id']}"
    assert rollback_dir.is_dir()
    deploy_replay = runner.run_job(profile_path, deploy_path)
    assert deploy_replay["replayed"] is True
    assert deploy_replay["replay_readback_verdict"] == "PASS"

    # Caller-controlled staged paths/URLs are absent from the semantic input.
    smuggled_plan = dict(deploy_plan)
    smuggled_plan["candidate"] = dict(candidate)
    smuggled_plan["candidate"]["url"] = "https://example.invalid/payload.zip"
    smuggled_plan["plan_sha256"] = runner.plan_digest(smuggled_plan)
    smuggled_job = make_job(
        profile,
        "wordpress_plugin_deploy",
        {"plan": smuggled_plan},
        plan_sha256=smuggled_plan["plan_sha256"],
        approval_ref="approval:ci-plugin-deploy-smuggled",
        authority_ref="ci:plugin-deploy-authority",
    )
    smuggled_path = tmp / "plugin-deploy-smuggled.json"
    smuggled_path.write_text(json.dumps(smuggled_job), encoding="utf-8")
    try:
        runner.run_job(profile_path, smuggled_path)
        raise SystemExit("Host Runner accepted caller-smuggled plugin deployment URL")
    except ValueError:
        pass

    # A post-swap readback mismatch must restore the previous exact package.
    second = stage_candidate_bundle(
        profile,
        "1" * 40,
        "2" * 64,
        "3" * 64,
        "0.4.0-rc.60",
        "candidate-runtime-corrupt-readback",
    )
    second_plan = make_plugin_deploy_plan(
        profile,
        candidate,
        second,
        "simulate failed plugin deployment readback",
    )
    second_job = make_job(
        profile,
        "wordpress_plugin_deploy",
        {"plan": second_plan},
        plan_sha256=second_plan["plan_sha256"],
        approval_ref="approval:ci-plugin-deploy-rollback",
        authority_ref="ci:plugin-deploy-authority",
    )
    second_path = tmp / "plugin-deploy-readback-failure.json"
    second_path.write_text(json.dumps(second_job), encoding="utf-8")
    original_installed_identity = runner._installed_control_plane_identity

    def corrupt_live_candidate_readback(root):
        identity = original_installed_identity(root)
        if root == plugin and identity["source_commit_sha"] == second["source_commit_sha"]:
            identity = dict(identity)
            identity["build_fingerprint"] = "9" * 64
        return identity

    runner._installed_control_plane_identity = corrupt_live_candidate_readback
    try:
        try:
            runner.run_job(profile_path, second_path)
            raise SystemExit("Host Runner accepted corrupt plugin deployment readback")
        except RuntimeError as exc:
            if "postcondition" not in str(exc):
                raise
    finally:
        runner._installed_control_plane_identity = original_installed_identity
    restored = runner._installed_control_plane_identity(plugin)
    assert restored["source_commit_sha"] == candidate["source_commit_sha"]

    # Fresh-request acceptance can explicitly roll the successful source deploy
    # back to its exact prior package without shell/path/package input.
    bridge_receipt_root = Path(profile["bridge_root"]) / "receipts"
    bridge_receipt_root.mkdir(parents=True, exist_ok=True)
    source_bridge_receipt = dict(deploy_receipt)
    source_bridge_receipt["bridge_contract"] = "mad4b.host-bridge-execution.v1"
    source_bridge_receipt["bridge_submission_sha256"] = deploy_receipt.get("bridge_submission_sha256", "")
    source_bridge_receipt_path = bridge_receipt_root / f"{deploy_job['job_id']}.json"
    runner.atomic_json_write(source_bridge_receipt_path, source_bridge_receipt)
    rollback_plan = make_plugin_rollback_plan(
        profile,
        deploy_receipt,
        runner.sha256_file(source_bridge_receipt_path),
        "fresh acceptance rejected deployed candidate",
    )
    plugin_rollback_job = make_job(
        profile,
        "wordpress_plugin_rollback",
        {"plan": rollback_plan},
        plan_sha256=rollback_plan["plan_sha256"],
        approval_ref="approval:ci-plugin-rollback",
        authority_ref="ci:plugin-deploy-authority",
    )
    plugin_rollback_path = tmp / "plugin-rollback.json"
    plugin_rollback_path.write_text(json.dumps(plugin_rollback_job), encoding="utf-8")
    plugin_rollback_receipt = runner.run_job(profile_path, plugin_rollback_path)
    assert plugin_rollback_receipt["mutation_performed"] is True
    assert plugin_rollback_receipt["readback_verdict"] == "PASS"
    assert plugin_rollback_receipt["result"]["source_job_id"] == deploy_job["job_id"]
    rolled_back_identity = runner._installed_control_plane_identity(plugin)
    assert rolled_back_identity["source_commit_sha"] == current_source
    assert rolled_back_identity["build_fingerprint"] == current_build
    assert rolled_back_identity["package_manifest_digest"] == current_manifest
    plugin_rollback_replay = runner.run_job(profile_path, plugin_rollback_path)
    assert plugin_rollback_replay["replayed"] is True
    assert plugin_rollback_replay["replay_readback_verdict"] == "PASS"

    reconciliation_after_plugin_rollback = runner.reconcile(profile_path)
    reconciled_by_job = {
        row["job_id"]: row for row in reconciliation_after_plugin_rollback["entries"]
    }
    assert reconciled_by_job[deploy_job["job_id"]]["reconciliation_status"] == "SUPERSEDED_BY_VERIFIED_ROLLBACK"
    assert reconciled_by_job[deploy_job["job_id"]]["superseded_by_verified_rollback_job_id"] == plugin_rollback_job["job_id"]
    assert reconciled_by_job[plugin_rollback_job["job_id"]]["reconciliation_status"] == "DURABLE_RECEIPT_PRESENT"

    failed_journal = json.loads(
        (Path(profile["journal_root"]) / f"{second_job['job_id']}.json").read_text(encoding="utf-8")
    )
    assert failed_journal["state"] == "ROLLED_BACK_AFTER_FAILURE"
    assert failed_journal["rollback_verified"] is True
    assert not (Path(profile["receipt_root"]) / f"{second_job['job_id']}.json").exists()

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
        if getattr(exc, "code", "") != "HOST_RESOURCE_WRITE_BYTES_EXCEEDED":
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

    # Rollback lineage must come from the durable receipt+journal store, not a fabricated in-memory object.
    fabricated_receipt = dict(write_receipt)
    fabricated_receipt["job_id"] = str(uuid.uuid4())
    try:
        runner.build_workspace_rollback_plan(
            profile,
            fabricated_receipt,
            "fabricated rollback receipt must fail",
        )
        raise SystemExit("Host Runner accepted fabricated rollback source receipt")
    except ValueError as exc:
        if "durable receipt is unavailable" not in str(exc):
            raise

    # A successful reversible write can be explicitly rolled back under a new exact plan+approval.
    rollback_plan = runner.build_workspace_rollback_plan(
        profile,
        write_receipt,
        "explicitly rollback successful workspace write",
    )
    explicit_rollback_job = make_job(
        profile,
        "workspace.file.rollback",
        {"plan": rollback_plan},
        plan_sha256=rollback_plan["plan_sha256"],
        approval_ref="approval:ci-workspace-rollback",
        authority_ref="ci:workspace-write-authority",
    )
    explicit_rollback_path = tmp / "explicit-success-rollback.json"
    explicit_rollback_path.write_text(json.dumps(explicit_rollback_job), encoding="utf-8")
    rollback_receipt = runner.run_job(profile_path, explicit_rollback_path)
    assert rollback_receipt["mutation_performed"] is True
    assert rollback_receipt["readback_verdict"] == "PASS"
    assert rollback_receipt["result"]["source_job_id"] == write_job["job_id"]
    assert rollback_receipt["result"]["after_sha256"] == "ABSENT"
    assert not (runner_workspace / "state.txt").exists()

    # The original successful write receipt is historical evidence, not proof of current postcondition.
    try:
        runner.run_job_with_failure_evidence(profile_path, write_path)
        raise SystemExit("Host Runner replay returned stale success after explicit rollback")
    except RuntimeError as exc:
        if "HOST_RUNNER_REPLAY_RECONCILIATION_REQUIRED" not in str(exc):
            raise

    # Exact rollback replay remains valid while the rollback postcondition is still current.
    rollback_replay = runner.run_job(profile_path, explicit_rollback_path)
    assert rollback_replay["replayed"] is True
    assert rollback_replay["replay_readback_verdict"] == "PASS"
    assert not (runner_workspace / "state.txt").exists()

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

    # Caller command/shell/executable/argv cannot be smuggled into a semantic write.
    injection_fields = {
        "command": "sh -c 'id'",
        "shell": "/bin/sh",
        "executable": "/usr/bin/python3",
        "argv": ["-c", "import os; os.system('id')"],
    }
    for field_name, field_value in injection_fields.items():
        injected = dict(write_job)
        injected["job_id"] = str(uuid.uuid4())
        injected["idempotency_key"] = f"idem-injection-{field_name}"
        injected["input"] = dict(write_job["input"])
        injected["input"][field_name] = field_value
        injected["input_sha256"] = runner.sha256_bytes(runner.canonical_json(injected["input"]))
        injected["mac_sha256"] = runner.job_mac(injected, profile["_integrity_key"])
        injected_path = tmp / f"injection-{field_name}.json"
        injected_path.write_text(json.dumps(injected), encoding="utf-8")
        try:
            runner.run_job(profile_path, injected_path)
            raise SystemExit(f"Host Runner accepted caller {field_name} injection")
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
        message = str(exc)
        if (
            "symlink" not in message
            and "link/reparse path component forbidden" not in message
        ):
            raise
    assert outside_write.read_bytes() == b"outside-original\n"
    (runner_workspace / "swap.txt").unlink()

    # Post-mutation readback failure is not success: it rolls back exact prior state.
    readback_target = runner_workspace / "readback.txt"
    readback_target.write_bytes(b"before-readback-failure\n")
    readback_new = b"after-readback-failure\n"
    readback_plan = runner.build_workspace_replace_plan(
        profile,
        "readback.txt",
        readback_new,
        "postcondition readback failure fixture",
    )
    readback_job = make_job(
        profile,
        "workspace.file.replace",
        {
            "plan": readback_plan,
            "new_content_b64": __import__("base64").b64encode(readback_new).decode(),
        },
        plan_sha256=readback_plan["plan_sha256"],
        approval_ref="approval:readback-failure",
        authority_ref="ci:workspace-write-authority",
    )
    readback_job_path = tmp / "readback-failure-write.json"
    readback_job_path.write_text(json.dumps(readback_job), encoding="utf-8")
    original_atomic_bytes = runner.atomic_bytes_write
    def corrupt_only_authoritative_write(path, raw):
        if path == readback_target and raw == readback_new:
            return original_atomic_bytes(path, b"corrupt-postcondition\n")
        return original_atomic_bytes(path, raw)
    runner.atomic_bytes_write = corrupt_only_authoritative_write
    try:
        runner.run_job(profile_path, readback_job_path)
        raise SystemExit("Host Runner accepted failed postcondition readback")
    except RuntimeError as exc:
        if "postcondition readback failed" not in str(exc):
            raise
    finally:
        runner.atomic_bytes_write = original_atomic_bytes
    assert readback_target.read_bytes() == b"before-readback-failure\n"
    readback_journal = json.loads(
        (wp / "wp-content" / "mad4b-runner" / "journals" / f"{readback_job['job_id']}.json")
        .read_text(encoding="utf-8")
    )
    assert readback_journal["state"] == "ROLLED_BACK_AFTER_FAILURE"
    assert readback_journal["rollback_verified"] is True
    assert readback_journal["blind_retry_allowed"] is False

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

    # Reconciliation is read-only and classifies durable, rolled-back and uncertain outcomes.
    reconciliation = runner.reconcile(profile_path)
    assert reconciliation["mutation_performed"] is False
    assert reconciliation["blind_retry_allowed"] is False
    by_job = {row["job_id"]: row for row in reconciliation["entries"]}
    assert by_job[write_job["job_id"]]["reconciliation_status"] == "SUPERSEDED_BY_VERIFIED_ROLLBACK"
    assert by_job[write_job["job_id"]]["superseded_by_verified_rollback_job_id"] == explicit_rollback_job["job_id"]
    assert by_job[write_job["job_id"]]["reconciliation_required"] is False
    assert by_job[explicit_rollback_job["job_id"]]["reconciliation_status"] == "DURABLE_RECEIPT_PRESENT"
    assert by_job[readback_job["job_id"]]["reconciliation_status"] == "ROLLED_BACK_OBSERVED_NO_RECEIPT"
    assert by_job[rollback_job["job_id"]]["reconciliation_status"] == "ROLLED_BACK_OBSERVED_NO_RECEIPT"
    assert by_job[uncertain_job["job_id"]]["reconciliation_status"] == "RUNTIME_EFFECT_OBSERVED_NO_RECEIPT"
    assert by_job[uncertain_job["job_id"]]["reconciliation_required"] is True
    assert by_job[uncertain_job["job_id"]]["blind_retry_allowed"] is False
    assert reconciliation["reconciliation_required_count"] >= 1

    doctor_after_faults = runner.doctor(profile_path)
    assert doctor_after_faults["reconciliation_required_count"] >= 1
    assert doctor_after_faults["mutation_performed"] is False


    # Operator Doctor surfaces permanent failures and reconciliation-required incidents durably.
    doctor_after_incidents = runner.doctor(profile_path)
    assert doctor_after_incidents["dead_letter_count"] >= 1
    assert doctor_after_incidents["recovery_required_incident_count"] >= 1
    assert doctor_after_incidents["operator_review_required"] is True
    dead_letter_root = wp / "wp-content" / "mad4b-runner" / "dead-letter"
    recovery_required_root = wp / "wp-content" / "mad4b-runner" / "recovery-required"
    incident_rows = []
    for incident_path in list(dead_letter_root.glob("*.json")) + list(recovery_required_root.glob("*.json")):
        incident_rows.append(json.loads(incident_path.read_text(encoding="utf-8")))
    assert incident_rows
    assert all(row["blind_retry_allowed"] is False for row in incident_rows)
    assert all(row["payload_persisted"] is False for row in incident_rows)
    assert any(row["state"] == "DEAD_LETTERED" for row in incident_rows)
    assert any(row["state"] == "RECOVERY_REQUIRED" for row in incident_rows)

    # Fixed resource budgets fail closed with stable reason codes.
    budgets = runner.resource_budget_status()
    assert budgets["contract"] == runner.RESOURCE_BUDGET_CONTRACT
    assert budgets["job_input_bytes"] == runner.MAX_JOB_BYTES
    assert budgets["receipt_output_bytes"] == runner.MAX_RECEIPT_BYTES
    assert budgets["workspace_write_bytes"] == runner.MAX_WRITE_BYTES
    assert budgets["process"]["available"] is False
    assert budgets["process"]["timeout_seconds"] == 0
    assert budgets["process"]["output_bytes"] == 0
    assert budgets["network"]["available"] is False
    assert budgets["network"]["request_count"] == 0
    assert budgets["database"]["available"] is False
    assert budgets["database"]["operation_count"] == 0

    for resource, code in (
        ("process", "HOST_RESOURCE_PROCESS_DISABLED"),
        ("network", "HOST_RESOURCE_NETWORK_DISABLED"),
        ("database", "HOST_RESOURCE_DATABASE_DISABLED"),
    ):
        try:
            runner.assert_resource_capability(resource)
            raise SystemExit(f"Host Runner unexpectedly enabled {resource}")
        except runner.HostRunnerResourceError as exc:
            assert exc.code == code

    oversized_input = tmp / "oversized-job.json"
    oversized_input.write_bytes(b"{" + b"x" * (runner.MAX_JOB_BYTES + 1) + b"}")
    try:
        runner.load_json_bounded(oversized_input)
        raise SystemExit("Host Runner accepted oversized signed job input")
    except runner.HostRunnerResourceError as exc:
        assert exc.code == "HOST_RESOURCE_INPUT_BYTES_EXCEEDED"

    try:
        runner.atomic_json_write(
            tmp / "oversized-receipt.json",
            {"blob": "x" * runner.MAX_RECEIPT_BYTES},
        )
        raise SystemExit("Host Runner accepted oversized durable output")
    except runner.HostRunnerResourceError as exc:
        assert exc.code == "HOST_RESOURCE_OUTPUT_BYTES_EXCEEDED"

    try:
        runner.build_workspace_replace_plan(
            profile,
            "oversized-write.txt",
            b"x" * (runner.MAX_WRITE_BYTES + 1),
            "oversized write must fail closed",
        )
        raise SystemExit("Host Runner accepted oversized workspace write")
    except runner.HostRunnerResourceError as exc:
        assert exc.code == "HOST_RESOURCE_WRITE_BYTES_EXCEEDED"

    original_disk_usage = runner.shutil.disk_usage
    class LowDisk:
        total = runner.MIN_FREE_SPACE_RESERVE_BYTES
        used = runner.MIN_FREE_SPACE_RESERVE_BYTES
        free = 0
    runner.shutil.disk_usage = lambda path: LowDisk()
    low_disk_target = runner_workspace / "low-disk.txt"
    try:
        runner.atomic_bytes_write(low_disk_target, b"x")
        raise SystemExit("Host Runner write ignored minimum free-space reserve")
    except runner.HostRunnerResourceError as exc:
        assert exc.code == "HOST_RESOURCE_DISK_BUDGET_EXCEEDED"
    finally:
        runner.shutil.disk_usage = original_disk_usage
    assert not low_disk_target.exists()

print("mad4b.host-runner.bounded-kernel.v2: PASS")

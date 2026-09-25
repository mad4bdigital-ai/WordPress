#!/usr/bin/env python3
"""Minimal provider-neutral MAD4B Host Runner kernel.

Repository slice:
- Staging-only.
- Read-only operations plus one dedicated-workspace reversible write.
- No shell/subprocess/network execution.
- HMAC-authenticated job envelopes.
- Exact local runner profile / target fingerprint binding.
- Named filesystem zones with canonical path confinement.
- Durable idempotent receipts.

General host/site writes, scheduler/bootstrap enrollment, provider CLI/API adapters,
and Production eligibility remain unavailable until separately implemented/certified.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import os
import re
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

PROFILE_CONTRACT = "mad4b.host-runner-profile.v1"
JOB_CONTRACT = "mad4b.host-runner-job.v1"
RECEIPT_CONTRACT = "mad4b.tool-execution-receipt.v1"
RUNNER_CONTRACT = "mad4b.host-runner.v1"
SUPPORTED_ENVIRONMENTS = {"staging"}
MAX_JOB_BYTES = 65536
MAX_RECEIPT_BYTES = 262144
MAX_WRITE_BYTES = 32768
WORKSPACE_PLAN_CONTRACT = "mad4b.host-runner-workspace-replace-plan.v1"
WORKSPACE_ROLLBACK_PLAN_CONTRACT = "mad4b.host-runner-workspace-rollback-plan.v1"

# Fixed semantic operation registry. There is intentionally no generic command or shell surface.
OPERATIONS: dict[str, dict[str, Any]] = {
    "runtime.status.read": {
        "version": 1,
        "risk": "read_only",
        "zones": [],
    },
    "filesystem.hash.read": {
        "version": 1,
        "risk": "read_only",
        "zones": ["wordpress_root", "plugin_root"],
    },
    "package.integrity.verify": {
        "version": 1,
        "risk": "read_only",
        "zones": ["plugin_root"],
    },
    "workspace.file.replace": {
        "version": 1,
        "risk": "reversible_write",
        "zones": ["runner_workspace"],
        "requires_plan": True,
        "requires_approval": True,
    },
    "workspace.file.rollback": {
        "version": 1,
        "risk": "reversible_write",
        "zones": ["runner_workspace"],
        "requires_plan": True,
        "requires_approval": True,
    },
}


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def canonical_json(value: Any) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as handle:
        while True:
            chunk = handle.read(1024 * 1024)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()


def atomic_json_write(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(value, sort_keys=True, indent=2) + "\n"
    raw = payload.encode()
    if len(raw) > MAX_RECEIPT_BYTES:
        raise ValueError("receipt exceeds bounded output budget")
    tmp = path.with_name(path.name + f".tmp-{uuid.uuid4().hex}")
    with tmp.open("wb") as handle:
        handle.write(raw)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(tmp, path)
    try:
        fd = os.open(str(path.parent), os.O_RDONLY)
        os.fsync(fd)
        os.close(fd)
    except OSError:
        pass


def load_json_bounded(path: Path, max_bytes: int = MAX_JOB_BYTES) -> dict[str, Any]:
    if path.is_symlink() or not path.is_file():
        raise ValueError(f"JSON source is not a regular file: {path}")
    raw = path.read_bytes()
    if len(raw) > max_bytes:
        raise ValueError("JSON source exceeds bounded input budget")
    value = json.loads(raw.decode("utf-8"))
    if not isinstance(value, dict):
        raise ValueError("JSON source must contain an object")
    return value


def operation_fingerprint(operation_id: str) -> str:
    definition = OPERATIONS.get(operation_id)
    if not definition:
        return ""
    return sha256_bytes(canonical_json({
        "operation_id": operation_id,
        **definition,
        "runner_contract": RUNNER_CONTRACT,
    }))


def _is_within(child: Path, root: Path) -> bool:
    try:
        child.relative_to(root)
        return True
    except ValueError:
        return False


def _reject_symlink_chain(path: Path, stop: Path) -> None:
    current = path
    stop = stop.resolve()
    while True:
        if current.is_symlink():
            raise ValueError(f"symlink path component forbidden: {current}")
        if current == stop:
            return
        if current.parent == current:
            raise ValueError("path escaped configured zone")
        current = current.parent


def load_profile(path: Path) -> dict[str, Any]:
    profile = load_json_bounded(path)
    if profile.get("contract") != PROFILE_CONTRACT:
        raise ValueError("Host Runner profile contract mismatch")
    environment = str(profile.get("environment") or "")
    if environment not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("Host Runner profile environment is not supported")
    profile_id = str(profile.get("profile_id") or "")
    if not re.fullmatch(r"[A-Za-z0-9._-]{3,120}", profile_id):
        raise ValueError("Host Runner profile_id is invalid")
    site_uuid = str(profile.get("site_uuid") or "").lower()
    if not re.fullmatch(r"[a-f0-9-]{36}", site_uuid):
        raise ValueError("Host Runner profile site_uuid is invalid")

    root_raw = str(profile.get("wordpress_root") or "")
    if not root_raw:
        raise ValueError("Host Runner profile wordpress_root missing")
    root_input = Path(root_raw).expanduser()
    if root_input.is_symlink():
        raise ValueError("Host Runner profile WordPress root symlink is forbidden")
    root = root_input.resolve()
    if not root.is_dir():
        raise ValueError("Host Runner profile WordPress root is invalid")
    if not (root / "wp-config.php").is_file():
        raise ValueError("Host Runner profile WordPress root guard failed")

    key_file_raw = str(profile.get("integrity_key_file") or "")
    if not key_file_raw:
        raise ValueError("Host Runner integrity_key_file missing")
    key_file_input = Path(key_file_raw).expanduser()
    if key_file_input.is_symlink():
        raise ValueError("Host Runner integrity key symlink is forbidden")
    key_file = key_file_input.resolve()
    if not key_file.is_file():
        raise ValueError("Host Runner integrity key file is invalid")
    key = key_file.read_bytes()
    if len(key) < 32 or len(key) > 4096:
        raise ValueError("Host Runner integrity key must contain 32..4096 bytes")

    configured = profile.get("allowed_operations")
    if not isinstance(configured, list) or not configured:
        raise ValueError("Host Runner allowed_operations must be a non-empty list")
    allowed = []
    for operation_id in configured:
        operation_id = str(operation_id)
        if operation_id not in OPERATIONS:
            raise ValueError(f"Host Runner profile requests unknown operation: {operation_id}")
        allowed.append(operation_id)

    runner_source_sha256 = sha256_file(Path(__file__).resolve())
    expected_runner_sha256 = str(profile.get("expected_runner_sha256") or "").lower()
    if not re.fullmatch(r"[a-f0-9]{64}", expected_runner_sha256):
        raise ValueError("Host Runner expected_runner_sha256 is invalid")
    if not hmac.compare_digest(expected_runner_sha256, runner_source_sha256):
        raise ValueError("Host Runner executable identity does not match profile")
    executor_fingerprint = sha256_bytes(canonical_json({
        "runner_contract": RUNNER_CONTRACT,
        "runner_source_sha256": runner_source_sha256,
        "operations": {
            op: operation_fingerprint(op) for op in sorted(set(allowed))
        },
    }))

    runner_workspace = (root / "wp-content" / "mad4b-runner" / "workspace").resolve()
    expected_workspace = (root / "wp-content" / "mad4b-runner").resolve()
    if not _is_within(runner_workspace, expected_workspace):
        raise ValueError("Host Runner workspace escaped dedicated runner root")
    if runner_workspace.exists() and (runner_workspace.is_symlink() or not runner_workspace.is_dir()):
        raise ValueError("Host Runner workspace must be a regular directory")

    receipt_root_input = Path(
        str(profile.get("receipt_root") or (root / "wp-content/mad4b-runner/receipts"))
    ).expanduser()
    if receipt_root_input.is_symlink():
        raise ValueError("Host Runner receipt_root symlink is forbidden")

    normalized = {
        "contract": PROFILE_CONTRACT,
        "profile_id": profile_id,
        "site_uuid": site_uuid,
        "environment": environment,
        "wordpress_root": str(root),
        "allowed_operations": sorted(set(allowed)),
        "runner_source_sha256": runner_source_sha256,
        "executor_fingerprint": executor_fingerprint,
        "integrity_key_file": str(key_file),
        "receipt_root": str(receipt_root_input.resolve()),
        "runner_workspace": str(runner_workspace),
        "journal_root": str((expected_workspace / "journals").resolve()),
        "rollback_root": str((expected_workspace / "rollback").resolve()),
    }
    receipt_root = Path(normalized["receipt_root"])
    if not _is_within(receipt_root, expected_workspace):
        raise ValueError("Host Runner receipt_root escaped dedicated runner workspace")
    for evidence_root_key in ("journal_root", "rollback_root"):
        candidate = Path(normalized[evidence_root_key])
        if not _is_within(candidate, expected_workspace):
            raise ValueError(f"Host Runner {evidence_root_key} escaped dedicated runner workspace")
        if candidate.exists() and (candidate.is_symlink() or not candidate.is_dir()):
            raise ValueError(f"Host Runner {evidence_root_key} must be a regular directory")
    normalized["target_fingerprint"] = sha256_bytes(canonical_json({
        "profile_id": profile_id,
        "site_uuid": site_uuid,
        "environment": environment,
        "wordpress_root": str(root),
    }))
    normalized["_integrity_key"] = key
    return normalized


def job_mac(job: dict[str, Any], key: bytes) -> str:
    material = dict(job)
    material.pop("mac_sha256", None)
    return hmac.new(key, canonical_json(material), hashlib.sha256).hexdigest()


def verify_job(job: dict[str, Any], profile: dict[str, Any]) -> dict[str, Any]:
    if job.get("contract") != JOB_CONTRACT:
        raise ValueError("Host Runner job contract mismatch")
    job_id = str(job.get("job_id") or "").lower()
    if not re.fullmatch(r"[a-f0-9-]{36}", job_id):
        raise ValueError("Host Runner job_id is invalid")
    if job.get("profile_id") != profile["profile_id"]:
        raise ValueError("Host Runner job profile mismatch")
    if job.get("site_uuid") != profile["site_uuid"]:
        raise ValueError("Host Runner job site_uuid mismatch")
    if job.get("environment") != profile["environment"]:
        raise ValueError("Host Runner job environment mismatch")
    if job.get("target_fingerprint") != profile["target_fingerprint"]:
        raise ValueError("Host Runner job target fingerprint mismatch")
    if not hmac.compare_digest(
        str(job.get("executor_fingerprint") or ""),
        str(profile["executor_fingerprint"])
    ):
        raise ValueError("Host Runner executor fingerprint mismatch")

    idempotency_key = str(job.get("idempotency_key") or "")
    actor_ref = str(job.get("actor_ref") or "")
    authority_ref = str(job.get("authority_ref") or "")
    if not idempotency_key or len(idempotency_key) > 191:
        raise ValueError("Host Runner idempotency_key is invalid")
    if not actor_ref or len(actor_ref) > 191:
        raise ValueError("Host Runner actor_ref is invalid")
    if not authority_ref or len(authority_ref) > 191:
        raise ValueError("Host Runner authority_ref is invalid")

    operation_id = str(job.get("operation_id") or "")
    if operation_id not in profile["allowed_operations"] or operation_id not in OPERATIONS:
        raise ValueError("Host Runner operation is not allowed")
    definition = OPERATIONS[operation_id]
    if job.get("operation_version") != definition["version"]:
        raise ValueError("Host Runner operation version mismatch")
    expected_fp = operation_fingerprint(operation_id)
    if not hmac.compare_digest(str(job.get("operation_fingerprint") or ""), expected_fp):
        raise ValueError("Host Runner operation fingerprint mismatch")

    created = str(job.get("created_at") or "")
    expires = str(job.get("expires_at") or "")
    try:
        created_dt = datetime.fromisoformat(created.replace("Z", "+00:00"))
        expires_dt = datetime.fromisoformat(expires.replace("Z", "+00:00"))
    except ValueError as exc:
        raise ValueError("Host Runner job timestamps are invalid") from exc
    now = datetime.now(timezone.utc)
    if created_dt > now or expires_dt <= now or expires_dt <= created_dt:
        raise ValueError("Host Runner job is expired or not yet valid")

    inputs = job.get("input")
    if not isinstance(inputs, dict):
        raise ValueError("Host Runner job input must be an object")
    input_sha = sha256_bytes(canonical_json(inputs))
    if not hmac.compare_digest(str(job.get("input_sha256") or ""), input_sha):
        raise ValueError("Host Runner input digest mismatch")
    plan_sha256 = str(job.get("plan_sha256") or "").lower()
    approval_ref = str(job.get("approval_ref") or "")
    if definition.get("risk") != "read_only":
        if not re.fullmatch(r"[a-f0-9]{64}", plan_sha256):
            raise ValueError("Host Runner write job plan_sha256 is invalid")
        if not approval_ref or len(approval_ref) > 191:
            raise ValueError("Host Runner write job approval_ref is required")
    elif plan_sha256 or approval_ref:
        raise ValueError("Host Runner read-only job cannot carry write approval material")

    expected_mac = job_mac(job, profile["_integrity_key"])
    supplied_mac = str(job.get("mac_sha256") or "")
    if not re.fullmatch(r"[a-f0-9]{64}", supplied_mac) or not hmac.compare_digest(supplied_mac, expected_mac):
        raise ValueError("Host Runner job integrity MAC mismatch")
    return {
        "job_id": job_id,
        "operation_id": operation_id,
        "operation_fingerprint": expected_fp,
        "executor_fingerprint": profile["executor_fingerprint"],
        "idempotency_key": idempotency_key,
        "actor_ref": actor_ref,
        "authority_ref": authority_ref,
        "input": inputs,
        "input_sha256": input_sha,
        "risk": definition.get("risk"),
        "plan_sha256": plan_sha256,
        "approval_ref": approval_ref,
    }


def zone_root(profile: dict[str, Any], zone: str) -> Path:
    root = Path(profile["wordpress_root"])
    zones = {
        "wordpress_root": root,
        "plugin_root": root / "wp-content" / "plugins" / "mad4b-site-control-plane",
        "runner_workspace": Path(profile["runner_workspace"]),
    }
    if zone not in zones:
        raise ValueError("unknown Host Runner filesystem zone")
    zone_path = zones[zone].resolve()
    if not zone_path.exists() or not _is_within(zone_path, root):
        raise ValueError("Host Runner filesystem zone is unavailable")
    return zone_path


def confined_file(profile: dict[str, Any], zone: str, relative: str) -> Path:
    if not isinstance(relative, str) or not relative or "\x00" in relative:
        raise ValueError("Host Runner relative path is invalid")
    rel = Path(relative)
    if rel.is_absolute() or ".." in rel.parts:
        raise ValueError("Host Runner path traversal denied")
    base = zone_root(profile, zone)
    candidate = base / rel
    _reject_symlink_chain(candidate, base)
    resolved = candidate.resolve(strict=True)
    if not _is_within(resolved, base):
        raise ValueError("Host Runner path escaped configured zone")
    if resolved.is_symlink() or not resolved.is_file():
        raise ValueError("Host Runner target must be a regular file")
    return resolved


def _workspace_relative(value: str) -> str:
    value = str(value or "")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,190}", value):
        raise ValueError("Host Runner workspace filename is invalid")
    return value


def plan_digest(plan: dict[str, Any]) -> str:
    material = dict(plan)
    material.pop("plan_sha256", None)
    return sha256_bytes(canonical_json(material))


def atomic_bytes_write(path: Path, raw: bytes) -> None:
    if len(raw) > MAX_WRITE_BYTES:
        raise ValueError("Host Runner write exceeds bounded byte budget")
    if path.parent.is_symlink() or not path.parent.is_dir():
        raise ValueError("Host Runner target parent is invalid")
    if path.exists() and path.is_symlink():
        raise ValueError("Host Runner target symlink is forbidden")
    tmp = path.with_name(path.name + f".tmp-{uuid.uuid4().hex}")
    with tmp.open("wb") as handle:
        handle.write(raw)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(tmp, path)
    fd = os.open(str(path.parent), os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def workspace_file_identity(path: Path) -> str:
    if not path.exists():
        return "ABSENT"
    if path.is_symlink() or not path.is_file():
        raise ValueError("Host Runner workspace target must be a regular file")
    return sha256_file(path)


def build_workspace_replace_plan(
    profile: dict[str, Any],
    relative_path: str,
    new_content: bytes,
    reason: str,
) -> dict[str, Any]:
    if "workspace.file.replace" not in profile["allowed_operations"]:
        raise ValueError("Host Runner workspace write operation is not enabled")
    relative_path = _workspace_relative(relative_path)
    if not isinstance(new_content, (bytes, bytearray)) or len(new_content) > MAX_WRITE_BYTES:
        raise ValueError("Host Runner replacement content is invalid or too large")
    reason = str(reason or "").strip()
    if len(reason) < 3 or len(reason) > 500:
        raise ValueError("Host Runner workspace write reason is invalid")
    workspace = Path(profile["runner_workspace"])
    if workspace.exists() and (workspace.is_symlink() or not workspace.is_dir()):
        raise ValueError("Host Runner workspace is invalid")
    target = workspace / relative_path
    expected_before = workspace_file_identity(target) if workspace.exists() else "ABSENT"
    plan = {
        "contract": WORKSPACE_PLAN_CONTRACT,
        "operation_id": "workspace.file.replace",
        "operation_version": OPERATIONS["workspace.file.replace"]["version"],
        "operation_fingerprint": operation_fingerprint("workspace.file.replace"),
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "executor_fingerprint": profile["executor_fingerprint"],
        "relative_path": relative_path,
        "expected_before_sha256": expected_before,
        "expected_after_sha256": sha256_bytes(bytes(new_content)),
        "byte_count": len(new_content),
        "reason": reason,
    }
    plan["plan_sha256"] = plan_digest(plan)
    return plan


def build_workspace_rollback_plan(
    profile: dict[str, Any],
    prior_receipt: dict[str, Any],
    reason: str,
) -> dict[str, Any]:
    if "workspace.file.rollback" not in profile["allowed_operations"]:
        raise ValueError("Host Runner workspace rollback operation is not enabled")
    if prior_receipt.get("contract") != RECEIPT_CONTRACT:
        raise ValueError("Host Runner prior receipt contract mismatch")
    if prior_receipt.get("operation_id") != "workspace.file.replace":
        raise ValueError("Host Runner rollback source must be workspace.file.replace")
    if prior_receipt.get("mutation_performed") is not True or prior_receipt.get("readback_verdict") != "PASS":
        raise ValueError("Host Runner rollback source receipt is not a verified mutation")
    if prior_receipt.get("profile_id") != profile["profile_id"] or prior_receipt.get("site_uuid") != profile["site_uuid"]:
        raise ValueError("Host Runner rollback source identity mismatch")
    if prior_receipt.get("target_fingerprint") != profile["target_fingerprint"]:
        raise ValueError("Host Runner rollback source target fingerprint mismatch")

    source_job_id = str(prior_receipt.get("job_id") or "")
    if not re.fullmatch(r"[a-f0-9-]{36}", source_job_id):
        raise ValueError("Host Runner rollback source job id is invalid")
    result = prior_receipt.get("result")
    if not isinstance(result, dict):
        raise ValueError("Host Runner rollback source result missing")
    relative = _workspace_relative(str(result.get("relative_path") or ""))
    before = str(result.get("before_sha256") or "")
    after = str(result.get("after_sha256") or "")
    if before != "ABSENT" and not re.fullmatch(r"[a-f0-9]{64}", before):
        raise ValueError("Host Runner rollback source before identity is invalid")
    if not re.fullmatch(r"[a-f0-9]{64}", after):
        raise ValueError("Host Runner rollback source after identity is invalid")
    reason = str(reason or "").strip()
    if len(reason) < 3 or len(reason) > 500:
        raise ValueError("Host Runner workspace rollback reason is invalid")

    workspace = Path(profile["runner_workspace"])
    target = workspace / relative
    current = workspace_file_identity(target) if workspace.exists() else "ABSENT"
    if not hmac.compare_digest(current, after):
        raise ValueError("Host Runner rollback source target no longer matches verified postcondition")

    source_snapshot = Path(profile["rollback_root"]) / f"{source_job_id}.bin"
    if before != "ABSENT":
        if source_snapshot.is_symlink() or not source_snapshot.is_file():
            raise ValueError("Host Runner rollback source snapshot is unavailable")
        if not hmac.compare_digest(sha256_file(source_snapshot), before):
            raise ValueError("Host Runner rollback source snapshot identity mismatch")

    plan = {
        "contract": WORKSPACE_ROLLBACK_PLAN_CONTRACT,
        "operation_id": "workspace.file.rollback",
        "operation_version": OPERATIONS["workspace.file.rollback"]["version"],
        "operation_fingerprint": operation_fingerprint("workspace.file.rollback"),
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "executor_fingerprint": profile["executor_fingerprint"],
        "source_job_id": source_job_id,
        "relative_path": relative,
        "expected_current_sha256": after,
        "restore_sha256": before,
        "reason": reason,
    }
    plan["plan_sha256"] = plan_digest(plan)
    return plan


def _validate_workspace_rollback_plan(
    profile: dict[str, Any],
    verified: dict[str, Any],
) -> tuple[dict[str, Any], Path, Path | None]:
    inputs = verified["input"]
    if set(inputs) != {"plan"}:
        raise ValueError("workspace.file.rollback input fields are invalid")
    plan = inputs.get("plan")
    if not isinstance(plan, dict) or plan.get("contract") != WORKSPACE_ROLLBACK_PLAN_CONTRACT:
        raise ValueError("Host Runner workspace rollback plan contract mismatch")
    supplied_plan_sha = str(plan.get("plan_sha256") or "").lower()
    if not re.fullmatch(r"[a-f0-9]{64}", supplied_plan_sha) or plan_digest(plan) != supplied_plan_sha:
        raise ValueError("Host Runner workspace rollback plan digest mismatch")
    if not hmac.compare_digest(supplied_plan_sha, verified["plan_sha256"]):
        raise ValueError("Host Runner rollback job is not bound to exact plan")
    expected = {
        "operation_id": "workspace.file.rollback",
        "operation_version": OPERATIONS["workspace.file.rollback"]["version"],
        "operation_fingerprint": operation_fingerprint("workspace.file.rollback"),
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "executor_fingerprint": profile["executor_fingerprint"],
    }
    for key, value in expected.items():
        if str(plan.get(key)) != str(value):
            raise ValueError(f"Host Runner workspace rollback plan identity drift: {key}")

    source_job_id = str(plan.get("source_job_id") or "")
    if not re.fullmatch(r"[a-f0-9-]{36}", source_job_id):
        raise ValueError("Host Runner rollback source job id is invalid")
    relative = _workspace_relative(str(plan.get("relative_path") or ""))
    expected_current = str(plan.get("expected_current_sha256") or "")
    restore = str(plan.get("restore_sha256") or "")
    if not re.fullmatch(r"[a-f0-9]{64}", expected_current):
        raise ValueError("Host Runner rollback expected current identity is invalid")
    if restore != "ABSENT" and not re.fullmatch(r"[a-f0-9]{64}", restore):
        raise ValueError("Host Runner rollback restore identity is invalid")

    workspace = Path(profile["runner_workspace"])
    target = workspace / relative
    current = workspace_file_identity(target) if workspace.exists() else "ABSENT"
    if not hmac.compare_digest(current, expected_current):
        raise ValueError("Host Runner rollback target changed since plan")
    source_snapshot = None
    if restore != "ABSENT":
        source_snapshot = Path(profile["rollback_root"]) / f"{source_job_id}.bin"
        if source_snapshot.is_symlink() or not source_snapshot.is_file():
            raise ValueError("Host Runner rollback source snapshot is unavailable")
        if not hmac.compare_digest(sha256_file(source_snapshot), restore):
            raise ValueError("Host Runner rollback source snapshot identity mismatch")
    return plan, target, source_snapshot


def execute_workspace_rollback(profile: dict[str, Any], verified: dict[str, Any]) -> dict[str, Any]:
    plan, target, source_snapshot = _validate_workspace_rollback_plan(profile, verified)
    workspace = Path(profile["runner_workspace"])
    journal_root = Path(profile["journal_root"])
    rollback_root = Path(profile["rollback_root"])
    journal_root.mkdir(parents=True, exist_ok=True)
    rollback_root.mkdir(parents=True, exist_ok=True)
    token = verified["job_id"]
    journal_path = journal_root / f"{token}.json"
    rollback_path = rollback_root / f"{token}.bin"
    if journal_path.exists() or rollback_path.exists():
        raise ValueError("Host Runner rollback evidence target already exists")

    before = str(plan["expected_current_sha256"])
    # Snapshot current post-write state so a failed rollback can itself be reversed.
    current_raw = target.read_bytes()
    atomic_bytes_write(rollback_path, current_raw)
    if not hmac.compare_digest(sha256_file(rollback_path), before):
        raise RuntimeError("Host Runner rollback-of-rollback snapshot readback failed")

    journal = {
        "contract": "mad4b.host-runner-mutation-journal.v1",
        "job_id": verified["job_id"],
        "plan_sha256": verified["plan_sha256"],
        "approval_ref": verified["approval_ref"],
        "operation_id": verified["operation_id"],
        "relative_path": plan["relative_path"],
        "before_sha256": before,
        "expected_after_sha256": plan["restore_sha256"],
        "source_job_id": plan["source_job_id"],
        "state": "MUTATION_STARTED",
        "terminal": False,
        "blind_retry_allowed": False,
        "created_at": utc_now(),
    }
    atomic_json_write(journal_path, journal)

    result = {
        "relative_path": plan["relative_path"],
        "before_sha256": before,
        "after_sha256": plan["restore_sha256"],
        "source_job_id": plan["source_job_id"],
        "plan_sha256": verified["plan_sha256"],
        "approval_ref": verified["approval_ref"],
        "rollback_available": True,
        "mutation_performed": True,
        "readback_verdict": "PENDING",
        "_target_path": str(target),
        "_rollback_path": str(rollback_path),
        "_journal_path": str(journal_path),
    }
    try:
        _reject_symlink_chain(target, workspace)
        if not hmac.compare_digest(workspace_file_identity(target), before):
            raise ValueError("Host Runner rollback target changed at commit boundary")
        if plan["restore_sha256"] == "ABSENT":
            target.unlink()
            fd = os.open(str(target.parent), os.O_RDONLY)
            try:
                os.fsync(fd)
            finally:
                os.close(fd)
        else:
            assert source_snapshot is not None
            atomic_bytes_write(target, source_snapshot.read_bytes())
        if not hmac.compare_digest(workspace_file_identity(target), str(plan["restore_sha256"])):
            raise RuntimeError("Host Runner rollback postcondition readback failed")
        result["readback_verdict"] = "PASS"
        return result
    except Exception:
        rolled_back = _rollback_workspace_replace(result)
        failure = dict(journal)
        failure.update({
            "terminal": True,
            "completed_at": utc_now(),
            "state": "ROLLED_BACK_AFTER_FAILURE" if rolled_back else "MUTATED_BUT_EVIDENCE_UNCERTAIN",
            "rollback_verified": rolled_back,
        })
        try:
            atomic_json_write(journal_path, failure)
        except Exception:
            pass
        raise


def _validate_workspace_plan(
    profile: dict[str, Any],
    verified: dict[str, Any],
) -> tuple[dict[str, Any], bytes, Path]:
    inputs = verified["input"]
    if set(inputs) != {"plan", "new_content_b64"}:
        raise ValueError("workspace.file.replace input fields are invalid")
    plan = inputs.get("plan")
    if not isinstance(plan, dict) or plan.get("contract") != WORKSPACE_PLAN_CONTRACT:
        raise ValueError("Host Runner workspace plan contract mismatch")
    supplied_plan_sha = str(plan.get("plan_sha256") or "").lower()
    if not re.fullmatch(r"[a-f0-9]{64}", supplied_plan_sha) or plan_digest(plan) != supplied_plan_sha:
        raise ValueError("Host Runner workspace plan digest mismatch")
    if not hmac.compare_digest(supplied_plan_sha, verified["plan_sha256"]):
        raise ValueError("Host Runner job is not bound to exact workspace plan")
    expected = {
        "operation_id": "workspace.file.replace",
        "operation_version": OPERATIONS["workspace.file.replace"]["version"],
        "operation_fingerprint": operation_fingerprint("workspace.file.replace"),
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "executor_fingerprint": profile["executor_fingerprint"],
    }
    for key, value in expected.items():
        if str(plan.get(key)) != str(value):
            raise ValueError(f"Host Runner workspace plan identity drift: {key}")
    relative = _workspace_relative(str(plan.get("relative_path") or ""))
    try:
        raw = base64.b64decode(str(inputs["new_content_b64"]), validate=True)
    except Exception as exc:
        raise ValueError("Host Runner replacement content is not valid base64") from exc
    if len(raw) > MAX_WRITE_BYTES or len(raw) != int(plan.get("byte_count") or -1):
        raise ValueError("Host Runner replacement content byte budget mismatch")
    if not hmac.compare_digest(sha256_bytes(raw), str(plan.get("expected_after_sha256") or "")):
        raise ValueError("Host Runner replacement content does not match plan")
    workspace = Path(profile["runner_workspace"])
    if workspace.exists() and (workspace.is_symlink() or not workspace.is_dir()):
        raise ValueError("Host Runner workspace is invalid")
    target = workspace / relative
    return plan, raw, target


def _rollback_workspace_replace(result: dict[str, Any]) -> bool:
    target = Path(result["_target_path"])
    rollback_path = Path(result["_rollback_path"]) if result.get("_rollback_path") else None
    before = str(result["before_sha256"])
    try:
        if before == "ABSENT":
            if target.exists():
                if target.is_symlink() or not target.is_file():
                    return False
                target.unlink()
                fd = os.open(str(target.parent), os.O_RDONLY)
                try:
                    os.fsync(fd)
                finally:
                    os.close(fd)
            return not target.exists()
        if rollback_path is None or rollback_path.is_symlink() or not rollback_path.is_file():
            return False
        atomic_bytes_write(target, rollback_path.read_bytes())
        return hmac.compare_digest(workspace_file_identity(target), before)
    except OSError:
        return False


def execute_workspace_replace(profile: dict[str, Any], verified: dict[str, Any]) -> dict[str, Any]:
    plan, raw, target = _validate_workspace_plan(profile, verified)
    workspace = Path(profile["runner_workspace"])
    workspace.mkdir(parents=True, exist_ok=True)
    if workspace.is_symlink() or not workspace.is_dir():
        raise ValueError("Host Runner workspace is invalid after initialization")
    _reject_symlink_chain(target, workspace)
    before = workspace_file_identity(target)
    if not hmac.compare_digest(before, str(plan.get("expected_before_sha256") or "")):
        raise ValueError("Host Runner workspace precondition changed since plan")

    journal_root = Path(profile["journal_root"])
    rollback_root = Path(profile["rollback_root"])
    journal_root.mkdir(parents=True, exist_ok=True)
    rollback_root.mkdir(parents=True, exist_ok=True)
    if journal_root.is_symlink() or rollback_root.is_symlink():
        raise ValueError("Host Runner evidence workspace symlink is forbidden")
    token = verified["job_id"]
    journal_path = journal_root / f"{token}.json"
    rollback_path = rollback_root / f"{token}.bin"
    if journal_path.exists() or rollback_path.exists():
        raise ValueError("Host Runner write evidence target already exists")

    if before != "ABSENT":
        original = target.read_bytes()
        atomic_bytes_write(rollback_path, original)
        if not hmac.compare_digest(sha256_file(rollback_path), before):
            raise RuntimeError("Host Runner rollback snapshot readback failed")

    journal = {
        "contract": "mad4b.host-runner-mutation-journal.v1",
        "job_id": verified["job_id"],
        "plan_sha256": verified["plan_sha256"],
        "approval_ref": verified["approval_ref"],
        "operation_id": verified["operation_id"],
        "relative_path": plan["relative_path"],
        "before_sha256": before,
        "expected_after_sha256": plan["expected_after_sha256"],
        "state": "MUTATION_STARTED",
        "terminal": False,
        "blind_retry_allowed": False,
        "created_at": utc_now(),
    }
    atomic_json_write(journal_path, journal)

    result = {
        "relative_path": plan["relative_path"],
        "before_sha256": before,
        "after_sha256": plan["expected_after_sha256"],
        "bytes": len(raw),
        "plan_sha256": verified["plan_sha256"],
        "approval_ref": verified["approval_ref"],
        "rollback_available": before != "ABSENT",
        "mutation_performed": True,
        "readback_verdict": "PENDING",
        "_target_path": str(target),
        "_rollback_path": str(rollback_path) if before != "ABSENT" else "",
        "_journal_path": str(journal_path),
    }
    try:
        # Immediate pre-commit path and expected-state revalidation.
        _reject_symlink_chain(target, workspace)
        if not hmac.compare_digest(workspace_file_identity(target), before):
            raise ValueError("Host Runner workspace target changed at commit boundary")
        atomic_bytes_write(target, raw)
        if not hmac.compare_digest(workspace_file_identity(target), plan["expected_after_sha256"]):
            raise RuntimeError("Host Runner workspace postcondition readback failed")
        result["readback_verdict"] = "PASS"
        return result
    except Exception:
        rolled_back = _rollback_workspace_replace(result)
        failure = dict(journal)
        failure.update({
            "terminal": True,
            "completed_at": utc_now(),
            "state": "ROLLED_BACK_AFTER_FAILURE" if rolled_back else "MUTATED_BUT_EVIDENCE_UNCERTAIN",
            "rollback_verified": rolled_back,
        })
        try:
            atomic_json_write(journal_path, failure)
        except Exception:
            pass
        raise


def plugin_tree_digest(root: Path) -> tuple[str, int]:
    rows: list[bytes] = []
    count = 0
    for path in sorted(root.rglob("*")):
        if path.is_symlink():
            raise ValueError(f"symlink forbidden in package tree: {path}")
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        raw_sha = sha256_file(path)
        rows.append(f"{rel}\0{path.stat().st_size}\0{raw_sha}\n".encode())
        count += 1
    return sha256_bytes(b"".join(rows)), count


def execute_operation(profile: dict[str, Any], verified: dict[str, Any]) -> dict[str, Any]:
    operation_id = verified["operation_id"]
    inputs = verified["input"]
    root = Path(profile["wordpress_root"])

    if operation_id == "runtime.status.read":
        if inputs:
            raise ValueError("runtime.status.read takes no input fields")
        config = root / "wp-config.php"
        return {
            "contract": "mad4b.runtime-status-read.v1",
            "operation_id": "runtime.status.read",
            "wordpress_root": str(root),
            "wp_config_present": config.is_file(),
            "wp_config_sha256": sha256_file(config) if config.is_file() else "",
            "plugin_present": (root / "wp-content/plugins/mad4b-site-control-plane").is_dir(),
            "environment": profile["environment"],
            "mutation_performed": False,
        }

    if operation_id == "filesystem.hash.read":
        allowed = {"zone", "relative_path"}
        if set(inputs) - allowed:
            raise ValueError("filesystem.hash.read contains unsupported input fields")
        zone = str(inputs.get("zone") or "")
        if zone not in OPERATIONS[operation_id]["zones"]:
            raise ValueError("filesystem.hash.read zone is not allowed")
        target = confined_file(profile, zone, str(inputs.get("relative_path") or ""))
        return {
            "zone": zone,
            "relative_path": target.relative_to(zone_root(profile, zone)).as_posix(),
            "bytes": target.stat().st_size,
            "sha256": sha256_file(target),
            "mutation_performed": False,
        }

    if operation_id == "package.integrity.verify":
        if inputs:
            raise ValueError("package.integrity.verify takes no caller-defined paths")
        plugin_root = zone_root(profile, "plugin_root")
        digest, count = plugin_tree_digest(plugin_root)
        return {
            "zone": "plugin_root",
            "tree_sha256": digest,
            "file_count": count,
            "mutation_performed": False,
        }

    if operation_id == "workspace.file.replace":
        return execute_workspace_replace(profile, verified)

    if operation_id == "workspace.file.rollback":
        return execute_workspace_rollback(profile, verified)

    raise ValueError("Host Runner operation has no implementation")


def run_job(profile_path: Path, job_path: Path) -> dict[str, Any]:
    profile = load_profile(profile_path)
    job = load_json_bounded(job_path)
    verified = verify_job(job, profile)
    receipt_root = Path(profile["receipt_root"])
    if receipt_root.exists() and receipt_root.is_symlink():
        raise ValueError("Host Runner receipt root symlink is forbidden")
    receipt_root.mkdir(parents=True, exist_ok=True)
    receipt_path = receipt_root / f"{verified['job_id']}.json"

    if receipt_path.exists():
        existing = load_json_bounded(receipt_path, MAX_RECEIPT_BYTES)
        if existing.get("contract") != RECEIPT_CONTRACT:
            raise ValueError("Host Runner existing receipt contract mismatch")
        if not hmac.compare_digest(str(existing.get("input_sha256") or ""), verified["input_sha256"]):
            raise ValueError("Host Runner job_id replayed with different input")
        if not hmac.compare_digest(str(existing.get("operation_fingerprint") or ""), verified["operation_fingerprint"]):
            raise ValueError("Host Runner job_id replayed with different operation")
        replay_bindings = {
            "profile_id": profile["profile_id"],
            "site_uuid": profile["site_uuid"],
            "target_fingerprint": profile["target_fingerprint"],
            "executor_fingerprint": profile["executor_fingerprint"],
            "idempotency_key": verified["idempotency_key"],
            "actor_ref": verified["actor_ref"],
            "authority_ref": verified["authority_ref"],
            "plan_sha256": verified["plan_sha256"],
            "approval_ref": verified["approval_ref"],
        }
        for key, expected in replay_bindings.items():
            if not hmac.compare_digest(str(existing.get(key) or ""), str(expected)):
                raise ValueError(f"Host Runner job_id replayed with different {key}")
        if existing.get("mutation_performed") is True:
            result = existing.get("result")
            if not isinstance(result, dict):
                raise ValueError("Host Runner write receipt result is missing")
            relative = _workspace_relative(str(result.get("relative_path") or ""))
            expected_after = str(result.get("after_sha256") or "")
            target = Path(profile["runner_workspace"]) / relative
            current = workspace_file_identity(target) if Path(profile["runner_workspace"]).exists() else "ABSENT"
            if not hmac.compare_digest(current, expected_after):
                raise RuntimeError("HOST_RUNNER_REPLAY_RECONCILIATION_REQUIRED")
            existing["replay_readback_verdict"] = "PASS"
        existing["replayed"] = True
        return existing

    result = execute_operation(profile, verified)
    is_write = verified["risk"] != "read_only"
    if is_write and result.get("mutation_performed") is not True:
        raise RuntimeError("Host Runner write operation did not report mutation")
    if not is_write and result.get("mutation_performed") is not False:
        raise RuntimeError("Host Runner read-only operation observed a mutation")
    receipt = {
        "contract": RECEIPT_CONTRACT,
        "runner_contract": RUNNER_CONTRACT,
        "job_id": verified["job_id"],
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "operation_id": verified["operation_id"],
        "operation_fingerprint": verified["operation_fingerprint"],
        "executor_fingerprint": verified["executor_fingerprint"],
        "runner_source_sha256": profile["runner_source_sha256"],
        "idempotency_key": verified["idempotency_key"],
        "actor_ref": verified["actor_ref"],
        "authority_ref": verified["authority_ref"],
        "plan_sha256": verified["plan_sha256"],
        "approval_ref": verified["approval_ref"],
        "input_sha256": verified["input_sha256"],
        "execution_location": "host_runner",
        "submission_location": str(job.get("submission_location") or "external_job_file"),
        "started_at": utc_now(),
        "completed_at": utc_now(),
        "result": {k: v for k, v in result.items() if not k.startswith("_")},
        "mutation_performed": bool(result.get("mutation_performed")),
        "readback_verdict": str(result.get("readback_verdict") or "PASS"),
        "replayed": False,
    }
    try:
        atomic_json_write(receipt_path, receipt)
        persisted = load_json_bounded(receipt_path, MAX_RECEIPT_BYTES)
        if persisted.get("job_id") != verified["job_id"] or persisted.get("readback_verdict") != "PASS":
            raise RuntimeError("Host Runner durable receipt readback failed")
    except Exception:
        if is_write:
            rolled_back = _rollback_workspace_replace(result)
            journal_path = Path(str(result.get("_journal_path") or ""))
            if journal_path:
                state = {
                    "contract": "mad4b.host-runner-mutation-journal.v1",
                    "job_id": verified["job_id"],
                    "plan_sha256": verified["plan_sha256"],
                    "approval_ref": verified["approval_ref"],
                    "operation_id": verified["operation_id"],
                    "relative_path": result.get("relative_path"),
                    "before_sha256": result.get("before_sha256"),
                    "expected_after_sha256": result.get("after_sha256"),
                    "state": "ROLLED_BACK_AFTER_FAILURE" if rolled_back else "MUTATED_BUT_EVIDENCE_UNCERTAIN",
                    "terminal": True,
                    "rollback_verified": rolled_back,
                    "blind_retry_allowed": False,
                    "completed_at": utc_now(),
                }
                try:
                    atomic_json_write(journal_path, state)
                except Exception:
                    pass
        raise
    if is_write:
        journal_path = Path(str(result.get("_journal_path") or ""))
        completed = {
            "contract": "mad4b.host-runner-mutation-journal.v1",
            "job_id": verified["job_id"],
            "plan_sha256": verified["plan_sha256"],
            "approval_ref": verified["approval_ref"],
            "operation_id": verified["operation_id"],
            "relative_path": result.get("relative_path"),
            "before_sha256": result.get("before_sha256"),
            "expected_after_sha256": result.get("after_sha256"),
            "state": "DURABLE_VERIFIED_RECEIPT",
            "terminal": True,
            "rollback_verified": False,
            "blind_retry_allowed": False,
            "receipt_path": str(receipt_path),
            "completed_at": utc_now(),
        }
        try:
            atomic_json_write(journal_path, completed)
        except Exception:
            receipt["journal_completion_persisted"] = False
        else:
            receipt["journal_completion_persisted"] = True
    return receipt


def reconcile(profile_path: Path) -> dict[str, Any]:
    profile = load_profile(profile_path)
    journal_root = Path(profile["journal_root"])
    receipt_root = Path(profile["receipt_root"])
    workspace = Path(profile["runner_workspace"])
    if journal_root.exists() and (journal_root.is_symlink() or not journal_root.is_dir()):
        raise ValueError("Host Runner journal root is invalid")
    if receipt_root.exists() and (receipt_root.is_symlink() or not receipt_root.is_dir()):
        raise ValueError("Host Runner receipt root is invalid")

    entries: list[dict[str, Any]] = []
    if journal_root.is_dir():
        for journal_path in sorted(journal_root.glob("*.json")):
            if journal_path.is_symlink() or not journal_path.is_file():
                continue
            journal = load_json_bounded(journal_path, MAX_RECEIPT_BYTES)
            if journal.get("contract") != "mad4b.host-runner-mutation-journal.v1":
                raise ValueError("Host Runner mutation journal contract mismatch")
            job_id = str(journal.get("job_id") or "")
            plan_sha = str(journal.get("plan_sha256") or "")
            operation_id = str(journal.get("operation_id") or "")
            relative_path = str(journal.get("relative_path") or "")
            before = str(journal.get("before_sha256") or "")
            expected_after = str(journal.get("expected_after_sha256") or "")

            receipt_present = False
            receipt_path = receipt_root / f"{job_id}.json"
            if receipt_path.is_file() and not receipt_path.is_symlink():
                receipt = load_json_bounded(receipt_path, MAX_RECEIPT_BYTES)
                receipt_present = (
                    receipt.get("contract") == RECEIPT_CONTRACT
                    and str(receipt.get("job_id") or "") == job_id
                    and hmac.compare_digest(str(receipt.get("plan_sha256") or ""), plan_sha)
                    and receipt.get("readback_verdict") == "PASS"
                )

            current_identity = ""
            if operation_id == "workspace.file.replace" and relative_path:
                relative_path = _workspace_relative(relative_path)
                target = workspace / relative_path
                if workspace.exists():
                    _reject_symlink_chain(target, workspace)
                    current_identity = workspace_file_identity(target)
                else:
                    current_identity = "ABSENT"

            if receipt_present:
                status = "DURABLE_RECEIPT_PRESENT"
                reconciliation_required = False
            elif current_identity and hmac.compare_digest(current_identity, expected_after):
                status = "RUNTIME_EFFECT_OBSERVED_NO_RECEIPT"
                reconciliation_required = True
            elif current_identity and hmac.compare_digest(current_identity, before):
                status = "ROLLED_BACK_OBSERVED_NO_RECEIPT"
                reconciliation_required = False
            else:
                status = "TARGET_STATE_DIVERGED"
                reconciliation_required = True

            entries.append({
                "job_id": job_id,
                "operation_id": operation_id,
                "relative_path": relative_path,
                "plan_sha256": plan_sha,
                "journal_state": str(journal.get("state") or ""),
                "journal_terminal": bool(journal.get("terminal")),
                "durable_receipt_present": receipt_present,
                "current_identity": current_identity,
                "before_sha256": before,
                "expected_after_sha256": expected_after,
                "reconciliation_status": status,
                "reconciliation_required": reconciliation_required,
                "blind_retry_allowed": False,
                "mutation_performed": False,
            })

    counts: dict[str, int] = {}
    for row in entries:
        status = row["reconciliation_status"]
        counts[status] = counts.get(status, 0) + 1
    return {
        "contract": "mad4b.host-runner-reconciliation.v1",
        "runner_contract": RUNNER_CONTRACT,
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "executor_fingerprint": profile["executor_fingerprint"],
        "entries": entries,
        "counts": counts,
        "reconciliation_required_count": sum(1 for row in entries if row["reconciliation_required"]),
        "blind_retry_allowed": False,
        "mutation_performed": False,
    }


def doctor(profile_path: Path) -> dict[str, Any]:
    profile = load_profile(profile_path)
    root = Path(profile["wordpress_root"])
    reconciliation = reconcile(profile_path)
    return {
        "contract": "mad4b.host-runner-doctor.v1",
        "runner_contract": RUNNER_CONTRACT,
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "runner_source_sha256": profile["runner_source_sha256"],
        "executor_fingerprint": profile["executor_fingerprint"],
        "wordpress_root_exists": root.is_dir(),
        "wp_config_present": (root / "wp-config.php").is_file(),
        "allowed_operations": profile["allowed_operations"],
        "operation_fingerprints": {
            op: operation_fingerprint(op) for op in profile["allowed_operations"]
        },
        "generic_shell_available": False,
        "write_operations_available": any(
            OPERATIONS[op].get("risk") != "read_only" for op in profile["allowed_operations"]
        ),
        "network_available_to_runner_contract": False,
        "reconciliation_required_count": reconciliation["reconciliation_required_count"],
        "reconciliation_counts": reconciliation["counts"],
        "mutation_performed": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    doctor_p = sub.add_parser("doctor")
    doctor_p.add_argument("--profile", required=True, type=Path)
    reconcile_p = sub.add_parser("reconcile")
    reconcile_p.add_argument("--profile", required=True, type=Path)
    run_p = sub.add_parser("run-job")
    run_p.add_argument("--profile", required=True, type=Path)
    run_p.add_argument("--job", required=True, type=Path)
    args = parser.parse_args()
    try:
        if args.command == "doctor":
            result = doctor(args.profile)
        elif args.command == "reconcile":
            result = reconcile(args.profile)
        else:
            result = run_job(args.profile, args.job)
    except (OSError, ValueError, RuntimeError, json.JSONDecodeError) as exc:
        print(f"HOST_RUNNER: FAIL: {exc}", file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

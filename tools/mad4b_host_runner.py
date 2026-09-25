#!/usr/bin/env python3
"""Minimal provider-neutral MAD4B Host Runner kernel.

Repository slice:
- Staging-only.
- Read-only semantic operations only.
- No shell/subprocess/network execution.
- HMAC-authenticated job envelopes.
- Exact local runner profile / target fingerprint binding.
- Named filesystem zones with canonical path confinement.
- Durable idempotent receipts.

Write operations, scheduler/bootstrap enrollment, provider CLI/API adapters, and
Production eligibility remain unavailable until separately implemented/certified.
"""

from __future__ import annotations

import argparse
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

# Fixed read-only operation registry. There is intentionally no generic command.
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
    root = Path(root_raw).expanduser().resolve()
    if not root.is_dir() or root.is_symlink():
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

    normalized = {
        "contract": PROFILE_CONTRACT,
        "profile_id": profile_id,
        "site_uuid": site_uuid,
        "environment": environment,
        "wordpress_root": str(root),
        "allowed_operations": sorted(set(allowed)),
        "integrity_key_file": str(key_file),
        "receipt_root": str(
            Path(str(profile.get("receipt_root") or (root / "wp-content/mad4b-runner/receipts")))
            .expanduser()
            .resolve()
        ),
    }
    receipt_root = Path(normalized["receipt_root"])
    expected_workspace = (root / "wp-content" / "mad4b-runner").resolve()
    if not _is_within(receipt_root, expected_workspace):
        raise ValueError("Host Runner receipt_root escaped dedicated runner workspace")
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
    if job.get("operation_version") != OPERATIONS[operation_id]["version"]:
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
    expected_mac = job_mac(job, profile["_integrity_key"])
    supplied_mac = str(job.get("mac_sha256") or "")
    if not re.fullmatch(r"[a-f0-9]{64}", supplied_mac) or not hmac.compare_digest(supplied_mac, expected_mac):
        raise ValueError("Host Runner job integrity MAC mismatch")
    return {
        "job_id": job_id,
        "operation_id": operation_id,
        "operation_fingerprint": expected_fp,
        "idempotency_key": idempotency_key,
        "actor_ref": actor_ref,
        "authority_ref": authority_ref,
        "input": inputs,
        "input_sha256": input_sha,
    }


def zone_root(profile: dict[str, Any], zone: str) -> Path:
    root = Path(profile["wordpress_root"])
    zones = {
        "wordpress_root": root,
        "plugin_root": root / "wp-content" / "plugins" / "mad4b-site-control-plane",
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
        return {
            "wordpress_root": str(root),
            "wp_config_sha256": sha256_file(root / "wp-config.php"),
            "plugin_present": (root / "wp-content/plugins/mad4b-site-control-plane").is_dir(),
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
            "idempotency_key": verified["idempotency_key"],
            "actor_ref": verified["actor_ref"],
            "authority_ref": verified["authority_ref"],
        }
        for key, expected in replay_bindings.items():
            if not hmac.compare_digest(str(existing.get(key) or ""), str(expected)):
                raise ValueError(f"Host Runner job_id replayed with different {key}")
        existing["replayed"] = True
        return existing

    result = execute_operation(profile, verified)
    if result.get("mutation_performed") is not False:
        raise RuntimeError("read-only Host Runner kernel observed a mutation")
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
        "idempotency_key": verified["idempotency_key"],
        "actor_ref": verified["actor_ref"],
        "authority_ref": verified["authority_ref"],
        "input_sha256": verified["input_sha256"],
        "execution_location": "host_runner",
        "submission_location": str(job.get("submission_location") or "external_job_file"),
        "started_at": utc_now(),
        "completed_at": utc_now(),
        "result": result,
        "mutation_performed": False,
        "readback_verdict": "PASS",
        "replayed": False,
    }
    atomic_json_write(receipt_path, receipt)
    persisted = load_json_bounded(receipt_path, MAX_RECEIPT_BYTES)
    if persisted.get("job_id") != verified["job_id"] or persisted.get("readback_verdict") != "PASS":
        raise RuntimeError("Host Runner durable receipt readback failed")
    return receipt


def doctor(profile_path: Path) -> dict[str, Any]:
    profile = load_profile(profile_path)
    root = Path(profile["wordpress_root"])
    return {
        "contract": "mad4b.host-runner-doctor.v1",
        "runner_contract": RUNNER_CONTRACT,
        "profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": profile["environment"],
        "target_fingerprint": profile["target_fingerprint"],
        "wordpress_root_exists": root.is_dir(),
        "wp_config_present": (root / "wp-config.php").is_file(),
        "allowed_operations": profile["allowed_operations"],
        "operation_fingerprints": {
            op: operation_fingerprint(op) for op in profile["allowed_operations"]
        },
        "generic_shell_available": False,
        "write_operations_available": False,
        "network_available_to_runner_contract": False,
        "mutation_performed": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    doctor_p = sub.add_parser("doctor")
    doctor_p.add_argument("--profile", required=True, type=Path)
    run_p = sub.add_parser("run-job")
    run_p.add_argument("--profile", required=True, type=Path)
    run_p.add_argument("--job", required=True, type=Path)
    args = parser.parse_args()
    try:
        result = doctor(args.profile) if args.command == "doctor" else run_job(args.profile, args.job)
    except (OSError, ValueError, RuntimeError, json.JSONDecodeError) as exc:
        print(f"HOST_RUNNER: FAIL: {exc}", file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Bounded MAD4B Host Runner bootstrap/enrollment kernel.

Repository proof only:
- Staging-only.
- Installs one exact attested runner source file into a dedicated runner zone.
- Creates one fixed scheduler descriptor (metadata only; no shell/cron mutation).
- Consumes one single-use, expiring, target-bound enrollment envelope.
- Emits durable bootstrap/enrollment receipts.
- Creates no standing Host Write/Execution or Production authority.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import shutil
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

BOOTSTRAP_PLAN_CONTRACT = "mad4b.host-runner-bootstrap-plan.v1"
RUNNER_PACKAGE_CONTRACT = "mad4b.runner-package.v1"
ENROLLMENT_ENVELOPE_CONTRACT = "mad4b.runner-enrollment-envelope.v1"
ENROLLMENT_RECORD_CONTRACT = "mad4b.runner-enrollment.v1"
BOOTSTRAP_RECEIPT_CONTRACT = "mad4b.host-runner-bootstrap-receipt.v1"
SUPPORTED_ENVIRONMENTS = {"staging"}
MAX_JSON_BYTES = 131072


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def canonical_json(value: Any) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def atomic_json_write(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    raw = (json.dumps(value, sort_keys=True, indent=2) + "\n").encode()
    if len(raw) > MAX_JSON_BYTES:
        raise ValueError("bootstrap evidence exceeds bounded size")
    tmp = path.with_name(path.name + f".tmp-{uuid.uuid4().hex}")
    with tmp.open("wb") as fh:
        fh.write(raw)
        fh.flush()
        os.fsync(fh.fileno())
    os.replace(tmp, path)
    try:
        fd = os.open(str(path.parent), os.O_RDONLY)
        os.fsync(fd)
        os.close(fd)
    except OSError:
        pass


def load_json(path: Path) -> dict[str, Any]:
    if path.is_symlink() or not path.is_file():
        raise ValueError(f"JSON source is not a regular file: {path}")
    raw = path.read_bytes()
    if len(raw) > MAX_JSON_BYTES:
        raise ValueError("JSON source exceeds bounded size")
    value = json.loads(raw.decode("utf-8"))
    if not isinstance(value, dict):
        raise ValueError("JSON source must contain an object")
    return value


def plan_digest(plan: dict[str, Any]) -> str:
    material = dict(plan)
    material.pop("plan_sha256", None)
    return sha256_bytes(canonical_json(material))


def package_identity(package: dict[str, Any], runner_source: Path) -> dict[str, Any]:
    if package.get("contract") != RUNNER_PACKAGE_CONTRACT:
        raise ValueError("RunnerPackage contract mismatch")
    version = str(package.get("version") or "")
    if not re.fullmatch(r"[A-Za-z0-9._-]{1,64}", version):
        raise ValueError("RunnerPackage version invalid")
    if package.get("entrypoint") != "mad4b_host_runner.py":
        raise ValueError("RunnerPackage entrypoint is not allowed")
    source_sha = sha256_file(runner_source)
    if not hmac.compare_digest(str(package.get("entrypoint_sha256") or ""), source_sha):
        raise ValueError("RunnerPackage entrypoint digest mismatch")
    package_sha = str(package.get("package_sha256") or "")
    package_material = {
        "contract": RUNNER_PACKAGE_CONTRACT,
        "version": version,
        "source_commit_sha": str(package.get("source_commit_sha") or ""),
        "entrypoint": "mad4b_host_runner.py",
        "entrypoint_sha256": source_sha,
        "supported_operation_contracts": package.get("supported_operation_contracts") or [],
        "runtime_profile": str(package.get("runtime_profile") or ""),
        "install_zone": "wp-content/mad4b-runner/bin",
    }
    expected_package_sha = sha256_bytes(canonical_json(package_material))
    if not hmac.compare_digest(package_sha, expected_package_sha):
        raise ValueError("RunnerPackage package identity mismatch")
    return {**package_material, "package_sha256": package_sha}


def target_identity(wordpress_root: Path, site_uuid: str, environment: str) -> dict[str, Any]:
    if environment not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("runner bootstrap is Staging-only")
    if not re.fullmatch(r"[a-f0-9-]{36}", site_uuid.lower()):
        raise ValueError("site_uuid invalid")
    root_input = wordpress_root.expanduser()
    if root_input.is_symlink():
        raise ValueError("WordPress root symlink forbidden")
    root = root_input.resolve()
    if not root.is_dir() or not (root / "wp-config.php").is_file():
        raise ValueError("WordPress root guard failed")
    return {
        "wordpress_root": str(root),
        "wp_config_sha256": sha256_file(root / "wp-config.php"),
        "site_uuid": site_uuid.lower(),
        "environment": environment,
        "target_fingerprint": sha256_bytes(canonical_json({
            "wordpress_root": str(root),
            "site_uuid": site_uuid.lower(),
            "environment": environment,
        })),
    }


def build_bootstrap_plan(
    wordpress_root: Path,
    site_uuid: str,
    environment: str,
    package: dict[str, Any],
    runner_source: Path,
    reason: str,
) -> dict[str, Any]:
    target = target_identity(wordpress_root, site_uuid, environment)
    pkg = package_identity(package, runner_source)
    reason = str(reason or "").strip()
    if len(reason) < 3 or len(reason) > 500:
        raise ValueError("bootstrap reason invalid")
    install_root = Path(target["wordpress_root"]) / "wp-content" / "mad4b-runner" / "bin" / pkg["version"]
    plan = {
        "contract": BOOTSTRAP_PLAN_CONTRACT,
        "environment": environment,
        "target": target,
        "package": pkg,
        "install_root": str(install_root),
        "scheduler_profile": {
            "mode": "fixed-entrypoint-descriptor",
            "entrypoint": str(install_root / "mad4b_host_runner.py"),
            "arbitrary_arguments_allowed": False,
            "reusable_secrets_allowed": False,
        },
        "authority_effect": {
            "host_write_granted": False,
            "host_execution_granted": False,
            "production_authorized": False,
            "raw_sql_breakglass_granted": False,
            "content_publish_granted": False,
        },
        "reason": reason,
        "created_at": utc_now(),
    }
    plan["plan_sha256"] = plan_digest(plan)
    return plan


def apply_bootstrap(plan: dict[str, Any], runner_source: Path, exact_plan_attestation: str) -> dict[str, Any]:
    if plan.get("contract") != BOOTSTRAP_PLAN_CONTRACT:
        raise ValueError("bootstrap plan contract mismatch")
    plan_sha = str(plan.get("plan_sha256") or "")
    if not re.fullmatch(r"[a-f0-9]{64}", plan_sha) or plan_digest(plan) != plan_sha:
        raise ValueError("bootstrap plan digest mismatch")
    if exact_plan_attestation.strip().lower() != plan_sha:
        raise ValueError("bootstrap attestation does not bind exact plan")
    target = plan.get("target") or {}
    current = target_identity(Path(str(target.get("wordpress_root") or "")), str(target.get("site_uuid") or ""), str(target.get("environment") or ""))
    for key in ("wordpress_root", "wp_config_sha256", "site_uuid", "environment", "target_fingerprint"):
        if current.get(key) != target.get(key):
            raise ValueError(f"bootstrap target changed since plan: {key}")

    pkg = package_identity(plan.get("package") or {}, runner_source)
    if pkg != plan.get("package"):
        raise ValueError("bootstrap package changed since plan")
    install_root = Path(str(plan.get("install_root") or ""))
    dedicated_root = Path(current["wordpress_root"]) / "wp-content" / "mad4b-runner" / "bin"
    if install_root.parent != dedicated_root or install_root.name != pkg["version"]:
        raise ValueError("bootstrap install root escaped dedicated runner zone")
    if dedicated_root.exists() and dedicated_root.is_symlink():
        raise ValueError("runner bin root symlink forbidden")
    dedicated_root.mkdir(parents=True, exist_ok=True)
    if install_root.exists():
        raise ValueError("runner version already installed")

    stage = dedicated_root / f".stage-{pkg['version']}-{uuid.uuid4().hex}"
    stage.mkdir()
    try:
        entrypoint = stage / "mad4b_host_runner.py"
        raw = runner_source.read_bytes()
        with entrypoint.open("wb") as fh:
            fh.write(raw)
            fh.flush()
            os.fsync(fh.fileno())
        if not hmac.compare_digest(sha256_file(entrypoint), pkg["entrypoint_sha256"]):
            raise RuntimeError("bootstrap installed entrypoint readback mismatch")
        manifest = {
            "contract": "mad4b.runner-install-manifest.v1",
            "package": pkg,
            "target_fingerprint": current["target_fingerprint"],
            "plan_sha256": plan_sha,
            "entrypoint_sha256": sha256_file(entrypoint),
            "installed_at": utc_now(),
        }
        atomic_json_write(stage / "RUNNER-INSTALL-MANIFEST.json", manifest)
        scheduler = {
            "contract": "mad4b.runner-scheduler-descriptor.v1",
            "mode": "fixed-entrypoint-descriptor",
            "entrypoint": str(install_root / "mad4b_host_runner.py"),
            "arguments": [],
            "arbitrary_arguments_allowed": False,
            "reusable_secrets_present": False,
            "registration_applied": False,
            "note": "descriptor only; hosting-profile scheduler registration remains a separately certified bootstrap channel",
        }
        atomic_json_write(stage / "RUNNER-SCHEDULER.json", scheduler)
        os.replace(stage, install_root)
        receipt = {
            "contract": BOOTSTRAP_RECEIPT_CONTRACT,
            "plan_sha256": plan_sha,
            "package_sha256": pkg["package_sha256"],
            "entrypoint_sha256": pkg["entrypoint_sha256"],
            "install_root": str(install_root),
            "target_fingerprint": current["target_fingerprint"],
            "scheduler_registration_applied": False,
            "bootstrap_scope_bounded": True,
            "production_authorized": False,
            "host_write_granted": False,
            "host_execution_granted": False,
            "mutation_performed": True,
            "readback_verdict": "PASS",
            "created_at": utc_now(),
        }
        atomic_json_write(install_root / "BOOTSTRAP-RECEIPT.json", receipt)
        return receipt
    except Exception:
        shutil.rmtree(stage, ignore_errors=True)
        raise


def envelope_mac(envelope: dict[str, Any], key: bytes) -> str:
    material = dict(envelope)
    material.pop("mac_sha256", None)
    return hmac.new(key, canonical_json(material), hashlib.sha256).hexdigest()


def build_enrollment_envelope(
    target: dict[str, Any],
    package_sha256: str,
    profile_id: str,
    expires_at: str,
    key: bytes,
) -> dict[str, Any]:
    if len(key) < 32:
        raise ValueError("enrollment integrity key too short")
    envelope = {
        "contract": ENROLLMENT_ENVELOPE_CONTRACT,
        "enrollment_id": str(uuid.uuid4()),
        "site_uuid": target["site_uuid"],
        "environment": target["environment"],
        "target_fingerprint": target["target_fingerprint"],
        "package_sha256": package_sha256,
        "profile_id": profile_id,
        "created_at": utc_now(),
        "expires_at": expires_at,
        "single_use": True,
    }
    envelope["mac_sha256"] = envelope_mac(envelope, key)
    return envelope


def consume_enrollment(
    wordpress_root: Path,
    envelope: dict[str, Any],
    key: bytes,
    installed_package_sha256: str,
) -> dict[str, Any]:
    if envelope.get("contract") != ENROLLMENT_ENVELOPE_CONTRACT:
        raise ValueError("enrollment envelope contract mismatch")
    if envelope.get("single_use") is not True:
        raise ValueError("enrollment envelope must be single-use")
    supplied = str(envelope.get("mac_sha256") or "")
    expected = envelope_mac(envelope, key)
    if not re.fullmatch(r"[a-f0-9]{64}", supplied) or not hmac.compare_digest(supplied, expected):
        raise ValueError("enrollment envelope integrity mismatch")
    expires = datetime.fromisoformat(str(envelope.get("expires_at") or "").replace("Z", "+00:00"))
    if expires <= datetime.now(timezone.utc):
        raise ValueError("enrollment envelope expired")
    target = target_identity(wordpress_root, str(envelope.get("site_uuid") or ""), str(envelope.get("environment") or ""))
    if target["target_fingerprint"] != envelope.get("target_fingerprint"):
        raise ValueError("enrollment target mismatch")
    if not hmac.compare_digest(str(envelope.get("package_sha256") or ""), installed_package_sha256):
        raise ValueError("enrollment package mismatch")

    root = Path(target["wordpress_root"]) / "wp-content" / "mad4b-runner" / "enrollment"
    if root.exists() and root.is_symlink():
        raise ValueError("enrollment root symlink forbidden")
    root.mkdir(parents=True, exist_ok=True)
    enrollment_id = str(envelope.get("enrollment_id") or "")
    if not re.fullmatch(r"[a-f0-9-]{36}", enrollment_id):
        raise ValueError("enrollment_id invalid")
    consumed = root / f"{enrollment_id}.consumed.json"
    if consumed.exists():
        raise ValueError("enrollment envelope replay denied")

    record = {
        "contract": ENROLLMENT_RECORD_CONTRACT,
        "enrollment_id": enrollment_id,
        "site_uuid": target["site_uuid"],
        "environment": target["environment"],
        "target_fingerprint": target["target_fingerprint"],
        "package_sha256": installed_package_sha256,
        "profile_id": str(envelope.get("profile_id") or ""),
        "enrolled_at": utc_now(),
        "bootstrap_credential_consumed": True,
        "write_eligible": False,
        "host_execution_authority_granted": False,
        "production_authorized": False,
        "mutation_performed": True,
    }
    atomic_json_write(consumed, record)
    return record


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)

    plan_p = sub.add_parser("plan")
    plan_p.add_argument("--wordpress-root", required=True, type=Path)
    plan_p.add_argument("--site-uuid", required=True)
    plan_p.add_argument("--environment", required=True, choices=sorted(SUPPORTED_ENVIRONMENTS))
    plan_p.add_argument("--package", required=True, type=Path)
    plan_p.add_argument("--runner-source", required=True, type=Path)
    plan_p.add_argument("--reason", required=True)

    apply_p = sub.add_parser("apply")
    apply_p.add_argument("--plan", required=True, type=Path)
    apply_p.add_argument("--runner-source", required=True, type=Path)
    apply_p.add_argument("--exact-plan-attestation", required=True)

    args = parser.parse_args()
    try:
        if args.command == "plan":
            result = build_bootstrap_plan(
                args.wordpress_root,
                args.site_uuid,
                args.environment,
                load_json(args.package),
                args.runner_source,
                args.reason,
            )
        else:
            result = apply_bootstrap(load_json(args.plan), args.runner_source, args.exact_plan_attestation)
    except (OSError, ValueError, RuntimeError, json.JSONDecodeError) as exc:
        print(f"HOST_RUNNER_BOOTSTRAP: FAIL: {exc}", file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Minimal out-of-band recovery plane for the MAD4B Control Plane.

This tool never loads WordPress and never touches the database. It is deliberately
limited to Staging restoration of the fixed mad4b-site-control-plane plugin from
an externally attested known-good distribution artifact.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import sys
import uuid
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import verify_release_root_trust as root_trust

PLUGIN_SLUG = "mad4b-site-control-plane"
PLAN_CONTRACT = "mad4b.recovery-plan.v1"
RECEIPT_CONTRACT = "mad4b.recovery-receipt.v1"
SUPPORTED_ENVIRONMENTS = {"staging"}


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def canonical_json(data: dict[str, Any]) -> bytes:
    return json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()


def plan_digest(plan: dict[str, Any]) -> str:
    material = dict(plan)
    material.pop("plan_sha256", None)
    return hashlib.sha256(canonical_json(material)).hexdigest()


def tree_digest(root: Path) -> str:
    if not root.exists():
        return ""
    if not root.is_dir():
        raise ValueError(f"expected directory: {root}")
    rows: list[bytes] = []
    for path in sorted(root.rglob("*")):
        if path.is_symlink():
            raise ValueError(f"symlink forbidden in recovery target: {path}")
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        raw = path.read_bytes()
        digest = hashlib.sha256(raw).hexdigest()
        rows.append(f"{rel}\0{len(raw)}\0{digest}\n".encode())
    return hashlib.sha256(b"".join(rows)).hexdigest()


def require_incident_id(value: str) -> str:
    value = value.strip()
    if not re.fullmatch(r"[A-Za-z0-9._-]{3,120}", value):
        raise ValueError("incident id must match [A-Za-z0-9._-]{3,120}")
    return value


def require_reason(value: str) -> str:
    value = value.strip()
    if not 3 <= len(value) <= 500:
        raise ValueError("reason must contain 3..500 characters")
    return value


def target_state(wordpress_root: Path) -> dict[str, Any]:
    root = wordpress_root.expanduser().resolve()
    config = root / "wp-config.php"
    plugins = root / "wp-content" / "plugins"
    plugin = plugins / PLUGIN_SLUG
    if not root.is_dir() or not config.is_file() or not plugins.is_dir():
        raise ValueError("WordPress root guard failed")
    return {
        "wordpress_root": str(root),
        "wp_config_sha256": root_trust.sha256_file(config),
        "plugin_path": str(plugin),
        "plugin_present": plugin.is_dir(),
        "plugin_tree_sha256": tree_digest(plugin) if plugin.is_dir() else "",
    }


def verify_installed_provenance(plugin_dir: Path) -> dict[str, str]:
    provenance_path = plugin_dir / "MAD4B-BUILD-PROVENANCE.json"
    provenance = root_trust.load_json(provenance_path)
    if provenance.get("contract") != root_trust.PROVENANCE_CONTRACT:
        raise ValueError("installed build provenance contract mismatch")
    package_files = provenance.get("package_files")
    if not isinstance(package_files, list) or not package_files:
        raise ValueError("installed build provenance package_files missing")

    rows: list[bytes] = []
    for entry in package_files:
        if not isinstance(entry, dict):
            raise ValueError("invalid installed provenance row")
        relative = str(entry.get("path", ""))
        parts = Path(relative).parts
        if not relative or relative.startswith("/") or ".." in parts:
            raise ValueError(f"unsafe installed provenance path: {relative!r}")
        expected_bytes = int(entry.get("bytes", -1))
        expected_sha = str(entry.get("sha256", "")).lower()
        if not re.fullmatch(r"[0-9a-f]{64}", expected_sha):
            raise ValueError(f"invalid installed provenance digest: {relative}")
        path = plugin_dir / relative
        if path.is_symlink() or not path.is_file():
            raise ValueError(f"installed provenance file missing: {relative}")
        raw = path.read_bytes()
        actual_sha = hashlib.sha256(raw).hexdigest()
        if len(raw) != expected_bytes or actual_sha != expected_sha:
            raise ValueError(f"installed provenance mismatch: {relative}")
        rows.append(f"{relative}\0{len(raw)}\0{actual_sha}\n".encode())

    digest = hashlib.sha256(b"".join(sorted(rows))).hexdigest()
    expected_digest = str(provenance.get("package_manifest_digest", "")).lower()
    if digest != expected_digest:
        raise ValueError("installed package manifest digest mismatch")
    return {
        "source_commit_sha": str(provenance.get("source_commit_sha", "")).lower(),
        "build_fingerprint": str(provenance.get("build_fingerprint", "")).lower(),
        "package_manifest_digest": expected_digest,
    }


def assert_verified_root_receipt(root_receipt: dict[str, Any]) -> None:
    if not root_receipt.get("verified") or not root_receipt.get("attestation_verified"):
        raise ValueError("known-good target lacks verified release root trust")
    if root_receipt.get("runtime_self_attestation_authoritative") is not False:
        raise ValueError("runtime self-attestation cannot authorize Recovery Plane")
    if root_receipt.get("trusted_signer_ref") != root_trust.TRUSTED_SIGNER_REF:
        raise ValueError("known-good target was not signed from the trusted release ref")
    if root_receipt.get("trusted_signer_event") not in root_trust.TRUSTED_SIGNER_EVENTS:
        raise ValueError("known-good target signer event is not trusted")
    if root_receipt.get("trusted_runner_environment") != "github-hosted":
        raise ValueError("known-good target signer runner is not trusted")


def build_restore_plan(
    wordpress_root: Path,
    environment: str,
    incident_id: str,
    reason: str,
    root_receipt: dict[str, Any],
) -> dict[str, Any]:
    if environment not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("Recovery Plane is Staging-only")
    assert_verified_root_receipt(root_receipt)
    state = target_state(wordpress_root)
    plan: dict[str, Any] = {
        "contract": PLAN_CONTRACT,
        "action": "restore_known_good",
        "environment": environment,
        "incident_id": require_incident_id(incident_id),
        "reason": require_reason(reason),
        "created_at": utc_now(),
        "target": state,
        "known_good": {
            "repository": root_receipt.get("repository"),
            "source_commit_sha": root_receipt.get("source_commit_sha"),
            "artifact_sha256": root_receipt.get("artifact_sha256"),
            "build_fingerprint": root_receipt.get("build_fingerprint"),
            "package_manifest_digest": root_receipt.get("package_manifest_digest"),
            "control_plane_version": root_receipt.get("control_plane_version"),
            "signer_workflow": root_receipt.get("signer_workflow"),
            "signer_digest": root_receipt.get("signer_digest"),
        },
        "authorization": {
            "mode": "SINGLE_OWNER_HARDENED",
            "exact_plan_attestation_required": True,
            "production_authorized": False,
            "breakglass_authority_created": False,
        },
        "postconditions": {
            "installed_provenance_exact": True,
            "normal_control_reverification_required": True,
        },
    }
    plan["plan_sha256"] = plan_digest(plan)
    return plan


def assert_plan_current(plan: dict[str, Any]) -> Path:
    if plan.get("contract") != PLAN_CONTRACT or plan.get("action") != "restore_known_good":
        raise ValueError("recovery plan contract/action mismatch")
    if plan.get("environment") not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("recovery plan environment is not allowed")
    expected_sha = str(plan.get("plan_sha256", "")).lower()
    if not re.fullmatch(r"[0-9a-f]{64}", expected_sha) or plan_digest(plan) != expected_sha:
        raise ValueError("recovery plan digest mismatch")

    target = plan.get("target")
    if not isinstance(target, dict):
        raise ValueError("recovery target missing")
    root = Path(str(target.get("wordpress_root", ""))).resolve()
    current = target_state(root)
    for key in ("wordpress_root", "wp_config_sha256", "plugin_present", "plugin_tree_sha256"):
        if current.get(key) != target.get(key):
            raise ValueError(f"recovery target changed since plan: {key}")
    return root


def safe_extract_control_plane(artifact: Path, destination: Path) -> Path:
    if destination.exists():
        raise ValueError(f"recovery staging directory already exists: {destination}")
    destination.mkdir(parents=True)
    plugin_root = destination / PLUGIN_SLUG
    try:
        with zipfile.ZipFile(artifact, "r") as archive:
            members = archive.infolist()
            if not members:
                raise ValueError("known-good artifact is empty")
            for member in members:
                name = member.filename.replace("\\", "/")
                pure = Path(name)
                if name.startswith("/") or ".." in pure.parts:
                    raise ValueError(f"unsafe archive path: {name}")
                if not (name == PLUGIN_SLUG or name.startswith(PLUGIN_SLUG + "/")):
                    raise ValueError(f"unexpected archive root: {name}")
                mode = (member.external_attr >> 16) & 0xFFFF
                if mode and stat.S_ISLNK(mode):
                    raise ValueError(f"symlink forbidden in recovery artifact: {name}")
                out = destination / pure
                if member.is_dir():
                    out.mkdir(parents=True, exist_ok=True)
                    continue
                out.parent.mkdir(parents=True, exist_ok=True)
                with archive.open(member, "r") as src, out.open("wb") as dst:
                    shutil.copyfileobj(src, dst)
        if not plugin_root.is_dir():
            raise ValueError("recovery artifact did not produce control-plane plugin root")
        return plugin_root
    except Exception:
        shutil.rmtree(destination, ignore_errors=True)
        raise


def apply_restore(
    plan: dict[str, Any],
    artifact: Path,
    root_receipt: dict[str, Any],
    owner_attest_plan_sha: str,
) -> dict[str, Any]:
    root = assert_plan_current(plan)
    assert_verified_root_receipt(root_receipt)
    plan_sha = str(plan["plan_sha256"])
    if owner_attest_plan_sha.strip().lower() != plan_sha:
        raise ValueError("OWNER_ATTEST_SINGLE_OWNER does not bind the exact recovery plan")

    known_good = plan.get("known_good")
    if not isinstance(known_good, dict):
        raise ValueError("known-good plan identity missing")
    for key in ("source_commit_sha", "artifact_sha256", "build_fingerprint", "package_manifest_digest", "signer_workflow", "signer_digest"):
        if str(root_receipt.get(key, "")) != str(known_good.get(key, "")):
            raise ValueError(f"root-trust receipt drift since plan: {key}")
    if root_trust.sha256_file(artifact) != str(known_good.get("artifact_sha256", "")):
        raise ValueError("known-good artifact digest changed since plan")

    plugins = root / "wp-content" / "plugins"
    live = plugins / PLUGIN_SLUG
    recovery_root = root / "wp-content" / "mad4b-recovery"
    quarantine_root = recovery_root / "quarantine"
    receipt_root = recovery_root / "receipts"
    quarantine_root.mkdir(parents=True, exist_ok=True)
    receipt_root.mkdir(parents=True, exist_ok=True)

    token = f"{require_incident_id(str(plan['incident_id']))}-{plan_sha[:12]}"
    stage = plugins / f".{PLUGIN_SLUG}.recovery-stage-{plan_sha[:12]}"
    quarantine = quarantine_root / token
    if quarantine.exists():
        raise ValueError("recovery quarantine target already exists")
    staged_plugin = safe_extract_control_plane(artifact, stage)

    expected = {
        "source_commit_sha": str(known_good.get("source_commit_sha", "")),
        "build_fingerprint": str(known_good.get("build_fingerprint", "")),
        "package_manifest_digest": str(known_good.get("package_manifest_digest", "")),
    }
    staged_identity = verify_installed_provenance(staged_plugin)
    if staged_identity != expected:
        shutil.rmtree(stage, ignore_errors=True)
        raise ValueError("staged known-good provenance does not match recovery plan")

    previous_present = live.is_dir()
    moved_previous = False
    try:
        if previous_present:
            os.replace(live, quarantine)
            moved_previous = True
        os.replace(staged_plugin, live)
        try:
            stage.rmdir()
        except OSError:
            pass

        installed = verify_installed_provenance(live)
        if installed != expected:
            raise ValueError("post-recovery installed provenance mismatch")
    except Exception:
        if live.exists():
            shutil.rmtree(live, ignore_errors=True)
        if moved_previous and quarantine.exists():
            os.replace(quarantine, live)
        shutil.rmtree(stage, ignore_errors=True)
        raise

    receipt = {
        "contract": RECEIPT_CONTRACT,
        "action": "restore_known_good",
        "environment": plan["environment"],
        "incident_id": plan["incident_id"],
        "reason": plan["reason"],
        "plan_sha256": plan_sha,
        "owner_attest_plan_sha256": plan_sha,
        "applied_at": utc_now(),
        "target": {
            "wordpress_root": str(root),
            "wp_config_sha256": root_trust.sha256_file(root / "wp-config.php"),
        },
        "previous": {
            "present": previous_present,
            "tree_sha256": plan["target"]["plugin_tree_sha256"],
            "quarantine_path": str(quarantine.relative_to(root)) if moved_previous else "",
        },
        "known_good": known_good,
        "post_recovery": {
            **installed,
            "plugin_tree_sha256": tree_digest(live),
            "normal_control_reverification_required": True,
            "production_authorized": False,
        },
        "root_trust_verification": {
            "contract": root_receipt.get("contract"),
            "attestation_verified": bool(root_receipt.get("attestation_verified")),
            "verification_boundary": root_receipt.get("verification_boundary"),
            "verified_attestation_count": root_receipt.get("verified_attestation_count"),
        },
    }
    receipt_path = receipt_root / f"{token}.json"
    receipt_path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    receipt["receipt_path"] = str(receipt_path)
    return receipt


def verify_known_good(args: argparse.Namespace) -> dict[str, Any]:
    return root_trust.verify(
        args.artifact,
        args.bundle,
        args.install_manifest,
        args.expected_source_sha,
        args.trusted_signer_digest,
    )


def add_known_good_args(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--artifact", required=True, type=Path)
    parser.add_argument("--bundle", required=True, type=Path)
    parser.add_argument("--install-manifest", required=True, type=Path)
    parser.add_argument("--expected-source-sha", required=True)
    parser.add_argument("--trusted-signer-digest", required=True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)

    status_p = sub.add_parser("status")
    status_p.add_argument("--wordpress-root", required=True, type=Path)
    status_p.add_argument("--environment", required=True, choices=sorted(SUPPORTED_ENVIRONMENTS))

    plan_p = sub.add_parser("plan-restore")
    plan_p.add_argument("--wordpress-root", required=True, type=Path)
    plan_p.add_argument("--environment", required=True, choices=sorted(SUPPORTED_ENVIRONMENTS))
    plan_p.add_argument("--incident-id", required=True)
    plan_p.add_argument("--reason", required=True)
    plan_p.add_argument("--output", required=True, type=Path)
    add_known_good_args(plan_p)

    apply_p = sub.add_parser("apply-restore")
    apply_p.add_argument("--plan", required=True, type=Path)
    apply_p.add_argument("--owner-attest-plan-sha", required=True)
    add_known_good_args(apply_p)

    args = parser.parse_args()
    try:
        if args.command == "status":
            result = {
                "contract": "mad4b.recovery-status.v1",
                "environment": args.environment,
                "read_only": True,
                "target": target_state(args.wordpress_root),
            }
        elif args.command == "plan-restore":
            root_receipt = verify_known_good(args)
            result = build_restore_plan(
                args.wordpress_root,
                args.environment,
                args.incident_id,
                args.reason,
                root_receipt,
            )
            args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        else:
            plan = root_trust.load_json(args.plan)
            root_receipt = verify_known_good(args)
            result = apply_restore(
                plan,
                args.artifact,
                root_receipt,
                args.owner_attest_plan_sha,
            )
    except (ValueError, RuntimeError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"RECOVERY_PLANE: FAIL: {exc}", file=sys.stderr)
        return 1

    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

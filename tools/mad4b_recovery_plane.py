#!/usr/bin/env python3
"""Minimal out-of-band recovery plane for the MAD4B Control Plane.

This tool never loads WordPress and never touches the database. It is deliberately
limited to Staging restoration of the fixed mad4b-site-control-plane plugin from
an externally attested known-good distribution artifact.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
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
DISABLE_PLAN_CONTRACT = "mad4b.recovery-disable-plan.v1"
DISABLE_RECEIPT_CONTRACT = "mad4b.recovery-disable-receipt.v1"
BACKUP_PLAN_CONTRACT = "mad4b.protected-backup-plan.v1"
BACKUP_RECEIPT_CONTRACT = "mad4b.protected-backup-receipt.v1"
BACKUP_RESTORE_PLAN_CONTRACT = "mad4b.protected-backup-restore-plan.v1"
BACKUP_RESTORE_RECEIPT_CONTRACT = "mad4b.protected-backup-restore-receipt.v1"
SUPPORTED_ENVIRONMENTS = {"staging"}


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def canonical_json(data: dict[str, Any]) -> bytes:
    return json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()


def atomic_json_write(path: Path, data: dict[str, Any]) -> None:
    """Durably replace one JSON evidence file without exposing a partial receipt."""
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + f".tmp-{uuid.uuid4().hex}")
    payload = json.dumps(data, indent=2, sort_keys=True) + "\n"
    with tmp.open("w", encoding="utf-8") as handle:
        handle.write(payload)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(tmp, path)
    try:
        directory_fd = os.open(str(path.parent), os.O_RDONLY)
    except OSError:
        return
    try:
        os.fsync(directory_fd)
    finally:
        os.close(directory_fd)


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


def protected_backup_status(wordpress_root: Path) -> dict[str, Any]:
    root = wordpress_root.expanduser().resolve()
    backup_root = root / "wp-content" / "mad4b-recovery" / "protected-backups"
    symlink = backup_root.is_symlink()
    exists = backup_root.exists()
    is_dir = backup_root.is_dir() if exists and not symlink else False
    writable = bool(is_dir and os.access(backup_root, os.W_OK))
    receipts = []
    verified_count = 0
    if is_dir:
        for receipt_path in sorted(backup_root.glob("*/BACKUP-RECEIPT.json")):
            if receipt_path.is_symlink() or not receipt_path.is_file():
                continue
            try:
                row = json.loads(receipt_path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                continue
            if row.get("contract") != BACKUP_RECEIPT_CONTRACT:
                continue
            backup_id = str(row.get("backup_id") or "")
            verified = False
            verification_error = ""
            try:
                verification = verify_protected_backup(root, backup_id)
                verified = verification.get("verified") is True
            except (OSError, ValueError, RuntimeError, json.JSONDecodeError) as exc:
                verification_error = str(exc)
            if verified:
                verified_count += 1
            receipts.append({
                "backup_id": backup_id,
                "plan_sha256": row.get("plan_sha256"),
                "plugin_tree_sha256": row.get("plugin_tree_sha256"),
                "created_at": row.get("created_at"),
                "receipt_path": str(receipt_path),
                "verified": verified,
                "verification_error": verification_error,
            })
    ready = bool(is_dir and writable and not symlink and verified_count > 0)
    return {
        "contract": "mad4b.protected-backup-status.v2",
        "path": str(backup_root),
        "exists": exists,
        "is_directory": is_dir,
        "symlink": symlink,
        "writable": writable,
        "ready": ready,
        "backup_count": len(receipts),
        "verified_backup_count": verified_count,
        "backups": receipts[-20:],
        "requires_preparation": not ready,
        "mutation_performed": False,
    }


def build_backup_plan(
    wordpress_root: Path,
    environment: str,
    incident_id: str,
    reason: str,
) -> dict[str, Any]:
    if environment not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("Protected backup is Staging-only")
    state = target_state(wordpress_root)
    if not state["plugin_present"] or not state["plugin_tree_sha256"]:
        raise ValueError("Control Plane plugin is absent; protected backup has no source")
    plan = {
        "contract": BACKUP_PLAN_CONTRACT,
        "action": "create_control_plane_backup",
        "environment": environment,
        "incident_id": require_incident_id(incident_id),
        "reason": require_reason(reason),
        "created_at": utc_now(),
        "target": state,
        "scope": {
            "plugin_slug": PLUGIN_SLUG,
            "copies_wp_config_bytes": False,
            "copies_database": False,
            "arbitrary_paths_allowed": False,
        },
        "authorization": {
            "mode": "SINGLE_OWNER_HARDENED",
            "exact_plan_attestation_required": True,
            "production_authorized": False,
            "breakglass_authority_created": False,
        },
    }
    plan["plan_sha256"] = plan_digest(plan)
    return plan


def assert_backup_plan_current(plan: dict[str, Any]) -> Path:
    if plan.get("contract") != BACKUP_PLAN_CONTRACT or plan.get("action") != "create_control_plane_backup":
        raise ValueError("protected backup plan contract/action mismatch")
    if plan.get("environment") not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("protected backup environment is not allowed")
    expected_sha = str(plan.get("plan_sha256", "")).lower()
    if not re.fullmatch(r"[0-9a-f]{64}", expected_sha) or plan_digest(plan) != expected_sha:
        raise ValueError("protected backup plan digest mismatch")
    scope = plan.get("scope")
    if not isinstance(scope, dict) or scope.get("plugin_slug") != PLUGIN_SLUG:
        raise ValueError("protected backup scope mismatch")
    if scope.get("copies_wp_config_bytes") is not False or scope.get("copies_database") is not False:
        raise ValueError("protected backup scope widened beyond Control Plane package")
    root = Path(str((plan.get("target") or {}).get("wordpress_root", ""))).resolve()
    current = target_state(root)
    target = plan.get("target") or {}
    for key in ("wordpress_root", "wp_config_sha256", "plugin_present", "plugin_tree_sha256"):
        if current.get(key) != target.get(key):
            raise ValueError(f"protected backup target changed since plan: {key}")
    return root


def _copy_plugin_snapshot(source: Path, destination: Path) -> list[dict[str, Any]]:
    if not source.is_dir() or source.is_symlink():
        raise ValueError("protected backup source plugin directory is invalid")
    destination.mkdir(parents=True, exist_ok=False)
    rows = []
    for path in sorted(source.rglob("*")):
        if path.is_symlink():
            raise ValueError(f"symlink forbidden in protected backup source: {path}")
        rel = path.relative_to(source)
        out = destination / rel
        if path.is_dir():
            out.mkdir(parents=True, exist_ok=True)
            continue
        if not path.is_file():
            raise ValueError(f"unsupported protected backup source entry: {path}")
        out.parent.mkdir(parents=True, exist_ok=True)
        raw = path.read_bytes()
        with out.open("wb") as handle:
            handle.write(raw)
            handle.flush()
            os.fsync(handle.fileno())
        rows.append({
            "path": rel.as_posix(),
            "bytes": len(raw),
            "sha256": hashlib.sha256(raw).hexdigest(),
        })
    return rows


def apply_backup(plan: dict[str, Any], owner_attest_plan_sha: str) -> dict[str, Any]:
    root = assert_backup_plan_current(plan)
    plan_sha = str(plan["plan_sha256"])
    if owner_attest_plan_sha.strip().lower() != plan_sha:
        raise ValueError("OWNER_ATTEST_SINGLE_OWNER does not bind the exact protected backup plan")

    recovery_root = root / "wp-content" / "mad4b-recovery"
    if recovery_root.is_symlink():
        raise ValueError("recovery root symlink is forbidden")
    backup_root = recovery_root / "protected-backups"
    if backup_root.exists() and backup_root.is_symlink():
        raise ValueError("protected backup root symlink is forbidden")
    backup_root.mkdir(parents=True, exist_ok=True)
    if not backup_root.is_dir() or not os.access(backup_root, os.W_OK):
        raise ValueError("protected backup root is not writable")

    backup_id = f"{require_incident_id(str(plan['incident_id']))}-{plan_sha[:12]}"
    stage = backup_root / f".stage-{backup_id}"
    final = backup_root / backup_id
    if stage.exists() or final.exists():
        raise ValueError("protected backup target already exists")

    source = root / "wp-content" / "plugins" / PLUGIN_SLUG
    stage.mkdir(parents=False, exist_ok=False)
    try:
        snapshot = stage / PLUGIN_SLUG
        rows = _copy_plugin_snapshot(source, snapshot)
        copied_tree = tree_digest(snapshot)
        planned_tree = str(plan["target"]["plugin_tree_sha256"])
        if not hmac.compare_digest(copied_tree, planned_tree):
            raise ValueError("protected backup tree differs from exact planned runtime")

        manifest_material = [
            f"{row['path']}\0{row['bytes']}\0{row['sha256']}\n".encode()
            for row in rows
        ]
        manifest_digest = hashlib.sha256(b"".join(manifest_material)).hexdigest()
        manifest = {
            "contract": "mad4b.protected-backup-manifest.v1",
            "backup_id": backup_id,
            "source_plugin_tree_sha256": planned_tree,
            "file_count": len(rows),
            "manifest_sha256": manifest_digest,
            "files": rows,
        }
        atomic_json_write(stage / "BACKUP-MANIFEST.json", manifest)
        receipt = {
            "contract": BACKUP_RECEIPT_CONTRACT,
            "backup_id": backup_id,
            "environment": plan["environment"],
            "incident_id": plan["incident_id"],
            "reason": plan["reason"],
            "plan_sha256": plan_sha,
            "owner_attest_plan_sha256": plan_sha,
            "created_at": utc_now(),
            "wordpress_root": str(root),
            "wp_config_sha256": plan["target"]["wp_config_sha256"],
            "plugin_slug": PLUGIN_SLUG,
            "plugin_tree_sha256": planned_tree,
            "backup_manifest_sha256": manifest_digest,
            "backup_file_count": len(rows),
            "production_authorized": False,
            "database_copied": False,
            "wp_config_bytes_copied": False,
            "mutation_performed": True,
        }
        atomic_json_write(stage / "BACKUP-RECEIPT.json", receipt)
        os.replace(stage, final)
        try:
            directory_fd = os.open(str(backup_root), os.O_RDONLY)
            os.fsync(directory_fd)
            os.close(directory_fd)
        except OSError:
            pass

        # Post-commit readback over copied bytes and durable receipt.
        if tree_digest(final / PLUGIN_SLUG) != planned_tree:
            raise RuntimeError("protected backup post-commit tree readback failed")
        persisted = json.loads((final / "BACKUP-RECEIPT.json").read_text(encoding="utf-8"))
        if persisted.get("plan_sha256") != plan_sha or persisted.get("plugin_tree_sha256") != planned_tree:
            raise RuntimeError("protected backup receipt readback failed")
        receipt["backup_path"] = str(final)
        receipt["readback_verified"] = True
        return receipt
    except Exception:
        shutil.rmtree(stage, ignore_errors=True)
        # If final exists, do not silently delete a possibly-valid committed backup.
        # Surface recovery/reconciliation instead.
        if final.exists():
            raise RuntimeError("PROTECTED_BACKUP_COMMITTED_BUT_READBACK_UNCERTAIN")
        raise


def verify_protected_backup(wordpress_root: Path, backup_id: str) -> dict[str, Any]:
    root = wordpress_root.expanduser().resolve()
    backup_id = str(backup_id or "")
    if not re.fullmatch(r"[A-Za-z0-9._-]{3,220}", backup_id):
        raise ValueError("protected backup_id is invalid")
    backup_root = root / "wp-content" / "mad4b-recovery" / "protected-backups"
    if backup_root.is_symlink() or not backup_root.is_dir():
        raise ValueError("protected backup root is unavailable")
    backup = backup_root / backup_id
    if backup.is_symlink() or not backup.is_dir():
        raise ValueError("protected backup directory is invalid")
    if backup.parent.resolve() != backup_root.resolve():
        raise ValueError("protected backup escaped backup root")

    receipt_path = backup / "BACKUP-RECEIPT.json"
    manifest_path = backup / "BACKUP-MANIFEST.json"
    snapshot = backup / PLUGIN_SLUG
    if receipt_path.is_symlink() or manifest_path.is_symlink() or snapshot.is_symlink():
        raise ValueError("protected backup metadata or snapshot symlink is forbidden")
    if not receipt_path.is_file() or not manifest_path.is_file() or not snapshot.is_dir():
        raise ValueError("protected backup is incomplete")

    receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if receipt.get("contract") != BACKUP_RECEIPT_CONTRACT:
        raise ValueError("protected backup receipt contract mismatch")
    if manifest.get("contract") != "mad4b.protected-backup-manifest.v1":
        raise ValueError("protected backup manifest contract mismatch")
    if receipt.get("backup_id") != backup_id or manifest.get("backup_id") != backup_id:
        raise ValueError("protected backup identity mismatch")
    if receipt.get("wordpress_root") != str(root):
        raise ValueError("protected backup target root mismatch")
    current_wp_config = root_trust.sha256_file(root / "wp-config.php")
    if not hmac.compare_digest(str(receipt.get("wp_config_sha256") or ""), current_wp_config):
        raise ValueError("protected backup wp-config target binding drift")

    rows: list[dict[str, Any]] = []
    for file_path in sorted(snapshot.rglob("*")):
        if file_path.is_symlink():
            raise ValueError(f"symlink forbidden in protected backup snapshot: {file_path}")
        if not file_path.is_file():
            continue
        rel = file_path.relative_to(snapshot).as_posix()
        raw = file_path.read_bytes()
        rows.append({
            "path": rel,
            "bytes": len(raw),
            "sha256": hashlib.sha256(raw).hexdigest(),
        })
    expected_rows = manifest.get("files")
    if not isinstance(expected_rows, list) or expected_rows != rows:
        raise ValueError("protected backup manifest file inventory mismatch")
    manifest_material = [
        f"{row['path']}\0{row['bytes']}\0{row['sha256']}\n".encode()
        for row in rows
    ]
    manifest_sha = hashlib.sha256(b"".join(manifest_material)).hexdigest()
    if not hmac.compare_digest(manifest_sha, str(manifest.get("manifest_sha256") or "")):
        raise ValueError("protected backup manifest digest mismatch")
    if not hmac.compare_digest(manifest_sha, str(receipt.get("backup_manifest_sha256") or "")):
        raise ValueError("protected backup receipt manifest digest mismatch")
    tree_sha = tree_digest(snapshot)
    expected_tree = str(receipt.get("plugin_tree_sha256") or "")
    if not hmac.compare_digest(tree_sha, expected_tree):
        raise ValueError("protected backup plugin tree digest mismatch")
    if not hmac.compare_digest(tree_sha, str(manifest.get("source_plugin_tree_sha256") or "")):
        raise ValueError("protected backup manifest tree digest mismatch")
    if int(receipt.get("backup_file_count") or -1) != len(rows) or int(manifest.get("file_count") or -1) != len(rows):
        raise ValueError("protected backup file count mismatch")

    return {
        "contract": "mad4b.protected-backup-verification.v1",
        "verified": True,
        "backup_id": backup_id,
        "backup_path": str(backup),
        "snapshot_path": str(snapshot),
        "wordpress_root": str(root),
        "wp_config_sha256": current_wp_config,
        "plugin_tree_sha256": tree_sha,
        "backup_manifest_sha256": manifest_sha,
        "file_count": len(rows),
        "source_backup_plan_sha256": str(receipt.get("plan_sha256") or ""),
        "mutation_performed": False,
    }


def build_backup_restore_plan(
    wordpress_root: Path,
    environment: str,
    backup_id: str,
    incident_id: str,
    reason: str,
) -> dict[str, Any]:
    if environment not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("Protected backup restore is Staging-only")
    verification = verify_protected_backup(wordpress_root, backup_id)
    before = target_state(wordpress_root)
    plan = {
        "contract": BACKUP_RESTORE_PLAN_CONTRACT,
        "action": "restore_protected_backup",
        "environment": environment,
        "incident_id": require_incident_id(incident_id),
        "reason": require_reason(reason),
        "created_at": utc_now(),
        "target_before": before,
        "backup": {
            "backup_id": verification["backup_id"],
            "plugin_tree_sha256": verification["plugin_tree_sha256"],
            "backup_manifest_sha256": verification["backup_manifest_sha256"],
            "source_backup_plan_sha256": verification["source_backup_plan_sha256"],
        },
        "scope": {
            "plugin_slug": PLUGIN_SLUG,
            "database_mutation": False,
            "wp_config_mutation": False,
            "arbitrary_paths_allowed": False,
        },
        "authorization": {
            "mode": "SINGLE_OWNER_HARDENED",
            "exact_plan_attestation_required": True,
            "production_authorized": False,
            "breakglass_authority_created": False,
        },
        "postconditions": {
            "plugin_tree_sha256": verification["plugin_tree_sha256"],
            "rollback_to_target_before_required_on_failure": True,
            "durable_receipt_required": True,
        },
    }
    plan["plan_sha256"] = plan_digest(plan)
    return plan


def assert_backup_restore_plan_current(plan: dict[str, Any]) -> tuple[Path, dict[str, Any]]:
    if plan.get("contract") != BACKUP_RESTORE_PLAN_CONTRACT or plan.get("action") != "restore_protected_backup":
        raise ValueError("protected backup restore plan contract/action mismatch")
    if plan.get("environment") not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("protected backup restore environment is not allowed")
    expected_sha = str(plan.get("plan_sha256") or "").lower()
    if not re.fullmatch(r"[a-f0-9]{64}", expected_sha) or plan_digest(plan) != expected_sha:
        raise ValueError("protected backup restore plan digest mismatch")
    scope = plan.get("scope")
    if not isinstance(scope, dict) or scope.get("plugin_slug") != PLUGIN_SLUG:
        raise ValueError("protected backup restore scope mismatch")
    if scope.get("database_mutation") is not False or scope.get("wp_config_mutation") is not False:
        raise ValueError("protected backup restore widened scope")

    before = plan.get("target_before")
    if not isinstance(before, dict):
        raise ValueError("protected backup restore target_before missing")
    root = Path(str(before.get("wordpress_root") or "")).resolve()
    current = target_state(root)
    for key in ("wordpress_root", "wp_config_sha256", "plugin_present", "plugin_tree_sha256"):
        if current.get(key) != before.get(key):
            raise ValueError(f"protected backup restore target changed since plan: {key}")

    backup = plan.get("backup")
    if not isinstance(backup, dict):
        raise ValueError("protected backup restore backup identity missing")
    verification = verify_protected_backup(root, str(backup.get("backup_id") or ""))
    for key in ("plugin_tree_sha256", "backup_manifest_sha256", "source_backup_plan_sha256"):
        if str(verification.get(key) or "") != str(backup.get(key) or ""):
            raise ValueError(f"protected backup restore source drift: {key}")
    return root, verification


def apply_backup_restore(plan: dict[str, Any], owner_attest_plan_sha: str) -> dict[str, Any]:
    root, verification = assert_backup_restore_plan_current(plan)
    plan_sha = str(plan["plan_sha256"])
    if owner_attest_plan_sha.strip().lower() != plan_sha:
        raise ValueError("OWNER_ATTEST_SINGLE_OWNER does not bind exact protected backup restore plan")

    recovery_root = root / "wp-content" / "mad4b-recovery"
    if recovery_root.is_symlink():
        raise ValueError("recovery root symlink is forbidden")
    staging_root = recovery_root / "restore-staging"
    quarantine_root = recovery_root / "quarantine"
    journal_root = recovery_root / "recovery-journal"
    receipt_root = recovery_root / "receipts"
    for directory in (staging_root, quarantine_root, journal_root, receipt_root):
        directory.mkdir(parents=True, exist_ok=True)
        if directory.is_symlink() or not directory.is_dir():
            raise ValueError("protected backup restore evidence directory is invalid")

    token = f"{require_incident_id(str(plan['incident_id']))}-{plan_sha[:12]}-backup-restore"
    stage = staging_root / token
    rollback = quarantine_root / f"{token}-previous"
    journal_path = journal_root / f"{token}.json"
    receipt_path = receipt_root / f"{token}.json"
    if any(p.exists() for p in (stage, rollback, journal_path, receipt_path)):
        raise ValueError("protected backup restore execution target already exists")

    snapshot = Path(verification["snapshot_path"])
    _copy_plugin_snapshot(snapshot, stage)
    expected_tree = str(verification["plugin_tree_sha256"])
    if not hmac.compare_digest(tree_digest(stage), expected_tree):
        shutil.rmtree(stage, ignore_errors=True)
        raise ValueError("protected backup restore staged tree verification failed")

    live = root / "wp-content" / "plugins" / PLUGIN_SLUG
    before = plan["target_before"]
    journal = {
        "contract": "mad4b.recovery-mutation-journal.v1",
        "action": "restore_protected_backup",
        "plan_sha256": plan_sha,
        "incident_id": plan["incident_id"],
        "mutation_started": False,
        "terminal": False,
        "evidence_state": "PLANNED",
        "blind_retry_allowed": False,
        "expected_post_identity": {
            "plugin_present": True,
            "plugin_tree_sha256": expected_tree,
        },
        "created_at": utc_now(),
    }
    atomic_json_write(journal_path, journal)

    moved_old = False
    moved_new = False
    try:
        started = dict(journal)
        started.update({
            "mutation_started": True,
            "evidence_state": "MUTATION_STARTED",
            "mutation_started_at": utc_now(),
        })
        atomic_json_write(journal_path, started)

        if live.exists():
            if live.is_symlink() or not live.is_dir():
                raise ValueError("protected backup restore live plugin target is invalid")
            os.replace(live, rollback)
            moved_old = True
        os.replace(stage, live)
        moved_new = True

        if not hmac.compare_digest(tree_digest(live), expected_tree):
            raise RuntimeError("protected backup restore postcondition readback failed")

        receipt = {
            "contract": BACKUP_RESTORE_RECEIPT_CONTRACT,
            "action": "restore_protected_backup",
            "environment": plan["environment"],
            "incident_id": plan["incident_id"],
            "reason": plan["reason"],
            "plan_sha256": plan_sha,
            "owner_attest_plan_sha256": plan_sha,
            "backup_id": verification["backup_id"],
            "backup_manifest_sha256": verification["backup_manifest_sha256"],
            "source_backup_plan_sha256": verification["source_backup_plan_sha256"],
            "wordpress_root": str(root),
            "wp_config_sha256": before["wp_config_sha256"],
            "previous_plugin_present": bool(before["plugin_present"]),
            "previous_plugin_tree_sha256": str(before["plugin_tree_sha256"]),
            "restored_plugin_tree_sha256": expected_tree,
            "rollback_path": str(rollback) if moved_old else "",
            "readback_verified": True,
            "production_authorized": False,
            "database_mutation": False,
            "wp_config_mutation": False,
            "mutation_performed": True,
            "evidence_state": "DURABLE_VERIFIED_RECEIPT",
            "created_at": utc_now(),
        }
        atomic_json_write(receipt_path, receipt)
        persisted = json.loads(receipt_path.read_text(encoding="utf-8"))
        if persisted.get("plan_sha256") != plan_sha or persisted.get("restored_plugin_tree_sha256") != expected_tree:
            raise RuntimeError("protected backup restore receipt readback failed")

        completed = dict(started)
        completed.update({
            "terminal": True,
            "evidence_state": "DURABLE_VERIFIED_RECEIPT",
            "receipt_path": str(receipt_path),
            "completed_at": utc_now(),
        })
        try:
            atomic_json_write(journal_path, completed)
            receipt["journal_completion_persisted"] = True
        except Exception:
            receipt["journal_completion_persisted"] = False
        receipt["receipt_path"] = str(receipt_path)
        receipt["journal_path"] = str(journal_path)
        return receipt
    except Exception:
        rollback_verified = False
        try:
            if moved_new and live.exists():
                if live.is_symlink():
                    raise RuntimeError("protected backup restore live target became symlink")
                shutil.rmtree(live)
            if moved_old and rollback.exists():
                os.replace(rollback, live)
            current = target_state(root)
            rollback_verified = all(
                current.get(key) == before.get(key)
                for key in ("wordpress_root", "wp_config_sha256", "plugin_present", "plugin_tree_sha256")
            )
        except Exception:
            rollback_verified = False

        failure = dict(journal)
        failure.update({
            "mutation_started": bool(moved_old or moved_new),
            "terminal": True,
            "evidence_state": "ROLLED_BACK_AFTER_FAILURE" if rollback_verified else "MUTATED_BUT_EVIDENCE_UNCERTAIN",
            "rollback_verified": rollback_verified,
            "blind_retry_allowed": False,
            "completed_at": utc_now(),
        })
        try:
            atomic_json_write(journal_path, failure)
        except Exception:
            pass
        if stage.exists():
            shutil.rmtree(stage, ignore_errors=True)
        raise


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



def recovery_journal_summary(wordpress_root: Path) -> dict[str, Any]:
    root = wordpress_root.expanduser().resolve()
    journal_root = root / "wp-content" / "mad4b-recovery" / "recovery-journal"
    summary = {
        "path": str(journal_root),
        "exists": journal_root.is_dir(),
        "total": 0,
        "pending": 0,
        "uncertain": 0,
        "completed": 0,
        "rolled_back": 0,
        "invalid": 0,
        "reconciliation_required": False,
        "entries": [],
    }
    if not journal_root.is_dir():
        return summary
    if journal_root.is_symlink():
        raise ValueError("recovery journal root symlink is forbidden")
    for path in sorted(journal_root.glob("*.json")):
        if path.is_symlink() or not path.is_file():
            summary["invalid"] += 1
            continue
        try:
            row = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            summary["invalid"] += 1
            continue
        state = str(row.get("evidence_state") or "")
        terminal = row.get("terminal") is True
        summary["total"] += 1
        if state == "MUTATED_BUT_EVIDENCE_UNCERTAIN":
            summary["uncertain"] += 1
        elif terminal and state == "DURABLE_VERIFIED_RECEIPT":
            summary["completed"] += 1
        elif terminal and state == "ROLLED_BACK_AFTER_FAILURE":
            summary["rolled_back"] += 1
        else:
            summary["pending"] += 1
        summary["entries"].append({
            "file": path.name,
            "action": row.get("action"),
            "plan_sha256": row.get("plan_sha256"),
            "incident_id": row.get("incident_id"),
            "evidence_state": state,
            "terminal": terminal,
            "expected_post_identity": row.get("expected_post_identity"),
            "reconciliation_required": bool(row.get("reconciliation_required")),
        })
    summary["reconciliation_required"] = bool(
        summary["pending"] or summary["uncertain"] or summary["invalid"]
    )
    return summary


def reconcile_recovery_evidence(wordpress_root: Path, environment: str) -> dict[str, Any]:
    """Read-only reconciliation of recovery journals against current runtime and receipts."""
    if environment not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("Recovery Plane is Staging-only")
    root = wordpress_root.expanduser().resolve()
    state = target_state(root)
    live = Path(state["plugin_path"])
    receipt_root = root / "wp-content" / "mad4b-recovery" / "receipts"
    journal = recovery_journal_summary(root)
    rows = []
    for entry in journal["entries"]:
        plan_sha = str(entry.get("plan_sha256") or "")
        action = str(entry.get("action") or "")
        receipt_match = False
        if receipt_root.is_dir() and not receipt_root.is_symlink():
            for receipt_path in receipt_root.glob("*.json"):
                if receipt_path.is_symlink() or not receipt_path.is_file():
                    continue
                try:
                    receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
                except (OSError, json.JSONDecodeError):
                    continue
                if str(receipt.get("plan_sha256") or "") == plan_sha:
                    receipt_match = True
                    break
        runtime_match = None
        runtime_identity = None
        expected_post = entry.get("expected_post_identity")
        if action == "restore_known_good":
            if live.is_dir():
                try:
                    runtime_identity = verify_installed_provenance(live)
                    runtime_match = bool(
                        isinstance(expected_post, dict)
                        and runtime_identity == expected_post
                    )
                except (ValueError, OSError, json.JSONDecodeError):
                    runtime_match = False
            else:
                runtime_match = False
        elif action == "disable_current":
            expected_tree = (
                str(expected_post.get("quarantine_tree_sha256") or "")
                if isinstance(expected_post, dict) else ""
            )
            quarantine = (
                root / "wp-content" / "mad4b-recovery" / "quarantine"
                / Path(str(entry.get("file") or "")).stem
            )
            quarantine_match = False
            if expected_tree and quarantine.is_dir() and not quarantine.is_symlink():
                try:
                    quarantine_match = hmac.compare_digest(tree_digest(quarantine), expected_tree)
                except (ValueError, OSError):
                    quarantine_match = False
            runtime_match = bool(
                not live.exists()
                and isinstance(expected_post, dict)
                and expected_post.get("plugin_present") is False
                and quarantine_match
            )
        rolled_back = entry.get("evidence_state") == "ROLLED_BACK_AFTER_FAILURE"
        status = "DURABLE_RECEIPT_PRESENT" if receipt_match else (
            "ROLLED_BACK" if rolled_back else
            "RUNTIME_EFFECT_OBSERVED_NO_RECEIPT" if runtime_match is True else
            "RUNTIME_EFFECT_NOT_OBSERVED" if runtime_match is False else
            "UNKNOWN"
        )
        rows.append({
            **entry,
            "durable_receipt_present": receipt_match,
            "runtime_effect_observed": runtime_match,
            "runtime_identity": runtime_identity,
            "reconciliation_status": status,
            "safe_to_blind_retry": False,
        })
    return {
        "contract": "mad4b.recovery-evidence-reconciliation.v1",
        "environment": environment,
        "read_only": True,
        "mutation_performed": False,
        "target": state,
        "journal": journal,
        "reconciliations": rows,
        "blind_retry_allowed": False,
    }


def recovery_status(wordpress_root: Path, environment: str) -> dict[str, Any]:
    if environment not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("Recovery Plane is Staging-only")
    state = target_state(wordpress_root)
    plugin = Path(state["plugin_path"])
    provenance: dict[str, Any] = {
        "present": state["plugin_present"],
        "valid": False,
        "identity": None,
        "error": "",
    }
    if state["plugin_present"]:
        try:
            provenance["identity"] = verify_installed_provenance(plugin)
            provenance["valid"] = True
        except (ValueError, OSError, json.JSONDecodeError) as exc:
            provenance["error"] = str(exc)

    root = Path(state["wordpress_root"])
    recovery_root = root / "wp-content" / "mad4b-recovery"
    return {
        "contract": "mad4b.recovery-status.v2",
        "environment": environment,
        "read_only": True,
        "mutation_performed": False,
        "target": state,
        "installed_provenance": provenance,
        "recovery_workspace": {
            "path": str(recovery_root),
            "exists": recovery_root.is_dir(),
            "parent_exists": recovery_root.parent.is_dir(),
            "parent_writable": os.access(recovery_root.parent, os.W_OK),
        },
        "recovery_journal": recovery_journal_summary(root),
        "protected_backup": protected_backup_status(root),
        "capabilities": {
            "status": True,
            "protected_backup_exact_plan": True,
            "disable_current_exact_plan": True,
            "restore_known_good_exact_plan": True,
            "production_authorized": False,
            "requires_wordpress_boot": False,
            "requires_database": False,
        },
    }


def build_disable_plan(
    wordpress_root: Path,
    environment: str,
    incident_id: str,
    reason: str,
) -> dict[str, Any]:
    if environment not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("Recovery Plane is Staging-only")
    state = target_state(wordpress_root)
    if not state["plugin_present"]:
        raise ValueError("Control Plane plugin is not present; disable plan has no target")
    plan: dict[str, Any] = {
        "contract": DISABLE_PLAN_CONTRACT,
        "action": "disable_current",
        "environment": environment,
        "incident_id": require_incident_id(incident_id),
        "reason": require_reason(reason),
        "created_at": utc_now(),
        "target": state,
        "authorization": {
            "mode": "SINGLE_OWNER_HARDENED",
            "exact_plan_attestation_required": True,
            "production_authorized": False,
            "breakglass_authority_created": False,
        },
        "postconditions": {
            "plugin_path_absent": True,
            "quarantine_tree_matches_planned_target": True,
            "restore_or_normal_control_followup_required": True,
        },
    }
    plan["plan_sha256"] = plan_digest(plan)
    return plan


def assert_disable_plan_current(plan: dict[str, Any]) -> Path:
    if plan.get("contract") != DISABLE_PLAN_CONTRACT or plan.get("action") != "disable_current":
        raise ValueError("recovery disable plan contract/action mismatch")
    if plan.get("environment") not in SUPPORTED_ENVIRONMENTS:
        raise ValueError("recovery disable plan environment is not allowed")
    expected_sha = str(plan.get("plan_sha256", "")).lower()
    if not re.fullmatch(r"[0-9a-f]{64}", expected_sha) or plan_digest(plan) != expected_sha:
        raise ValueError("recovery disable plan digest mismatch")
    target = plan.get("target")
    if not isinstance(target, dict):
        raise ValueError("recovery disable target missing")
    root = Path(str(target.get("wordpress_root", ""))).resolve()
    current = target_state(root)
    for key in ("wordpress_root", "wp_config_sha256", "plugin_present", "plugin_tree_sha256"):
        if current.get(key) != target.get(key):
            raise ValueError(f"recovery disable target changed since plan: {key}")
    if not current.get("plugin_present"):
        raise ValueError("recovery disable target is already absent")
    return root


def apply_disable(plan: dict[str, Any], owner_attest_plan_sha: str) -> dict[str, Any]:
    root = assert_disable_plan_current(plan)
    plan_sha = str(plan["plan_sha256"])
    if owner_attest_plan_sha.strip().lower() != plan_sha:
        raise ValueError("OWNER_ATTEST_SINGLE_OWNER does not bind the exact recovery disable plan")

    plugins = root / "wp-content" / "plugins"
    live = plugins / PLUGIN_SLUG
    recovery_root = root / "wp-content" / "mad4b-recovery"
    quarantine_root = recovery_root / "quarantine"
    receipt_root = recovery_root / "receipts"
    journal_root = recovery_root / "recovery-journal"
    if recovery_root.is_symlink():
        raise ValueError("recovery root symlink is forbidden")
    quarantine_root.mkdir(parents=True, exist_ok=True)
    receipt_root.mkdir(parents=True, exist_ok=True)
    journal_root.mkdir(parents=True, exist_ok=True)

    token = f"{require_incident_id(str(plan['incident_id']))}-{plan_sha[:12]}-disabled"
    quarantine = quarantine_root / token
    if quarantine.exists():
        raise ValueError("recovery disable quarantine target already exists")

    planned_tree = str(plan["target"]["plugin_tree_sha256"])
    moved = False
    journal_path = journal_root / f"{token}.json"
    journal = {
        "contract": "mad4b.recovery-mutation-journal.v1",
        "action": "disable_current",
        "plan_sha256": plan_sha,
        "incident_id": plan["incident_id"],
        "mutation_started": True,
        "mutation_started_at": utc_now(),
        "target_plugin_path": str(live),
        "expected_post_identity": {
            "plugin_present": False,
            "quarantine_tree_sha256": planned_tree,
        },
        "evidence_state": "MUTATION_INTENT_DURABLE",
        "terminal": False,
    }
    atomic_json_write(journal_path, journal)
    try:
        os.replace(live, quarantine)
        moved = True
        if live.exists():
            raise ValueError("Control Plane plugin path still exists after disable")
        actual_tree = tree_digest(quarantine)
        if actual_tree != planned_tree:
            raise ValueError("quarantined plugin tree differs from reviewed disable target")

        receipt = {
            "contract": DISABLE_RECEIPT_CONTRACT,
            "action": "disable_current",
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
                "plugin_tree_sha256": planned_tree,
                "quarantine_path": str(quarantine.relative_to(root)),
            },
            "post_disable": {
                "plugin_present": False,
                "quarantine_tree_sha256": actual_tree,
                "restore_or_normal_control_followup_required": True,
                "production_authorized": False,
            },
        }
        receipt["evidence_state"] = "DURABLE_VERIFIED_RECEIPT"
        receipt_path = receipt_root / f"{token}.json"
        try:
            atomic_json_write(receipt_path, receipt)
        except OSError as exc:
            uncertain = dict(journal)
            uncertain.update({
                "terminal": True,
                "evidence_state": "MUTATED_BUT_EVIDENCE_UNCERTAIN",
                "reconciliation_required": True,
                "failure": str(exc),
            })
            try:
                atomic_json_write(journal_path, uncertain)
            except OSError:
                pass
            raise RuntimeError("MUTATED_BUT_EVIDENCE_UNCERTAIN") from exc

        completed = dict(journal)
        completed.update({
            "terminal": True,
            "evidence_state": "DURABLE_VERIFIED_RECEIPT",
            "receipt_path": str(receipt_path),
            "completed_at": utc_now(),
        })
        try:
            atomic_json_write(journal_path, completed)
            receipt["journal_completion_persisted"] = True
        except OSError:
            # The final receipt is already durable and authoritative. A stale journal
            # is reconciled read-only; it must not cause a blind write retry.
            receipt["journal_completion_persisted"] = False
        receipt["receipt_path"] = str(receipt_path)
        receipt["journal_path"] = str(journal_path)
        return receipt
    except Exception as exc:
        rollback_restored = False
        if moved and quarantine.exists() and not live.exists():
            os.replace(quarantine, live)
            rollback_restored = True
        if rollback_restored:
            rolled_back = dict(journal)
            rolled_back.update({
                "terminal": True,
                "evidence_state": "ROLLED_BACK_AFTER_FAILURE",
                "reconciliation_required": False,
                "rollback_restored_original": True,
                "failure": str(exc),
                "completed_at": utc_now(),
            })
            try:
                atomic_json_write(journal_path, rolled_back)
            except OSError:
                pass
        raise

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
    journal_root = recovery_root / "recovery-journal"
    if recovery_root.is_symlink():
        raise ValueError("recovery root symlink is forbidden")
    quarantine_root.mkdir(parents=True, exist_ok=True)
    receipt_root.mkdir(parents=True, exist_ok=True)
    journal_root.mkdir(parents=True, exist_ok=True)

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
    journal_path = journal_root / f"{token}.json"
    journal = {
        "contract": "mad4b.recovery-mutation-journal.v1",
        "action": "restore_known_good",
        "plan_sha256": plan_sha,
        "incident_id": plan["incident_id"],
        "mutation_started": True,
        "mutation_started_at": utc_now(),
        "target_plugin_path": str(live),
        "expected_post_identity": expected,
        "evidence_state": "MUTATION_INTENT_DURABLE",
        "terminal": False,
    }
    atomic_json_write(journal_path, journal)
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
        "evidence_state": "DURABLE_VERIFIED_RECEIPT",
        "root_trust_verification": {
            "contract": root_receipt.get("contract"),
            "attestation_verified": bool(root_receipt.get("attestation_verified")),
            "verification_boundary": root_receipt.get("verification_boundary"),
            "verified_attestation_count": root_receipt.get("verified_attestation_count"),
        },
    }
    receipt_path = receipt_root / f"{token}.json"
    try:
        atomic_json_write(receipt_path, receipt)
    except OSError as exc:
        uncertain = dict(journal)
        uncertain.update({
            "terminal": True,
            "evidence_state": "MUTATED_BUT_EVIDENCE_UNCERTAIN",
            "reconciliation_required": True,
            "failure": str(exc),
        })
        try:
            atomic_json_write(journal_path, uncertain)
        except OSError:
            pass
        raise RuntimeError("MUTATED_BUT_EVIDENCE_UNCERTAIN") from exc

    completed = dict(journal)
    completed.update({
        "terminal": True,
        "evidence_state": "DURABLE_VERIFIED_RECEIPT",
        "receipt_path": str(receipt_path),
        "completed_at": utc_now(),
    })
    try:
        atomic_json_write(journal_path, completed)
        receipt["journal_completion_persisted"] = True
    except OSError:
        # Durable receipt is authoritative; reconcile the stale journal read-only.
        receipt["journal_completion_persisted"] = False
    receipt["receipt_path"] = str(receipt_path)
    receipt["journal_path"] = str(journal_path)
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

    reconcile_p = sub.add_parser("reconcile-evidence")
    reconcile_p.add_argument("--wordpress-root", required=True, type=Path)
    reconcile_p.add_argument("--environment", required=True, choices=sorted(SUPPORTED_ENVIRONMENTS))

    backup_plan_p = sub.add_parser("plan-backup")
    backup_plan_p.add_argument("--wordpress-root", required=True, type=Path)
    backup_plan_p.add_argument("--environment", required=True, choices=sorted(SUPPORTED_ENVIRONMENTS))
    backup_plan_p.add_argument("--incident-id", required=True)
    backup_plan_p.add_argument("--reason", required=True)
    backup_plan_p.add_argument("--output", required=True, type=Path)

    backup_apply_p = sub.add_parser("apply-backup")
    backup_apply_p.add_argument("--plan", required=True, type=Path)
    backup_apply_p.add_argument("--owner-attest-plan-sha", required=True)

    backup_verify_p = sub.add_parser("verify-backup")
    backup_verify_p.add_argument("--wordpress-root", required=True, type=Path)
    backup_verify_p.add_argument("--backup-id", required=True)

    backup_restore_plan_p = sub.add_parser("plan-backup-restore")
    backup_restore_plan_p.add_argument("--wordpress-root", required=True, type=Path)
    backup_restore_plan_p.add_argument("--environment", required=True, choices=sorted(SUPPORTED_ENVIRONMENTS))
    backup_restore_plan_p.add_argument("--backup-id", required=True)
    backup_restore_plan_p.add_argument("--incident-id", required=True)
    backup_restore_plan_p.add_argument("--reason", required=True)
    backup_restore_plan_p.add_argument("--output", required=True, type=Path)

    backup_restore_apply_p = sub.add_parser("apply-backup-restore")
    backup_restore_apply_p.add_argument("--plan", required=True, type=Path)
    backup_restore_apply_p.add_argument("--owner-attest-plan-sha", required=True)

    disable_plan_p = sub.add_parser("plan-disable")
    disable_plan_p.add_argument("--wordpress-root", required=True, type=Path)
    disable_plan_p.add_argument("--environment", required=True, choices=sorted(SUPPORTED_ENVIRONMENTS))
    disable_plan_p.add_argument("--incident-id", required=True)
    disable_plan_p.add_argument("--reason", required=True)
    disable_plan_p.add_argument("--output", required=True, type=Path)

    disable_apply_p = sub.add_parser("apply-disable")
    disable_apply_p.add_argument("--plan", required=True, type=Path)
    disable_apply_p.add_argument("--owner-attest-plan-sha", required=True)

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
            result = recovery_status(args.wordpress_root, args.environment)
        elif args.command == "reconcile-evidence":
            result = reconcile_recovery_evidence(args.wordpress_root, args.environment)
        elif args.command == "plan-backup":
            result = build_backup_plan(
                args.wordpress_root,
                args.environment,
                args.incident_id,
                args.reason,
            )
            atomic_json_write(args.output, result)
        elif args.command == "apply-backup":
            plan = root_trust.load_json(args.plan)
            result = apply_backup(plan, args.owner_attest_plan_sha)
        elif args.command == "verify-backup":
            result = verify_protected_backup(args.wordpress_root, args.backup_id)
        elif args.command == "plan-backup-restore":
            result = build_backup_restore_plan(
                args.wordpress_root,
                args.environment,
                args.backup_id,
                args.incident_id,
                args.reason,
            )
            atomic_json_write(args.output, result)
        elif args.command == "apply-backup-restore":
            plan = root_trust.load_json(args.plan)
            result = apply_backup_restore(plan, args.owner_attest_plan_sha)
        elif args.command == "plan-disable":
            result = build_disable_plan(
                args.wordpress_root,
                args.environment,
                args.incident_id,
                args.reason,
            )
            args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        elif args.command == "apply-disable":
            plan = root_trust.load_json(args.plan)
            result = apply_disable(plan, args.owner_attest_plan_sha)
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

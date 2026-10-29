#!/usr/bin/env python3
"""Hash-locked, non-destructive deployer for the reviewed Woo Lab MU overlay.

Targets an explicitly supplied WordPress MU directory; no WP_ENVIRONMENT_TYPE
gate. Refuses unlisted files, changed originals, symlinks, or automatic broad
multi-tenant deployment. No secrets, WP database or external I/O.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "manifest.json"
SOURCE = ROOT / "mu-plugins"


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def read_package() -> tuple[list[dict], str]:
    raw = MANIFEST.read_bytes()
    m = json.loads(raw)
    if m.get("schema") != "mad4b.etg-woo-lab-portable-mu.v1":
        raise ValueError("unexpected_manifest_schema")
    rows = m["files"]
    if len(rows) != 32 or m["file_count"] != 32:
        raise ValueError("source_count_mismatch")
    actual = {p.name for p in SOURCE.iterdir() if p.is_file()}
    expected = {r["path"] for r in rows}
    if expected != actual or len(expected) != 32:
        raise ValueError("source_files_drift")
    for r in rows:
        name = r["path"]
        if "/" in name or "\\" in name or name.startswith(".") or not name.endswith(".php"):
            raise ValueError("unsafe_filename")
        p = SOURCE / name
        if p.is_symlink() or not p.is_file():
            raise ValueError("unsafe_source")
        b = p.read_bytes()
        if len(b) != r["bytes"] or digest(b) != r["sha256"]:
            raise ValueError("source_hash_mismatch:" + name)
        if len(r["prior_sha256"]) != 64:
            raise ValueError("prior_hash_not_recorded")
    return rows, digest(raw)


def lint(rows: list[dict]) -> None:
    for r in rows:
        p = SOURCE / r["path"]
        proc = subprocess.run([os.environ.get("MU_PHP_BIN", "php"), "-l", str(p)], capture_output=True, text=True)
        if proc.returncode:
            raise RuntimeError(f"php_syntax_error:{r['path']}:{proc.stderr[-250:]}")


def target_is_safe(target: Path) -> Path:
    if not target.is_absolute():
        raise ValueError("absolute_target_path_required")
    if target.is_symlink():
        raise ValueError("mu_target_must_not_be_symlink")
    if target.parts[-2:] != ("wp-content", "mu-plugins"):
        raise ValueError("wordpress_mu_directory_required")
    if not target.is_dir() or not (target.parent.parent / "wp-settings.php").is_file():
        raise ValueError("real_wordpress_root_required")
    return target.resolve(strict=True)


def inspect(rows: list[dict], target: Path) -> tuple[list[str], list[str]]:
    changed, already = [], []
    for r in rows:
        dest = target / r["path"]
        if not dest.is_file() or dest.is_symlink():
            raise ValueError("missing_or_symlink_target:" + r["path"])
        actual = digest(dest.read_bytes())
        if actual == r["sha256"]:
            already.append(r["path"])
        elif actual == r["prior_sha256"]:
            changed.append(r["path"])
        else:
            raise ValueError("unreviewed_target_drift:" + r["path"])
    return changed, already


def save_atomic(path: Path, data: bytes) -> None:
    fd, temp_name = tempfile.mkstemp(prefix=".mu-reviewed-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temp_name, path.stat().st_mode & 0o777)
        try:
            os.chown(temp_name, path.stat().st_uid, path.stat().st_gid)
        except PermissionError:
            if os.stat(temp_name).st_uid != path.stat().st_uid:
                raise
        os.replace(temp_name, path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


def apply(rows: list[dict], target: Path, backup: Path, manifest_sha: str) -> dict:
    changed, already = inspect(rows, target)
    if not changed:
        return {"result": "ALREADY_CURRENT", "changed": 0, "unchanged": len(already),
                "manifest_sha256": manifest_sha}
    if backup.exists():
        raise ValueError("backup_destination_exists")
    backup.mkdir(parents=True, mode=0o700)
    (backup / "previous").mkdir(mode=0o700)
    prior = {}
    replaced = []
    try:
        for name in changed:
            dest = target / name
            content = dest.read_bytes()
            prior[name] = digest(content)
            shutil.copy2(dest, backup / "previous" / name, follow_symlinks=False)
        receipt = {"schema": "mad4b.mu-portability-deploy-receipt.v1",
                   "target": str(target), "manifest_sha256": manifest_sha,
                   "timestamp_utc": datetime.now(timezone.utc).isoformat(),
                   "prior": prior, "after": {
                       r["path"]: r["sha256"] for r in rows if r["path"] in changed}}
        for name in changed:
            save_atomic(target / name, (SOURCE / name).read_bytes())
            replaced.append(name)
        (backup / "receipt.json").write_text(json.dumps(receipt, indent=2) + "\n")
    except Exception:
        for name in reversed(replaced):
            save_atomic(target / name, (backup / "previous" / name).read_bytes())
        raise
    return {"result": "DEPLOYED", "changed": len(changed), "unchanged": len(already),
            "backup": str(backup), "manifest_sha256": manifest_sha}


def rollback(receipt_dir: Path, target: Path) -> dict:
    receipt = json.loads((receipt_dir / "receipt.json").read_text())
    if receipt["schema"] != "mad4b.mu-portability-deploy-receipt.v1" or \
            receipt["target"] != str(target):
        raise ValueError("rollback_target_binding_mismatch")
    before = receipt["prior"]
    for name, wanted in before.items():
        if name.startswith(".") or "/" in name or "\\" in name:
            raise ValueError("unsafe_rollback_filename")
        source = receipt_dir / "previous" / name
        dest = target / name
        if not source.is_file() or source.is_symlink() or dest.is_symlink():
            raise ValueError("unsafe_rollback_path")
        if digest(source.read_bytes()) != wanted or \
                digest(dest.read_bytes()) != receipt["after"][name]:
            raise ValueError("rollback_drift:" + name)
    for name in before:
        save_atomic(target / name, (receipt_dir / "previous" / name).read_bytes())
    return {"result": "ROLLED_BACK", "changed": len(before)}


def main() -> None:
    parser = argparse.ArgumentParser()
    choices = parser.add_mutually_exclusive_group(required=True)
    choices.add_argument("--check", action="store_true")
    choices.add_argument("--dry-run", action="store_true")
    choices.add_argument("--apply", action="store_true")
    choices.add_argument("--rollback", metavar="RECEIPT_DIRECTORY")
    parser.add_argument("--target", type=Path)
    parser.add_argument("--backup-dir", type=Path)
    args = parser.parse_args()
    try:
        rows, sha = read_package()
        if args.rollback is not None:
            if not args.target:
                raise ValueError("target_required")
            result = rollback(Path(args.rollback).resolve(strict=True),
                              target_is_safe(args.target))
        else:
            lint(rows)
            if args.check:
                result = {"result": "SOURCE_VALIDATED", "files": len(rows),
                          "manifest_sha256": sha}
            else:
                if not args.target:
                    raise ValueError("target_required")
                dest = target_is_safe(args.target)
                changes, current = inspect(rows, dest)
                if args.dry_run:
                    result = {"result": "PREFLIGHT_PASS", "would_update": len(changes),
                              "already_current": len(current), "manifest_sha256": sha}
                else:
                    if args.backup_dir is None or not args.backup_dir.is_absolute():
                        raise ValueError("absolute_backup_directory_required")
                    result = apply(rows, dest, args.backup_dir, sha)
        print(json.dumps(result, sort_keys=True))
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as exc:
        print("DEPLOYMENT_REFUSED: " + str(exc), file=sys.stderr)
        raise SystemExit(2)


if __name__ == "__main__":
    main()

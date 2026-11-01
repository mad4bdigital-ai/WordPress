#!/usr/bin/env python3
"""Reproducible, exact-Git-commit Content Hub plugin release component.

Binds a standalone Content Hub ZIP to the exact source tree and a signed-by-
workflow GitHub immutable artifact. This does not deploy or grant authority.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import zipfile

SOURCE = Path("ops/mad4b-governed-task-context-v1/plugin")
COMPONENT = "mad4b-content-hub"
CONTRACT = "mad4b.content-hub.exact-head-package.v1"
SHA40 = re.compile(r"[a-f0-9]{40}\Z")
SHA64 = re.compile(r"[a-f0-9]{64}\Z")
FIXED_TIME = (1980, 1, 1, 0, 0, 0)
SKIP = {".DS_Store", ".gitignore", "Thumbs.db"}
SECRET_SUFFIXES = {".pem", ".p12", ".pfx", ".key", ".env"}
MAX_SOURCE = 15 * 1024 * 1024
MAX_FILE = 3 * 1024 * 1024


def digest(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


def normalized_manifest(files: dict[str, bytes]) -> dict[str, str]:
    return {name: digest(files[name]) for name in sorted(files)}


def payload_digest(manifest: dict[str, str]) -> str:
    return digest(json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode())


def valid_relative(name: str) -> bool:
    path = PurePosixPath(name)
    return (not path.is_absolute() and name == str(path)
            and all(part not in (".", "..") for part in path.parts)
            and len(path.parts) >= 2 and path.parts[0] == COMPONENT)


def source_files(repo: Path) -> dict[str, bytes]:
    root = repo / SOURCE
    if not root.is_dir() or root.is_symlink():
        raise ValueError("original_content_hub_source_missing")
    files: dict[str, bytes] = {}
    total = 0
    for path in sorted(root.rglob("*")):
        if path.is_symlink():
            raise ValueError(f"source_symlink_forbidden:{path}")
        if path.is_dir():
            continue
        if not path.is_file():
            raise ValueError("unexpected_source_entry")
        rel = path.relative_to(root).as_posix()
        if path.name in SKIP:
            continue
        if path.suffix.lower() in SECRET_SUFFIXES or path.name.startswith(".env"):
            raise ValueError("secret_like_source_forbidden")
        name = COMPONENT + "/" + rel
        if not valid_relative(name):
            raise ValueError("invalid_source_path")
        data = path.read_bytes()
        if not data or len(data) > MAX_FILE:
            raise ValueError("source_file_empty_or_oversize")
        total += len(data)
        if total > MAX_SOURCE:
            raise ValueError("source_tree_oversize")
        if b"-----BEGIN PRIVATE KEY-----" in data:
            raise ValueError("embedded_private_key_forbidden")
        files[name] = data
    if len(files) < 40 or COMPONENT + "/" + COMPONENT + ".php" not in files:
        raise ValueError("incomplete_plugin_source")
    return files


def build(repo: Path, out: Path, sha: str) -> dict:
    if not SHA40.fullmatch(sha):
        raise ValueError("invalid_exact_head_sha")
    files = source_files(repo)
    manifest = normalized_manifest(files)
    out.mkdir(parents=True, exist_ok=True)
    archive = out / (COMPONENT + "-" + sha + ".zip")
    with zipfile.ZipFile(archive, mode="w", compression=zipfile.ZIP_DEFLATED,
                         compresslevel=9, strict_timestamps=True) as z:
        for name, data in files.items():
            info = zipfile.ZipInfo(name, FIXED_TIME)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    receipt = {
        "contract": CONTRACT,
        "source_commit_sha": sha,
        "component": COMPONENT,
        "source_root": SOURCE.as_posix(),
        "archive_name": archive.name,
        "archive_sha256": digest(archive.read_bytes()),
        "source_manifest_digest": payload_digest(manifest),
        "file_count": len(files),
        "files": manifest,
        "release_ready": False,
        "runtime_mutation_performed": False,
        "deployment_authorized": False,
    }
    receipt_file = out / "CONTENT-HUB-CANONICAL-RECEIPT.json"
    receipt_file.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n")
    verify(repo, archive, receipt_file, sha)
    return receipt


def verify(repo: Path, archive: Path, receipt_file: Path, sha: str) -> dict:
    if not SHA40.fullmatch(sha):
        raise ValueError("invalid_exact_head_sha")
    r = json.loads(receipt_file.read_text())
    if (r.get("contract") != CONTRACT or r.get("source_commit_sha") != sha
            or r.get("component") != COMPONENT
            or r.get("source_root") != SOURCE.as_posix()
            or r.get("archive_name") != archive.name
            or not SHA64.fullmatch(str(r.get("archive_sha256", "")))
            or r.get("archive_sha256") != digest(archive.read_bytes())
            or r.get("release_ready") is not False
            or r.get("runtime_mutation_performed") is not False
            or r.get("deployment_authorized") is not False):
        raise ValueError("release_receipt_mismatch")
    original = source_files(repo)
    manifest = normalized_manifest(original)
    if (r.get("files") != manifest or r.get("file_count") != len(original)
            or r.get("source_manifest_digest") != payload_digest(manifest)):
        raise ValueError("original_source_manifest_mismatch")
    with zipfile.ZipFile(archive) as z:
        entries = z.infolist()
        names = [e.filename for e in entries]
        if (len(names) != len(set(names)) or set(names) != set(original)
                or len(names) != len(original)):
            raise ValueError("archive_members_mismatch")
        for e in entries:
            if (not valid_relative(e.filename)
                    or e.is_dir() or ((e.external_attr >> 16) & 0o170000) != stat.S_IFREG
                    or e.file_size != len(original[e.filename])
                    or digest(z.read(e)) != manifest[e.filename]):
                raise ValueError("archive_source_hash_or_entry_type_mismatch")
    return {"verified": True, "source_commit_sha": sha,
            "archive_sha256": r["archive_sha256"],
            "source_manifest_digest": r["source_manifest_digest"],
            "file_count": len(original),
            "deployment_authorized": False}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("mode", choices=["build", "verify"])
    ap.add_argument("--repo", type=Path, default=Path("."))
    ap.add_argument("--source-sha", required=True)
    ap.add_argument("--out", type=Path, default=Path("dist/mad4b-content-hub"))
    ap.add_argument("--archive", type=Path)
    ap.add_argument("--receipt", type=Path)
    args = ap.parse_args()
    if args.mode == "build":
        result = build(args.repo, args.out, args.source_sha)
    else:
        if not args.archive or not args.receipt:
            ap.error("verify requires --archive and --receipt")
        result = verify(args.repo, args.archive, args.receipt, args.source_sha)
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, ValueError, zipfile.BadZipFile, json.JSONDecodeError) as e:
        print("CONTENT_HUB_EXACT_HEAD_PACKAGE: FAIL:", e, file=sys.stderr)
        sys.exit(1)

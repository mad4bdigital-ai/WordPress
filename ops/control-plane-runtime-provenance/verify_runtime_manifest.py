#!/usr/bin/env python3
"""Read-only runtime provenance preflight. Never generates or repairs manifests.

Use before accepting a release and after deploying it. The source commit must
be verified independently by the canonical source builder.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import sys

SHA40 = re.compile(r"^[a-f0-9]{40}$")
SHA64 = re.compile(r"^[a-f0-9]{64}$")


def verify(plugin_root: Path, expected_head: str | None = None) -> dict:
    issues: list[str] = []
    manifest_path = plugin_root / "MAD4B-BUILD-PROVENANCE.json"
    if not manifest_path.is_file() or manifest_path.is_symlink():
        return {"state": "DRIFT", "issues": ["manifest_missing_or_symlink"], "matched": 0, "files": 0}
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (ValueError, OSError, UnicodeError):
        return {"state": "DRIFT", "issues": ["manifest_unreadable"], "matched": 0, "files": 0}
    if not isinstance(manifest, dict) or manifest.get("contract") != "mad4b.build-provenance.v1":
        return {"state": "DRIFT", "issues": ["manifest_contract_invalid"], "matched": 0, "files": 0}

    source_sha = str(manifest.get("source_commit_sha", "")).lower()
    if not SHA40.fullmatch(source_sha):
        issues.append("source_commit_invalid")
    if expected_head is not None and source_sha != expected_head:
        issues.append("pinned_source_head_mismatch")
    for name in ("build_fingerprint", "package_manifest_digest"):
        if not SHA64.fullmatch(str(manifest.get(name, "")).lower()):
            issues.append(name + "_invalid")

    entries = manifest.get("package_files")
    if not isinstance(entries, list) or not entries:
        return {"state": "DRIFT", "issues": issues + ["package_files_missing"], "matched": 0, "files": 0}
    if manifest.get("manifest_file_count") != len(entries):
        issues.append("manifest_file_count_mismatch")
    seen: set[str] = set()
    matched = 0
    root_real = plugin_root.resolve()
    for entry in entries:
        if not isinstance(entry, dict):
            issues.append("invalid_file_entry")
            continue
        rel = entry.get("path")
        expected_hash = entry.get("sha256")
        expected_bytes = entry.get("bytes")
        if not isinstance(rel, str) or not rel or rel in seen:
            issues.append("invalid_or_duplicate_file_path")
            continue
        seen.add(rel)
        pure = PurePosixPath(rel)
        if pure.is_absolute() or ".." in pure.parts or "\\" in rel or rel == "MAD4B-BUILD-PROVENANCE.json":
            issues.append("unsafe_manifest_path:" + rel[:160])
            continue
        if not isinstance(expected_hash, str) or not SHA64.fullmatch(expected_hash):
            issues.append("invalid_hash:" + rel[:160])
            continue
        if not isinstance(expected_bytes, int) or isinstance(expected_bytes, bool) or expected_bytes < 0:
            issues.append("invalid_size:" + rel[:160])
            continue
        path = plugin_root.joinpath(*pure.parts)
        if not path.is_file() or path.is_symlink() or not path.resolve().is_relative_to(root_real):
            issues.append("missing_or_unsafe_file:" + rel[:160])
            continue
        stat = path.stat()
        if stat.st_size != expected_bytes:
            issues.append("size_mismatch:" + rel[:160])
            continue
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        if digest != expected_hash:
            issues.append("sha256_mismatch:" + rel[:160])
            continue
        matched += 1

    return {
        "state": "PASS" if not issues else "DRIFT",
        "source_commit_sha": source_sha,
        "manifest_file_count": len(entries),
        "matched": matched,
        "mismatched": len(entries) - matched,
        "issues": issues,
        "checks_only": True,
        "authorization_granted": False,
        "manifest_modified": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plugin-root", required=True, type=Path)
    parser.add_argument("--expected-source-head", default=None)
    args = parser.parse_args()
    if args.expected_source_head is not None and not SHA40.fullmatch(args.expected_source_head):
        parser.error("--expected-source-head must be a 40-character lowercase Git SHA")
    result = verify(args.plugin_root, args.expected_source_head)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0 if result["state"] == "PASS" else 1


if __name__ == "__main__":
    sys.exit(main())

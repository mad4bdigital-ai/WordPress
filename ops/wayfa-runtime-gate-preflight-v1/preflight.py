#!/usr/bin/env python3
"""Read-only Control Plane provenance and unmanifested-file audit."""
import argparse
from collections import Counter
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import subprocess

NAME = "MAD4B-BUILD-PROVENANCE.json"

def digest(b):
    return hashlib.sha256(b).hexdigest()

def classify(name):
    if ".before-" in name or ".pre-" in name or name.endswith((".bak", ".orig", ".old", ".tmp", ".save")):
        return "backup"
    if name.endswith(".php"):
        return "unmanifested_php"
    if name.startswith(("assets/", "data/")):
        return "assets_or_data"
    return "other"

def inspect(root, repo=None):
    root = root.resolve(strict=True)
    m = json.loads((root / NAME).read_text())
    if m.get("contract") != "mad4b.build-provenance.v1":
        raise ValueError("manifest_contract_invalid")
    seen, changed, missing, lines = set(), [], [], []
    for row in m.get("package_files", []):
        rel = row.get("path", "")
        path = PurePosixPath(rel)
        if not rel or rel in seen or path.is_absolute() or ".." in path.parts or "\\" in rel:
            raise ValueError("unsafe_or_duplicate_manifest_path")
        seen.add(rel)
        f = root / rel
        if f.is_symlink() or not f.resolve().is_relative_to(root):
            raise ValueError("unsafe_symlinked_manifest_entry")
        if not f.is_file():
            missing.append(rel)
            continue
        raw = f.read_bytes()
        if len(raw) != row["bytes"] or digest(raw) != row["sha256"]:
            changed.append({"path": rel, "expected_sha256": row["sha256"], "live_sha256": digest(raw)})
        lines.append(rel.encode() + b"\0" + str(len(raw)).encode() + b"\0" + digest(raw).encode() + b"\n")
    names = set()
    for p in root.rglob("*"):
        if p.is_file():
            names.add(p.relative_to(root).as_posix())
    extras = sorted(names - seen - {NAME})
    kinds = Counter(classify(p) for p in extras)
    commit = m.get("source_commit_sha", "")
    available = None
    if repo:
        if len(commit) != 40:
            available = False
        else:
            result = subprocess.run(["git", "-C", str(repo), "cat-file", "-e", commit + "^{commit}"],
                                    capture_output=True, timeout=15,
                                    env={**os.environ, "GIT_NO_LAZY_FETCH": "1"}, check=False)
            available = result.returncode == 0
    checksum = digest(b"".join(sorted(lines)))
    match = not changed and not missing and checksum == m.get("package_manifest_digest")
    problems = []
    if not match:
        problems.append("runtime_manifest_mismatch")
    if available is False:
        problems.append("manifest_source_commit_missing_in_local_git")
    if kinds["unmanifested_php"]:
        problems.append("unreviewed_unmanifested_php")
    return {
        "contract": "mad4b.wayfa-provenance-preflight.v1",
        "read_only": True,
        "manifest_entries": len(seen),
        "matching_entries": len(seen) - len(changed) - len(missing),
        "changed": changed,
        "missing": missing,
        "unmanifested": extras,
        "extra_classes": dict(kinds),
        "original_source_commit_present": available,
        "manifest_matches_live": match,
        "hard_blockers": problems,
        "ready_to_propose_release": not problems,
    }

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--plugin-root", type=Path, required=True)
    p.add_argument("--git-repo", type=Path)
    p.add_argument("--report", type=Path)
    args = p.parse_args()
    data = inspect(args.plugin_root, args.git_repo)
    raw = json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        with os.fdopen(os.open(args.report, os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW, 0o600), "w") as out:
            out.write(raw)
            out.flush()
            os.fsync(out.fileno())
    print(json.dumps({k: v for k, v in data.items() if k not in ("changed", "missing", "unmanifested")}))
if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""Read-only Git source-preservation audit across release candidate branches."""
from __future__ import annotations
import argparse
import json
import re
from pathlib import PurePosixPath
import subprocess

CONTRACT = "mad4b.release-source-preservation.v1"


def git(root: str, *args: str) -> bytes:
    process = subprocess.run(["git", "-C", root, *args], capture_output=True,
                             timeout=30, check=False)
    if process.returncode:
        raise ValueError("SOURCE_GIT_READ_FAILED:" + args[0])
    return process.stdout


def entries(root: str, revision: str, scopes: list[str]) -> dict[str, str]:
    if not isinstance(revision, str) or re.fullmatch(
            r"(?:[0-9a-f]{40}|[0-9a-f]{64})", revision) is None:
        raise ValueError("SOURCE_PARITY_REVISION_INVALID")
    if not scopes:
        raise ValueError("SOURCE_PARITY_SCOPE_REQUIRED")
    for scope in scopes:
        p = PurePosixPath(scope)
        if (p.is_absolute() or ".." in p.parts or p.as_posix() != scope or
                "//" in scope or chr(92) in scope):
            raise ValueError("SOURCE_PARITY_SCOPE_INVALID")
    result = {}
    for line in git(root, "ls-tree", "-rz", revision, "--", *scopes).split(b"\x00"):
        if not line:
            continue
        header, path = line.split(b"\t", 1)
        mode, kind, oid = header.decode("ascii").split(" ")
        if kind != "blob" or mode not in ("100644", "100755"):
            continue
        result[path.decode("utf-8")] = oid
    return result


def compare(root: str, protected_ref: str, candidate_ref: str,
            scopes: list[str]) -> dict:
    source = entries(root, protected_ref, scopes)
    candidate = entries(root, candidate_ref, scopes)
    lost = sorted(set(source) - set(candidate))
    modified = sorted(p for p in source.keys() & candidate.keys()
                      if source[p] != candidate[p])
    return {
        "contract": CONTRACT,
        "protected_commit": git(root, "rev-parse", protected_ref).decode().strip(),
        "candidate_commit": git(root, "rev-parse", candidate_ref).decode().strip(),
        "scopes": scopes,
        "preserved": len(source) - len(lost) - len(modified),
        "lost_files": [{"path": p, "protected_git_blob": source[p]} for p in lost],
        "modified_files": [{"path": p, "protected_git_blob": source[p],
                            "candidate_git_blob": candidate[p]} for p in modified],
        "candidate_extra_files": len(set(candidate) - set(source)),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", required=True)
    parser.add_argument("--protected-ref", required=True)
    parser.add_argument("--candidate-ref", required=True)
    parser.add_argument("--scope", action="append", required=True)
    args = parser.parse_args()
    report = compare(args.repo, args.protected_ref, args.candidate_ref, args.scope)
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 3 if report["lost_files"] or report["modified_files"] else 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Validate all literal Control Plane loader PHP dependencies before packaging.

This contract is about *source presence*, not execution permission or release
authority. It rejects untracked/missing required classes even when PHP lint
and archive assembly would otherwise pass.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[1]
LOADER = ROOT / "mad4b-site-control-plane.php"
REQUIRES = re.compile(
    r"""\b(?:require|require_once|include|include_once)\s*\(?\s*
    MAD4B_SCP_DIR\s*\.\s*(?P<quote>['"])(?P<path>[^'"]+)(?P=quote)""",
    re.VERBOSE,
)

def main() -> int:
    if not LOADER.is_file():
        print(f"FAIL missing Control Plane loader: {LOADER}", file=sys.stderr)
        return 2
    contents = LOADER.read_text(encoding="utf-8")
    required = sorted(set(match.group("path") for match in REQUIRES.finditer(contents)))
    if len(required) < 100:
        print(f"FAIL unexpectedly small static loader inventory: {len(required)}", file=sys.stderr)
        return 3
    malformed: list[str] = []
    missing: list[str] = []
    for name in required:
        path = PurePosixPath(name)
        if path.is_absolute() or ".." in path.parts or not name.endswith(".php"):
            malformed.append(name)
            continue
        candidate = ROOT.joinpath(*path.parts)
        if not candidate.is_file() or candidate.is_symlink():
            missing.append(name)
    print(f"Control Plane literal loader dependencies: {len(required)}")
    print(f"Available regular PHP sources: {len(required) - len(missing) - len(malformed)}")
    for name in malformed:
        print(f"UNSAFE STATIC REQUIRE: {name}", file=sys.stderr)
    for name in missing:
        print(f"MISSING REQUIRED SOURCE: {name}", file=sys.stderr)
    if malformed or missing:
        print("FAIL: Distributable loader is not source-complete", file=sys.stderr)
        return 1
    print("PASS: Every literal PHP dependency exists in the distributable source")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())

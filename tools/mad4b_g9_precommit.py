#!/usr/bin/env python3
"""Fail-closed authoring-time G9 fingerprint refresh for local Git commits.

NOT called by build/CI; modifies ONLY the G9 evidence manifest when the
existing exact-inventory checker detects drift. Installs no Git hook itself.
Refuses unstaged changes to any fingerprinted source or to the manifest:
the committed evidence must reflect the exact staged source, not a dirty
working tree. Never grants release, Staging installation or Production access.
"""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/g9-delivery.json"
REFRESH = ROOT / "tools/mad4b_refresh_g9_fingerprints.py"
VALIDATOR = ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/g9-delivery-contract.py"


def git(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["git", *args], cwd=ROOT, text=True,
                          capture_output=True, check=False)


def fail(message: str) -> None:
    raise SystemExit("G9 PRE-COMMIT BLOCKED: " + message)


def run_python(script: Path, *args: str) -> int:
    completed = subprocess.run([sys.executable, str(script), *args],
                               cwd=ROOT, check=False)
    return completed.returncode


def main() -> None:
    branch = git("symbolic-ref", "--quiet", "--short", "HEAD")
    if branch.returncode or not branch.stdout.strip():
        fail("detached HEAD; prepare a named feature branch before changing source evidence")
    manifest_file = ROOT / MANIFEST
    if manifest_file.is_symlink() or not manifest_file.is_file():
        fail("G9 evidence manifest missing or symlinked")
    try:
        payload = json.loads(manifest_file.read_bytes())
        rows = payload["evidence_integrity"]
        paths = [row["path"] for row in rows]
        if (payload.get("contract") != "mad4b.feature007-g9-delivery.v1"
                or not isinstance(rows, list) or len(rows) < 10
                or not all(isinstance(path, str) and bool(path) for path in paths)
                or len(paths) != len(set(paths))):
            fail("G9 inventory is invalid; do not automatically change evidence")
    except (KeyError, ValueError, TypeError) as exc:
        fail("cannot parse exact G9 source inventory: " + str(exc))

    # Git's index MUST match disk for each evidence source and the manifest.
    # Otherwise the script could incorrectly hash unstaged bytes and certify
    # a different snapshot than the commit Git is actually creating.
    unstaged = git("diff", "--name-only", "--", *paths, MANIFEST)
    if unstaged.returncode:
        fail("cannot inspect unstaged changes")
    if unstaged.stdout.strip():
        fail("stage or revert all G9 fingerprinted source/manifest changes first: "
             + ", ".join(unstaged.stdout.splitlines()[:8]))

    checked = run_python(REFRESH)
    if checked not in (0, 1):
        fail("G9 check script failed; review source manually")

    if checked == 1:
        if branch.stdout.strip() in {"main", "master", "production", "release"}:
            fail("refuse automatic G9 repin on protected branch")
        if run_python(REFRESH, "--write") != 0:
            fail("G9 exact-inventory repin failed; do not commit")
        staged = git("add", "--", MANIFEST)
        if staged.returncode:
            fail("G9 manifest changed but could not be staged; do not commit")
        if run_python(REFRESH) != 0:
            fail("G9 post-repin digest check failed")

    if run_python(VALIDATOR) != 0:
        fail("independent G9 contract failed; commit denied, no verifier bypass")
    print("G9 PRE-COMMIT: PASS (exact fingerprinted source; no release authority)")


if __name__ == "__main__":
    main()

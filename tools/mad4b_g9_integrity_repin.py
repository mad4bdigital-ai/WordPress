#!/usr/bin/env python3
"""Guarded G9 workflow fingerprint review/repin; never a CI or release bypass.

Default is check-only. --apply requires independently reviewed exact workflow
SHA-256, a clean committed workflow, and zero unrelated G9 integrity drift.
--commit-pair additionally supports an *uncommitted* reviewed workflow change:
validate both working-tree files, then commit workflow and G9 manifest ATOMICALLY.
No broad source refresh, no automatic promotion, no release certification.
"""
from __future__ import annotations

import argparse
from hashlib import sha256
import json
import os
from pathlib import Path
import re
import runpy
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ".github/workflows/feature-007-spec-ci.yml"
MANIFEST = "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/g9-delivery.json"
SECURITY_TEST = "wp-content/plugins/mad4b-site-control-plane/tests/g9-security-source-contract.py"
G9_TEST = "wp-content/plugins/mad4b-site-control-plane/tests/g9-delivery-contract.py"
SHA = re.compile(r"^[a-f0-9]{64}$")


def stop(message: str) -> None:
    raise SystemExit("BLOCKED: " + message)


def file_bytes(root: Path, path: str) -> bytes:
    rel = Path(path)
    if not path or rel.is_absolute() or ".." in rel.parts or rel.as_posix() != path:
        stop("unsafe path " + path)
    f = root / rel
    if not f.is_file() or f.is_symlink():
        stop("missing or symlinked source " + path)
    if any(p.is_symlink() for p in f.parents if p.is_relative_to(root)):
        stop("source symlink ancestry " + path)
    return f.read_bytes()


def git(root: Path, *args: str) -> str:
    p = subprocess.run(["git", *args], cwd=root, text=True,
                       capture_output=True, check=False)
    if p.returncode:
        stop("git prerequisite: " + " ".join(args))
    return p.stdout.strip()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo-root", type=Path, default=ROOT)
    parser.add_argument("--apply", action="store_true",
                        help="Repin only after external workflow review")
    parser.add_argument("--reviewed-workflow-sha256", default="",
                        help="Exact SHA-256 reviewed by the source owner")
    parser.add_argument("--commit-pair", action="store_true",
                        help="Atomic reviewed workflow + G9 record Git commit")
    a = parser.parse_args()
    if a.commit_pair and not a.apply:
        stop("--commit-pair requires --apply")
    root = a.repo_root.resolve(strict=True)
    manifest_path = root / MANIFEST
    raw = file_bytes(root, MANIFEST)
    data = json.loads(raw)
    if data.get("contract") != "mad4b.feature007-g9-delivery.v1":
        stop("G9 delivery contract changed")
    records = data.get("evidence_integrity")
    if not isinstance(records, list) or len(records) < 10:
        stop("invalid G9 inventory")
    if len({r.get("path") for r in records if isinstance(r, dict)}) != len(records):
        stop("duplicate G9 fingerprint path")
    mismatches = []
    for row in records:
        if not isinstance(row, dict) or set(row) != {"path", "sha256", "bytes"}:
            stop("unexpected G9 entry shape")
        content = file_bytes(root, row["path"])
        observed = sha256(content).hexdigest()
        if row["sha256"] != observed or row["bytes"] != len(content):
            mismatches.append((row, observed, len(content)))
    if not mismatches:
        if a.commit_pair:
            stop("no G9 workflow drift to include in an atomic pair")
        print("G9 SOURCE INTEGRITY: PASS (all %s committed source digests match)" % len(records))
        return
    for row, digest, size in mismatches:
        print("DRIFT: %s recorded=%s/%s observed=%s/%s" %
              (row["path"], row["sha256"], row["bytes"], digest, size))
    if not a.apply:
        stop("G9 drift found; no changes made. Independently review the workflow.")
    if len(mismatches) != 1 or mismatches[0][0]["path"] != WORKFLOW:
        stop("only a reviewed standalone workflow drift can be repinned")
    row, digest, size = mismatches[0]
    if not SHA.fullmatch(a.reviewed_workflow_sha256) or digest != a.reviewed_workflow_sha256:
        stop("reviewed SHA must exactly match workflow contents")
    branch = git(root, "branch", "--show-current")
    if branch in {"main", "master", "production", "release"} or not branch:
        stop("repin only on named nonproduction feature branches")
    before_head = git(root, "rev-parse", "HEAD")
    if a.commit_pair:
        if not git(root, "diff", "--name-only", "HEAD", "--", WORKFLOW):
            stop("--commit-pair requires an uncommitted reviewed workflow change")
        if git(root, "diff", "--cached", "--name-only"):
            stop("clear staging area before atomic pair commit; unrelated staged files rejected")
        if git(root, "diff", "--name-only", "HEAD", "--", MANIFEST):
            stop("G9 manifest must be clean before guarded paired repin")
    else:
        git(root, "diff", "--exit-code", "HEAD", "--", WORKFLOW)
        git(root, "diff", "--cached", "--exit-code", "--", WORKFLOW)
    contract = runpy.run_path(str(root / SECURITY_TEST))
    if contract.get("WORKFLOW") != WORKFLOW or not callable(contract.get("verify_g9_workflow")):
        stop("security source contract missing")
    contract["verify_g9_workflow"]()
    old_line = ('"sha256": "' + row["sha256"] + '",\n'
                '      "bytes": ' + str(row["bytes"]))
    new_line = ('"sha256": "' + digest + '",\n'
                '      "bytes": ' + str(size))
    original = raw.decode("utf-8")
    if original.count(old_line) != 1:
        stop("reviewed record text changed or appears multiple times")
    updated = original.replace(old_line, new_line, 1)
    parsed = json.loads(updated)
    record = next(x for x in parsed["evidence_integrity"] if x["path"] == WORKFLOW)
    if record["sha256"] != digest or record["bytes"] != size:
        stop("repinned record mismatch")
    # The file is a repository artifact, not a signed approval or installation.
    try:
        manifest_path.write_text(updated, encoding="utf-8", newline="")
        check = subprocess.run([sys.executable, str(root / G9_TEST)],
                               cwd=root, text=True, capture_output=True, check=False)
        if check.returncode != 0:
            stop("post-repin G9 contract failed: " + (check.stderr or check.stdout)[-600:])
    except BaseException:
        manifest_path.write_bytes(raw)
        raise
    if a.commit_pair:
        if git(root, "rev-parse", "HEAD") != before_head:
            stop("HEAD changed during reviewed pair preparation; no Git commit created")
        git(root, "add", "--", WORKFLOW, MANIFEST)
        git(root, "commit", "-m",
            "ci(feature007): update reviewed workflow and G9 fingerprint atomically",
            "--only", "--", WORKFLOW, MANIFEST)
        after_head = git(root, "rev-parse", "HEAD")
        if after_head == before_head:
            stop("atomic workflow + G9 commit did not advance HEAD")
        changed = set(git(root, "diff-tree", "--no-commit-id", "--name-only",
                          "-r", after_head).splitlines())
        if changed != {WORKFLOW, MANIFEST}:
            stop("unexpected atomic commit scope: " + repr(sorted(changed)))
        print("G9 PAIRED COMMIT: PASS (HEAD=%s; workflow and G9 manifest in ONE commit)" % after_head)
    else:
        print("G9 REPIN: PASS (only reviewed workflow SHA/bytes; verify git diff and commit)")
    print("No CI PASS, Staging deployment, release approval, or Production access asserted.")


if __name__ == "__main__":
    main()

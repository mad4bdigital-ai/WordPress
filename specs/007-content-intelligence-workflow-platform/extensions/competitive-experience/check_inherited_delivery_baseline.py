#!/usr/bin/env python3
"""Verify inherited Feature 007 evidence against its immutable base, and review changed runtime bindings.

A non-Feature PR must not recertify old G2-G9 delivery evidence merely because it
modifies a shared plugin bootstrap. This verifier rejects changes to manifests,
checks every baseline fingerprint against base source, rejects unreviewed drift,
and requires exact-head Production Readiness records for changed bound files.
"""
import argparse
import hashlib
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
EXT = "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/"
GROUPS = ("code_paths", "test_paths", "spec_paths", "workflow_paths", "evidence_integrity")
REVIEW = "governance/production-readiness/capability-coverage.json"

def git(*args):
    return subprocess.check_output(["git", *args], cwd=ROOT)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    args = parser.parse_args()
    base, head = args.base, args.head
    for ref in (base, head):
        if len(ref) != 40 or any(c not in "0123456789abcdef" for c in ref):
            raise ValueError("Expected immutable full Git SHA")
    manifests = sorted((ROOT / EXT).glob("*-delivery.json"))
    if len(manifests) < 10:
        raise ValueError("Missing Feature 007 delivery manifests")
    coverage = json.loads(git("show", head + ":" + REVIEW))
    entries = coverage.get("reviewed_changes", [])
    if not entries:
        entries = [row for value in coverage.values() if isinstance(value, list)
                   for row in value if isinstance(row, dict) and "source_blob_sha" in row]
    reviewed = {}
    for row in entries:
        reviewed.setdefault(row.get("path"), []).append(row)
    checked = 0
    changed = set()
    for manifest in manifests:
        path = manifest.relative_to(ROOT).as_posix()
        historical = git("show", base + ":" + path)
        current = git("show", head + ":" + path)
        if historical != current:
            raise ValueError("FEATURE007_MANIFEST_REVIEW_REQUIRED:" + path)
        payload = json.loads(historical)
        seen = set()
        for group in GROUPS:
            for row in payload.get(group, []):
                if not isinstance(row, dict) or "sha256" not in row:
                    continue
                source = row["path"]
                if source in seen:
                    raise ValueError("FEATURE007_DUPLICATE_BINDING:" + source)
                seen.add(source)
                baseline = git("show", base + ":" + source)
                if hashlib.sha256(baseline).hexdigest() != row["sha256"]:
                    raise ValueError("FEATURE007_BASELINE_HASH_MISMATCH:" + source)
                if "bytes" in row and len(baseline) != row["bytes"]:
                    raise ValueError("FEATURE007_BASELINE_SIZE_MISMATCH:" + source)
                current_source = git("show", head + ":" + source)
                if current_source != baseline:
                    changed.add(source)
                    blob = git("rev-parse", head + ":" + source).decode().strip()
                    matches = [r for r in reviewed.get(source, [])
                               if r.get("source_blob_sha") == blob
                               and r.get("status") == "reviewed_for_ci_only"
                               and not r.get("production_authorized")
                               and r.get("live_evidence_required")
                               and r.get("regression_tests")]
                    if not matches:
                        raise ValueError("FEATURE007_CHANGED_BINDING_UNREVIEWED:" + source)
                checked += 1
    print("FEATURE007_INHERITED_BASELINE: PASS bindings=%d reviewed_changed_paths=%d" %
          (checked, len(changed)))
    print("FEATURE007_INHERITED_BASELINE_CHANGED_PATHS: " + ",".join(sorted(changed)))

if __name__ == "__main__":
    main()

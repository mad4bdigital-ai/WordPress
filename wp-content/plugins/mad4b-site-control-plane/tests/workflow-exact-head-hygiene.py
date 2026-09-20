#!/usr/bin/env python3
"""Fail closed when PR workflows stop validating the exact pull-request head.

The guard also prevents SHA-scoped concurrency groups (which keep stale runs
alive) and interactive unzip into a shared WordPress plugin directory.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[4]
WORKFLOWS = REPO / ".github" / "workflows"
EXACT_REF = "github.event.pull_request.head.sha || github.sha"
SOURCE_REF = "env.SOURCE_SHA"
SOURCE_BINDING = "SOURCE_SHA: ${{ github.event.pull_request.head.sha || github.sha }}"

def indent(line: str) -> int:
    return len(line) - len(line.lstrip(" "))

def checkout_block(lines: list[str], index: int) -> tuple[str, list[str]]:
    line = lines[index]
    base = indent(line)
    list_form = line.strip().startswith("- uses:")
    expected_with = base + (2 if list_form else 0)
    j = index + 1
    while j < len(lines) and not lines[j].strip():
        j += 1
    if j >= len(lines) or lines[j].strip() != "with:" or indent(lines[j]) != expected_with:
        return "", []
    block = []
    j += 1
    while j < len(lines) and (not lines[j].strip() or indent(lines[j]) > expected_with):
        block.append(lines[j])
        j += 1
    ref = ""
    for item in block:
        if item.strip().startswith("ref:"):
            ref = item.strip()[4:].strip()
            break
    return ref, block

def inspect(path: Path) -> list[dict]:
    text = path.read_text("utf-8")
    if "pull_request:" not in text:
        return []
    lines = text.splitlines()
    issues: list[dict] = []

    for i, line in enumerate(lines):
        if "uses: actions/checkout@v4" not in line:
            continue
        ref, _ = checkout_block(lines, i)
        if not ref:
            issues.append({"workflow": path.name, "line": i + 1, "reason": "checkout_missing_exact_ref"})
            continue
        if EXACT_REF in ref:
            continue
        if SOURCE_REF in ref and SOURCE_BINDING in text:
            continue
        issues.append({"workflow": path.name, "line": i + 1, "reason": "checkout_not_bound_to_exact_pr_head", "ref": ref})

    group_lines = [line.strip() for line in lines if line.strip().startswith("group:")]
    if not group_lines:
        issues.append({"workflow": path.name, "reason": "missing_concurrency_group"})
    else:
        group = group_lines[0]
        if "github.event.pull_request.head.sha" in group or "github.sha" in group:
            issues.append({"workflow": path.name, "reason": "sha_scoped_concurrency_keeps_stale_runs"})
        if "github.event.pull_request.number" not in group or "github.ref" not in group:
            issues.append({"workflow": path.name, "reason": "concurrency_not_scoped_to_pr_or_ref", "group": group})
    if "cancel-in-progress: true" not in text:
        issues.append({"workflow": path.name, "reason": "stale_run_cancellation_disabled"})

    for i, line in enumerate(lines):
        if re.search(r"\bunzip\s+-q\s+", line) and "$WP_PATH/wp-content/plugins" in line:
            issues.append({"workflow": path.name, "line": i + 1, "reason": "interactive_unzip_into_shared_plugin_directory"})

    return issues

def main() -> int:
    issues = []
    files = sorted(list(WORKFLOWS.glob("*.yml")) + list(WORKFLOWS.glob("*.yaml")))
    for path in files:
        issues.extend(inspect(path))
    report = {
        "contract": "mad4b.workflow-exact-head-hygiene.v1",
        "workflow_count": len(files),
        "issues": issues,
        "status": "passed" if not issues else "failed",
    }
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if not issues else 1

if __name__ == "__main__":
    sys.exit(main())

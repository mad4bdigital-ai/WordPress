#!/usr/bin/env python3
"""Fail closed when PR workflows stop validating the exact pull-request head.

Exact-source concurrency must isolate evidence events as well as source heads.
A stale queued retry must not cancel checks for a newer candidate. Independent
old checks may finish, but checkout and release evidence remain exact-head bound.
The guard also prevents interactive unzip into a shared plugin directory.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[4]
WORKFLOWS = REPO / ".github" / "workflows"
EXACT_REF = "github.event.pull_request.head.sha || github.sha"
CONCURRENCY_REF = "github.event.pull_request.head.sha || github.ref"
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
    return inspect_text(path.read_text("utf-8"), path.name)

def inspect_text(text: str, workflow: str) -> list[dict]:
    if "pull_request:" not in text:
        return []
    lines = text.splitlines()
    issues: list[dict] = []

    for i, line in enumerate(lines):
        if "uses: actions/checkout@v4" not in line:
            continue
        ref, _ = checkout_block(lines, i)
        if not ref:
            issues.append({"workflow": workflow, "line": i + 1, "reason": "checkout_missing_exact_ref"})
            continue
        if EXACT_REF in ref:
            continue
        if SOURCE_REF in ref and SOURCE_BINDING in text:
            continue
        issues.append({"workflow": workflow, "line": i + 1, "reason": "checkout_not_bound_to_exact_pr_head", "ref": ref})

    group_lines = [line.strip() for line in lines if line.strip().startswith("group:")]
    if not group_lines:
        issues.append({"workflow": workflow, "reason": "missing_concurrency_group"})
    else:
        group = group_lines[0]
        if "github.event.pull_request.head.sha" in group or "github.sha" in group:
            if CONCURRENCY_REF not in group or "github.event_name" not in group:
                issues.append({"workflow": workflow, "reason": "concurrency_source_or_event_binding_missing"})
        if "github.event.pull_request.number" not in group or "github.ref" not in group:
            issues.append({"workflow": workflow, "reason": "concurrency_not_scoped_to_pr_or_ref", "group": group})
    if "cancel-in-progress: true" not in text:
        issues.append({"workflow": workflow, "reason": "duplicate_run_cancellation_disabled"})

    for i, line in enumerate(lines):
        if re.search(r"\bunzip\b", line) and "$WP_PATH/wp-content/plugins" in line:
            issues.append({"workflow": workflow, "line": i + 1, "reason": "direct_archive_extract_into_shared_plugin_directory"})

    return issues

def self_test() -> None:
    legacy = "ci-${{ github.event.pull_request.number || github.ref }}"
    exact = legacy + "-${{ github.event_name }}-${{ " + CONCURRENCY_REF + " }}"
    def fixture(group: str, ref: str = EXACT_REF, cancel: str = "true") -> str:
        return (
            "on:\n  pull_request:\nconcurrency:\n  group: " + group
            + "\n  cancel-in-progress: " + cancel
            + "\njobs:\n  check:\n    steps:\n      - uses: actions/checkout@v4"
            + "\n        with:\n          ref: ${{ " + ref + " }}\n"
        )
    assert not inspect_text(fixture(legacy), "legacy.yml")
    assert not inspect_text(fixture(exact), "exact.yml")
    cases = [
        (fixture(legacy + "-${{ " + CONCURRENCY_REF + " }}"), "concurrency_source_or_event_binding_missing"),
        (fixture(legacy + "-${{ github.event_name }}-${{ " + EXACT_REF + " }}"), "concurrency_source_or_event_binding_missing"),
        (fixture(legacy + "-${{ github.event_name }}-${{ github.sha }}"), "concurrency_source_or_event_binding_missing"),
        (fixture(exact, ref="github.ref"), "checkout_not_bound_to_exact_pr_head"),
        (fixture(exact, cancel="false"), "duplicate_run_cancellation_disabled"),
        (fixture(exact) + '          run: unzip package.zip -d "$WP_PATH/wp-content/plugins"\n', "direct_archive_extract_into_shared_plugin_directory"),
    ]
    for text, expected in cases:
        assert expected in {issue["reason"] for issue in inspect_text(text, "negative.yml")}, expected
    def key(sha: str, event: str, pr: int = 276) -> str:
        values = {"github.event.pull_request.number || github.ref": str(pr or "refs/heads/master"), "github.event_name": event, CONCURRENCY_REF: sha if pr else "refs/heads/master"}
        return re.sub(r"\$\{\{\s*(.*?)\s*\}\}", lambda match: values[match.group(1)], exact)
    assert key("a" * 40, "pull_request") != key("b" * 40, "pull_request")
    assert key("b" * 40, "pull_request") != key("b" * 40, "pull_request_target")
    assert key("a" * 40, "push", pr=0) == key("b" * 40, "push", pr=0)

def main() -> int:
    self_test()
    issues = []
    files = sorted(list(WORKFLOWS.glob("*.yml")) + list(WORKFLOWS.glob("*.yaml")))
    for path in files:
        issues.extend(inspect(path))
    report = {
        "contract": "mad4b.workflow-exact-head-hygiene.v2",
        "workflow_count": len(files),
        "issues": issues,
        "status": "passed" if not issues else "failed",
    }
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if not issues else 1

if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""Regression contract for exact-head GitHub Actions event scoping."""

from __future__ import annotations

import importlib.util
from pathlib import Path

MODULE_PATH = Path(__file__).with_name("capture_exact_head_action_jobs.py")
spec = importlib.util.spec_from_file_location("mad4b_capture_exact_head_action_jobs", MODULE_PATH)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load capture_exact_head_action_jobs.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

SHA = "a" * 40


def run(*, event: str, branch: str = "master", prs=None):
    return {
        "head_sha": SHA,
        "head_branch": branch,
        "event": event,
        "pull_requests": [] if prs is None else [{"number": n} for n in prs],
    }


def matches(row, *, branch="master", pr=0, event="push"):
    return module.run_matches_scope(
        row,
        head_sha=SHA,
        head_branch=branch,
        pr_number=pr,
        event_name=event,
    )


assert matches(run(event="push"))
assert not matches(run(event="pull_request"))
assert not matches(run(event="workflow_dispatch"))
assert not matches(run(event="push", branch="feature"))
assert matches(run(event="push"), event="workflow_dispatch")
assert not matches(run(event="workflow_dispatch"), event="workflow_dispatch")
assert module.canonical_evidence_event(event_name="workflow_dispatch", pr_number=0) == "push"

assert module.canonical_evidence_event(event_name="push", pr_number=0) == "push"
assert module.canonical_evidence_event(event_name="pull_request", pr_number=193) == "pull_request"

assert matches(
    run(event="pull_request", branch="feature", prs=[193]),
    branch="feature",
    pr=193,
    event="pull_request",
)
assert not matches(
    run(event="push", branch="feature", prs=[193]),
    branch="feature",
    pr=193,
    event="pull_request",
)
assert not matches(
    run(event="pull_request", branch="feature", prs=[194]),
    branch="feature",
    pr=193,
    event="pull_request",
)
assert matches(
    run(event="pull_request", branch="feature"),
    branch="feature",
    pr=193,
    event="pull_request",
)

# Compatibility for explicit non-Actions callers that provide no event.
assert matches(run(event="pull_request"), event="")

print("mad4b.exact-head-action-jobs.v2: PASS")

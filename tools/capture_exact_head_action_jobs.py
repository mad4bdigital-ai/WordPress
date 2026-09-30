#!/usr/bin/env python3
"""Capture exact-head GitHub Actions jobs in check-run-compatible shape."""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import time
from pathlib import Path

CONTRACT = "mad4b.exact-head-action-jobs.v2"
MAX_RUN_PAGES = 5
MAX_JOB_PAGES = 5
GH_API_MAX_ATTEMPTS = 6
GH_API_RETRY_BASE_SECONDS = 2
GH_API_RETRY_MAX_SECONDS = 20
GH_API_TRANSIENT_HTTP_STATUSES = frozenset({429, 500, 502, 503, 504})
GH_API_TRANSIENT_ERROR_MARKERS = (
    "server error",
    "timeout",
    "timed out",
    "connection reset",
    "connection refused",
    "tls handshake timeout",
    "temporary failure",
    "unexpected eof",
    "secondary rate limit",
    "rate limit exceeded",
)
TRUSTED_PULL_REQUEST_TARGET_WORKFLOWS = (
    ".github/workflows/mad4b-feature-boundary-root.yml",
)


def gh_api_failure_is_transient(stderr: str) -> bool:
    """Retry only bounded GitHub/network failures; permanent API errors fail closed."""
    normalized = (stderr or "").strip().lower()
    status_match = re.search(r"\bhttp\s+(\d{3})\b", normalized)
    status = int(status_match.group(1)) if status_match else None
    if status in GH_API_TRANSIENT_HTTP_STATUSES:
        return True
    return any(marker in normalized for marker in GH_API_TRANSIENT_ERROR_MARKERS)


def gh_json(endpoint: str):
    """Fetch JSON from GitHub with bounded retry for transient infrastructure failures."""
    command = [
        "gh",
        "api",
        "-H",
        "Accept: application/vnd.github+json",
        endpoint,
    ]
    for attempt in range(1, GH_API_MAX_ATTEMPTS + 1):
        completed = subprocess.run(
            command,
            text=True,
            capture_output=True,
            check=False,
        )
        if completed.returncode == 0:
            return json.loads(completed.stdout)

        stderr = completed.stderr or ""
        if (
            attempt >= GH_API_MAX_ATTEMPTS
            or not gh_api_failure_is_transient(stderr)
        ):
            raise subprocess.CalledProcessError(
                completed.returncode,
                command,
                output=completed.stdout,
                stderr=stderr,
            )

        delay = min(
            GH_API_RETRY_BASE_SECONDS * (2 ** (attempt - 1)),
            GH_API_RETRY_MAX_SECONDS,
        )
        print(
            "GitHub API transient failure; retrying "
            f"attempt={attempt + 1}/{GH_API_MAX_ATTEMPTS} delay_seconds={delay}: "
            f"{stderr.strip()}",
            file=sys.stderr,
        )
        time.sleep(delay)

    raise AssertionError("unreachable GitHub API retry state")


def pull_request_numbers(run: dict) -> list[int]:
    rows = run.get("pull_requests", [])
    if not isinstance(rows, list):
        return []
    numbers = []
    for row in rows:
        if not isinstance(row, dict):
            continue
        try:
            number = int(row.get("number") or 0)
        except (TypeError, ValueError):
            continue
        if number > 0:
            numbers.append(number)
    return sorted(set(numbers))


def canonical_evidence_events(*, event_name: str, pr_number: int) -> tuple[str, ...]:
    """Resolve the only run events trusted for this verdict invocation."""
    event_name = event_name.strip()
    if event_name == "pull_request" and pr_number > 0:
        # Repository Feature Boundary intentionally runs from the trusted base
        # under pull_request_target. It is the only target-event evidence that
        # may join the exact PR's ordinary pull_request evidence.
        return ("pull_request", "pull_request_target")
    if event_name == "push" and pr_number == 0:
        return ("push",)
    if event_name == "workflow_dispatch" and pr_number == 0:
        # Manual verdicts explicitly re-evaluate canonical push evidence for the
        # selected exact branch/SHA. They do not mix workflow_dispatch jobs into
        # release evidence.
        return ("push",)
    return ()


def run_matches_scope(
    run: dict,
    *,
    head_sha: str,
    head_branch: str,
    pr_number: int,
    event_name: str,
) -> bool:
    run_head = str(run.get("head_sha") or "").strip().lower()
    if run_head != head_sha:
        return False

    run_branch = str(run.get("head_branch") or "").strip()
    run_event = str(run.get("event") or "").strip()
    run_path = str(run.get("path") or "").strip()
    associated_prs = pull_request_numbers(run)
    evidence_events = canonical_evidence_events(
        event_name=event_name,
        pr_number=pr_number,
    )

    # Missing/unsupported invocation provenance is never equivalent to
    # head-SHA-only scope. Fail closed instead of silently widening evidence.
    if not evidence_events or run_event not in evidence_events:
        return False

    if pr_number > 0:
        # PR evidence must prove the exact PR association. Branch equality alone
        # is insufficient because GitHub can expose same-SHA pull_request runs
        # with head_branch=master and no pull_requests association.
        if pr_number not in associated_prs:
            return False
        if head_branch and run_branch != head_branch:
            return False
        if run_event == "pull_request_target":
            return run_path in TRUSTED_PULL_REQUEST_TARGET_WORKFLOWS
        return run_event == "pull_request"

    # Push/manual replay evidence is canonical push evidence from the exact
    # selected branch only.
    if head_branch and run_branch != head_branch:
        return False
    return run_event == "push"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", required=True)
    parser.add_argument("--head-sha", required=True)
    parser.add_argument(
        "--event",
        required=True,
        choices=("push", "pull_request", "workflow_dispatch"),
        help="Release Verdict invocation event; evidence is derived explicitly from this mode.",
    )
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()

    repository = args.repository.strip()
    head_sha = args.head_sha.strip().lower()
    head_branch = __import__("os").environ.get("HEAD_BRANCH", "").strip()
    event_name = args.event.strip()
    raw_pr_number = __import__("os").environ.get("PR_NUMBER", "").strip()
    try:
        pr_number = int(raw_pr_number or "0")
    except ValueError as exc:
        raise SystemExit("PR_NUMBER must be an integer when provided") from exc
    if pr_number < 0:
        raise SystemExit("PR_NUMBER may not be negative")
    if "/" not in repository:
        raise SystemExit("repository must use owner/name form")
    if len(head_sha) != 40 or any(ch not in "0123456789abcdef" for ch in head_sha):
        raise SystemExit("head SHA must be an exact 40-character lowercase SHA")

    evidence_events = canonical_evidence_events(
        event_name=event_name,
        pr_number=pr_number,
    )
    if not evidence_events:
        raise SystemExit(
            "unsupported Release Verdict event/PR scope: "
            f"event={event_name!r} pull_request={pr_number}"
        )

    runs = []
    for page in range(1, MAX_RUN_PAGES + 1):
        payload = gh_json(
            f"repos/{repository}/actions/runs"
            f"?head_sha={head_sha}&per_page=100&page={page}"
        )
        page_rows = payload.get("workflow_runs", [])
        if not isinstance(page_rows, list):
            raise SystemExit("workflow-runs response shape invalid")
        runs.extend(row for row in page_rows if isinstance(row, dict))
        if len(page_rows) < 100:
            break
    else:
        raise SystemExit("workflow-run pagination exceeded safety bound")

    jobs = []
    seen_run_ids = set()
    for run in runs:
        run_id = int(run.get("id") or 0)
        if run_id < 1 or run_id in seen_run_ids:
            continue
        if not run_matches_scope(
            run,
            head_sha=head_sha,
            head_branch=head_branch,
            pr_number=pr_number,
            event_name=event_name,
        ):
            continue
        seen_run_ids.add(run_id)
        run_head = str(run.get("head_sha") or "").lower()
        run_branch = str(run.get("head_branch") or "").strip()
        run_event = str(run.get("event") or "").strip()
        associated_prs = pull_request_numbers(run)

        for page in range(1, MAX_JOB_PAGES + 1):
            payload = gh_json(
                f"repos/{repository}/actions/runs/{run_id}/jobs"
                f"?per_page=100&page={page}"
            )
            page_jobs = payload.get("jobs", [])
            if not isinstance(page_jobs, list):
                raise SystemExit(f"jobs response shape invalid for run {run_id}")
            for job in page_jobs:
                if not isinstance(job, dict):
                    continue
                jobs.append(
                    {
                        "id": int(job.get("id") or 0),
                        "name": str(job.get("name") or ""),
                        "status": str(job.get("status") or ""),
                        "conclusion": job.get("conclusion"),
                        "workflow_run_id": run_id,
                        "workflow_name": str(run.get("name") or ""),
                        "workflow_path": str(run.get("path") or ""),
                        "workflow_event": run_event,
                        "workflow_head_branch": run_branch,
                        "workflow_pull_requests": associated_prs,
                        "run_attempt": int(run.get("run_attempt") or 1),
                        "head_sha": run_head,
                    }
                )
            if len(page_jobs) < 100:
                break
        else:
            raise SystemExit(f"job pagination exceeded safety bound for run {run_id}")

    jobs = [row for row in jobs if row["id"] > 0 and row["name"]]
    jobs.sort(key=lambda row: row["id"])

    scope_mode = (
        "exact_pr_event_provenance"
        if pr_number > 0
        else (
            "manual_replay_of_exact_push"
            if event_name == "workflow_dispatch"
            else "exact_branch_push_event"
        )
    )
    result = {
        "contract": CONTRACT,
        "repository": repository,
        "head_sha": head_sha,
        "head_branch": head_branch,
        "event_name": event_name,
        "evidence_events": list(evidence_events),
        "trusted_pull_request_target_workflows": list(
            TRUSTED_PULL_REQUEST_TARGET_WORKFLOWS
        ),
        "pull_request": pr_number,
        "scope_mode": scope_mode,
        "workflow_run_count": len(seen_run_ids),
        "job_count": len(jobs),
        # Preserve the legacy key so the release-verdict parser remains unchanged.
        "check_runs": jobs,
        "source": "github_actions_runs_jobs_api",
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(
        "EXACT_HEAD_ACTION_JOBS: PASS "
        f"scope={scope_mode} runs={result['workflow_run_count']} jobs={result['job_count']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Capture exact-head GitHub Actions jobs in check-run-compatible shape."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
from pathlib import Path

CONTRACT = "mad4b.exact-head-action-jobs.v2"
MAX_RUN_PAGES = 5
MAX_JOB_PAGES = 5


def gh_json(endpoint: str):
    raw = subprocess.check_output(
        [
            "gh",
            "api",
            "-H",
            "Accept: application/vnd.github+json",
            endpoint,
        ],
        text=True,
    )
    return json.loads(raw)


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
    event_name = event_name.strip()
    if pr_number > 0:
        # PR release evidence is intentionally split across the untrusted-head
        # pull_request workflows and the BASE-owned pull_request_target feature
        # boundary. Both are exact-head evidence for the same PR, and both must
        # remain visible to the verdict while unrelated push/manual runs stay out.
        if event_name == "pull_request":
            return ("pull_request", "pull_request_target")
        return (event_name,) if event_name else ()
    # A manual verdict is a re-evaluation of the canonical branch push, not a
    # new source of runtime/release evidence. Binding workflow_dispatch to
    # workflow_dispatch would make all ordinary push-produced critical checks
    # invisible and would turn manual re-evaluation into a guaranteed timeout.
    if event_name == "workflow_dispatch":
        return ("push",)
    return (event_name,) if event_name else ()


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
    associated_prs = pull_request_numbers(run)
    evidence_events = canonical_evidence_events(
        event_name=event_name,
        pr_number=pr_number,
    )

    # Exact-head evidence is event-bound as well as SHA/branch-bound. Without
    # this guard, a later pull_request run whose synthetic merge SHA equals the
    # current master SHA can overwrite canonical push evidence for the same job
    # name when the verdict selects the newest check-run id.
    if evidence_events and run_event not in evidence_events:
        return False

    if pr_number > 0:
        # Prefer GitHub's explicit PR association when it exists. Some
        # pull_request_target/legacy runs omit pull_requests, so allow those only
        # when their head branch exactly matches the current PR branch.
        if associated_prs:
            return pr_number in associated_prs
        return bool(head_branch and run_branch == head_branch)

    # Push/manual verdicts must not ingest same-SHA jobs from a PR branch.
    if head_branch:
        return run_branch == head_branch
    return True


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", required=True)
    parser.add_argument("--head-sha", required=True)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()

    repository = args.repository.strip()
    head_sha = args.head_sha.strip().lower()
    head_branch = os.environ.get("HEAD_BRANCH", "").strip()
    event_name = os.environ.get("GITHUB_EVENT_NAME", "").strip()
    raw_pr_number = os.environ.get("PR_NUMBER", "").strip()
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
                        "workflow_event": str(run.get("event") or ""),
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

    result = {
        "contract": CONTRACT,
        "repository": repository,
        "head_sha": head_sha,
        "head_branch": head_branch,
        "event_name": event_name,
        "evidence_events": list(
            canonical_evidence_events(
                event_name=event_name,
                pr_number=pr_number,
            )
        ),
        "pull_request": pr_number,
        "scope_mode": (
            "pull_request+event"
            if pr_number > 0 and event_name
            else (
                "pull_request"
                if pr_number > 0
                else (
                    "head_branch+event"
                    if head_branch and event_name
                    else ("head_branch" if head_branch else ("event" if event_name else "head_sha_only"))
                )
            )
        ),
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
        f"runs={result['workflow_run_count']} jobs={result['job_count']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

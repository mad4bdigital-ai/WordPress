#!/usr/bin/env python3
"""Capture exact-head GitHub Actions jobs in check-run-compatible shape."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
from pathlib import Path

CONTRACT = "mad4b.exact-head-action-jobs.v1"
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


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", required=True)
    parser.add_argument("--head-sha", required=True)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()

    repository = args.repository.strip()
    head_sha = args.head_sha.strip().lower()
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
        seen_run_ids.add(run_id)
        run_head = str(run.get("head_sha") or "").lower()
        if run_head != head_sha:
            continue

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

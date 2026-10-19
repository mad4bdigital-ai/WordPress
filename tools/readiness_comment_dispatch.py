#!/usr/bin/env python3
"""Governed PR issue-comment rerun of an exact-head Readiness CI attempt.

Only reruns an existing PR-sourced workflow. No checkout of PR content,
shell evaluation of comment text, grant, release, or Production write.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import subprocess
import sys

COMMAND = re.compile(r"/run-production-readiness ([0-9a-f]{40})\Z")
WORKFLOW = "mad4b-production-readiness-matrix.yml"
FULL_REPO = re.compile(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\Z")


class Denied(ValueError):
    pass


def require(ok: bool, message: str) -> None:
    if not ok:
        raise Denied(message)


def read_command(value: str) -> str:
    match = COMMAND.fullmatch(value.strip())
    require(match is not None, "Exact command and full 40-character SHA required")
    return match.group(1)


def event_scope(kind: str, event: dict, owner_logins: set[str]) -> tuple[str, int, str]:
    require(kind == "issue_comment", "Only GitHub-supported PR issue_comment events are executable")
    require(event.get("action") == "created", "Only newly created comments are executable")
    comment = event.get("comment") or {}
    sender = (event.get("sender") or {}).get("login", "")
    login = (comment.get("user") or {}).get("login", "")
    require(isinstance(login, str) and login.lower() in owner_logins
            and sender == login, "Requester is not an enrolled owner")
    requested = read_command(str(comment.get("body") or ""))
    issue = event.get("issue") or {}
    pr_number = issue.get("number")
    require(isinstance(pr_number, int) and pr_number > 0
            and isinstance(issue.get("pull_request"), dict),
            "Command must appear in a Pull Request conversation")
    return requested, pr_number, "pr_comment"


def select_pr(repo: str, requested: str, number: int, get) -> tuple[int, dict]:
    pr = get(f"repos/{repo}/pulls/{number}")
    head, base = pr.get("head") or {}, pr.get("base") or {}
    require(pr.get("state") == "open" and isinstance(pr.get("number"), int),
            "PR must be open")
    require(head.get("sha") == requested and (head.get("repo") or {}).get("full_name") == repo,
            "PR exact HEAD changed or source repository differs")
    require(base.get("ref") == "master", "PR must target the protected master branch")
    require(bool(re.fullmatch(r"[0-9a-f]{40}", str(base.get("sha") or ""))),
            "Current base SHA not readable")
    require(bool(head.get("ref")), "Source branch is missing")
    return pr["number"], pr


def select_run(repo: str, sha: str, pr: dict, get) -> dict:
    url = f"repos/{repo}/actions/workflows/{WORKFLOW}/runs?event=pull_request&per_page=100"
    matches = []
    for page in range(1, 6):
        payload = get(f"{url}&page={page}")
        rows = payload.get("workflow_runs")
        require(isinstance(rows, list), "Missing trusted workflow run inventory")
        for run in rows:
            prs = run.get("pull_requests") or []
            # Re-running a stale PR event is NOT an acceptable current-base
            # check. Require GitHub's immutable event PR head and base SHAs.
            associations = [
                row for row in prs
                if row.get("number") == pr["number"]
                and (row.get("head") or {}).get("sha") == sha
                and (row.get("base") or {}).get("sha") == pr["base"]["sha"]
            ]
            if (run.get("event") == "pull_request"
                    and run.get("head_sha") == sha
                    and run.get("head_branch") == pr["head"]["ref"]
                    and associations):
                matches.append(run)
        if len(rows) < 100:
            break
    else:
        require(False, "Run inventory exceeded bounded pagination")
    require(matches, "No exact-head PR-sourced Readiness run; await normal PR CI")
    latest = max(matches, key=lambda x: int(x["id"]))
    require(latest.get("status") in {"completed", "queued", "in_progress", "waiting", "pending"},
            "Unknown GitHub run status")
    return latest


def process(kind: str, event: dict, policy: dict, repo: str, get, post) -> dict:
    require(bool(FULL_REPO.fullmatch(repo or "")), "Invalid repository identity")
    enrolled = policy.get("single_owner_safety", {}).get("authorized_owner_logins", [])
    require(isinstance(enrolled, list) and bool(enrolled), "Missing enrolled owners")
    owners = {x.lower() for x in enrolled if isinstance(x, str)}
    sha, number, surface = event_scope(kind, event, owners)
    pr_number, pr = select_pr(repo, sha, number, get)
    run = select_run(repo, sha, pr, get)
    status, run_id = run["status"], int(run["id"])
    if status != "completed":
        outcome = "ALREADY_ACTIVE"
    elif run.get("conclusion") == "success":
        outcome = "ALREADY_SUCCESS"
    else:
        require(run.get("conclusion") in {"failure", "cancelled", "timed_out"},
                "The previous run is not eligible for safe retry")
        outcome = "RERUN_REQUESTED"
    if outcome == "RERUN_REQUESTED":
        # TOCTOU fence: the source PR and original run must still identify
        # the same head/base immediately before this side effect.
        live_pr = get(f"repos/{repo}/pulls/{pr_number}")
        live_head, live_base = live_pr.get("head") or {}, live_pr.get("base") or {}
        require(live_pr.get("state") == "open"
                and live_head.get("sha") == sha
                and live_head.get("ref") == pr["head"]["ref"]
                and (live_head.get("repo") or {}).get("full_name") == repo
                and live_base.get("ref") == "master"
                and live_base.get("sha") == pr["base"]["sha"],
                "PR moved while preparing readiness rerun")
        live_run = get(f"repos/{repo}/actions/runs/{run_id}")
        associated = [
            item for item in (live_run.get("pull_requests") or [])
            if item.get("number") == pr_number
            and (item.get("head") or {}).get("sha") == sha
            and (item.get("base") or {}).get("sha") == pr["base"]["sha"]
        ]
        require(live_run.get("id") == run_id
                and live_run.get("event") == "pull_request"
                and live_run.get("head_sha") == sha
                and live_run.get("head_branch") == pr["head"]["ref"]
                and live_run.get("status") == "completed"
                and live_run.get("conclusion") in {"failure", "cancelled", "timed_out"}
                and associated,
                "Run moved or no longer retryable")
        post(f"repos/{repo}/actions/runs/{run_id}/rerun")
    return {
        "contract": "mad4b.readiness-comment-dispatch.v1",
        "surface": surface,
        "pr_number": pr_number,
        "exact_head_sha": sha,
        "run_id": run_id,
        "outcome": outcome,
        "source_run_event": "pull_request",
        "production_authorized": False,
        "mutation_scope": "actions_rerun_only" if outcome == "RERUN_REQUESTED" else "none",
    }


def api(method: str, path: str):
    result = subprocess.run(["gh", "api", "--method", method, path],
                            capture_output=True, text=True, check=False, timeout=30)
    require(result.returncode == 0, "GitHub API request denied or failed")
    if method == "POST":
        return None
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise Denied("Invalid GitHub API response") from exc


def main() -> int:
    try:
        event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text(encoding="utf-8"))
        policy = json.loads(Path(".github/mad4b-repository-governance-policy.json").read_text(encoding="utf-8"))
        outcome = process(
            os.environ["GITHUB_EVENT_NAME"], event, policy,
            os.environ["GITHUB_REPOSITORY"],
            lambda path: api("GET", path),
            lambda path: api("POST", path),
        )
        print(json.dumps(outcome, sort_keys=True))
        with Path(os.environ["GITHUB_STEP_SUMMARY"]).open("a", encoding="utf-8") as output:
            output.write(
                "### Read-only Readiness rerun\n"
                + f"PR #{outcome['pr_number']} — {outcome['exact_head_sha']}\n\n"
                + f"Result: **{outcome['outcome']}**; Production: **NO-GO**.\n"
            )
        return 0
    except (Denied, OSError, KeyError, TypeError, ValueError, subprocess.TimeoutExpired) as exc:
        print("READINESS_COMMENT_DISPATCH_DENIED:", exc, file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())

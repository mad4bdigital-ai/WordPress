#!/usr/bin/env python3
"""Cancel only superseded PR CI runs, preserving exact-head workflow isolation."""
import argparse
import datetime
import time
import json
import os
import urllib.error
import urllib.parse
import urllib.request

ACTIVE_STATES = ("queued", "in_progress", "waiting", "pending", "requested")
MAX_PAGES_PER_STATE = 10
MAX_SPLIT_DEPTH = 22
MAX_CANCEL_PER_RUN = 400


def api(method, repository, path, token):
    request = urllib.request.Request(
        "https://api.github.com/repos/" + repository + path,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": "Bearer " + token,
            "User-Agent": "MAD4B-latest-PR-head-reaper",
            "X-GitHub-Api-Version": "2022-11-28",
        },
        data=b"" if method == "POST" else None,
        method=method,
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            raw = response.read()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as exc:
        if method == "POST" and exc.code in (409, 422):
            return {"already_finished": True}
        raise


def candidate(run, *, number, sha, branch):
    if run.get("event") != "pull_request":
        return False
    if run.get("status") not in ACTIVE_STATES:
        return False
    if run.get("head_branch") != branch or run.get("head_sha") == sha:
        return False
    return number in {
        entry.get("number") for entry in (run.get("pull_requests") or [])
        if isinstance(entry, dict)
    }


def identify(repository, number, token):
    pr = api("GET", repository, "/pulls/" + str(number), token)
    if pr.get("state") != "open":
        return None
    head = pr.get("head") or {}
    origin = (head.get("repo") or {}).get("full_name")
    if origin != repository:
        print("SKIP_CROSS_REPOSITORY_PR", number)
        return None
    return {"number": number, "sha": head["sha"], "branch": head["ref"], "created_at": pr.get("created_at", "2000-01-01T00:00:00Z")}


def stale_runs(repository, pr, token):
    """Scan exact-PR active runs without GitHub's 1,000-result truncation.

    Split overloaded search windows by run-creation time; never submit a
    partial cancellation list when an API window cannot be partitioned.
    """
    matches = {}
    def parse_utc(value):
        return datetime.datetime.fromisoformat(value.replace("Z", "+00:00"))
    start = parse_utc(pr.get("created_at") or "2000-01-01T00:00:00Z")
    end = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(minutes=5)
    def interval(state, lower=None, upper=None, depth=0):
        params = {"branch": pr["branch"], "status": state, "per_page": 100, "page": 1}
        if lower is not None:
            params["created"] = lower.strftime("%Y-%m-%dT%H:%M:%SZ") + ".." + upper.strftime("%Y-%m-%dT%H:%M:%SZ")
        data = api("GET", repository, "/actions/runs?" + urllib.parse.urlencode(params), token)
        first = data.get("workflow_runs") or []
        total = data.get("total_count", len(first))
        if total >= 1000:
            if depth >= MAX_SPLIT_DEPTH:
                raise RuntimeError("RESULT_WINDOW_TRUNCATED; refusing partial cancellation")
            lower = lower or start
            upper = upper or end
            seconds = int((upper - lower).total_seconds())
            if seconds < 2:
                raise RuntimeError("RESULT_WINDOW_UNSPLITTABLE; refusing partial cancellation")
            midpoint = lower + datetime.timedelta(seconds=seconds // 2)
            interval(state, lower, midpoint, depth + 1)
            interval(state, midpoint + datetime.timedelta(seconds=1), upper, depth + 1)
            return
        max_pages = max(1, (total + 99) // 100)
        if max_pages > MAX_PAGES_PER_STATE:
            raise RuntimeError("RESULT_WINDOW_TRUNCATED; refusing partial cancellation")
        for page in range(1, max_pages + 1):
            if page == 1:
                rows = first
            else:
                params["page"] = page
                rows = api("GET", repository, "/actions/runs?" + urllib.parse.urlencode(params), token).get("workflow_runs") or []
            for row in rows:
                if candidate(row, number=pr["number"], sha=pr["sha"], branch=pr["branch"]):
                    matches[row["id"]] = row
    for state in ACTIVE_STATES:
        interval(state)
    return list(matches.values())

def run(repository, number, token, apply):
    pr = identify(repository, number, token)
    if pr is None:
        return 0
    targets = stale_runs(repository, pr, token)
    print(json.dumps({
        "contract": "mad4b.ci-latest-pr-head.v1",
        "pr": number, "head_sha": pr["sha"],
        "superseded_run_count": len(targets), "mode": "apply" if apply else "dry_run",
    }))
    if not apply:
        return 0
    current = identify(repository, number, token)
    if current is None or current["sha"] != pr["sha"]:
        raise RuntimeError("PR_HEAD_MOVED; no cancellations submitted")
    accepted = 0
    finished = 0
    for i, entry in enumerate(targets[:MAX_CANCEL_PER_RUN]):
        if i % 20 == 0:
            current = identify(repository, number, token)
            if current is None or current["sha"] != pr["sha"] or current["branch"] != pr["branch"]:
                raise RuntimeError("PR_HEAD_MOVED_DURING_CANCELLATION; stopping immediately")
        result = api("POST", repository, "/actions/runs/" + str(entry["id"]) + "/cancel", token)
        if result.get("already_finished"):
            finished += 1
        else:
            accepted += 1
        time.sleep(0.1)
    print(json.dumps({
        "cancel_requested": accepted, "already_finished": finished,
        "remaining_candidates": max(0, len(targets) - MAX_CANCEL_PER_RUN),
    }))
    return accepted


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--pr-number", required=True, type=int)
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    token = os.environ.get("GH_TOKEN", "")
    if not repository.startswith("mad4bdigital-ai/") or not token or args.pr_number < 1:
        raise SystemExit("INVALID_REPOSITORY_OR_AUTHORIZATION")
    run(repository, args.pr_number, token, args.apply)


if __name__ == "__main__":
    main()

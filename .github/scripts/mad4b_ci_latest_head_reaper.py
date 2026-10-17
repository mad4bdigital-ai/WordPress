#!/usr/bin/env python3
"""Cancel only superseded PR CI runs, preserving exact-head workflow isolation."""
import argparse
import json
import os
import urllib.error
import urllib.parse
import urllib.request

ACTIVE_STATES = ("queued", "in_progress", "waiting")
MAX_PAGES_PER_STATE = 15


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
    return {"number": number, "sha": head["sha"], "branch": head["ref"]}


def stale_runs(repository, pr, token):
    matches = {}
    for state in ACTIVE_STATES:
        for page in range(1, MAX_PAGES_PER_STATE + 1):
            query = urllib.parse.urlencode({
                "branch": pr["branch"], "status": state,
                "per_page": 100, "page": page,
            })
            data = api("GET", repository, "/actions/runs?" + query, token)
            runs = data.get("workflow_runs") or []
            for run in runs:
                if candidate(run, number=pr["number"], sha=pr["sha"], branch=pr["branch"]):
                    matches[run["id"]] = run
            if len(runs) < 100:
                break
            if page == MAX_PAGES_PER_STATE:
                raise RuntimeError("RESULT_WINDOW_TRUNCATED; refusing partial cancellation")
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
    for entry in targets:
        result = api("POST", repository, "/actions/runs/" + str(entry["id"]) + "/cancel", token)
        if not result.get("already_finished"):
            accepted += 1
    print(json.dumps({"cancel_requested": accepted, "already_finished": len(targets) - accepted}))
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

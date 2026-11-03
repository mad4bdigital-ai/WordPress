#!/usr/bin/env python3
"""Owner-command review signing dispatcher. No package certification or deployment.

Trusted only on default branch via issue_comment. Never checkout PR code.
"""
from __future__ import annotations

import base64
import json
import os
from pathlib import Path
import re
import subprocess
import sys

REPO = "mad4bdigital-ai/WordPress"
WORKFLOW = "dfsb-release-review-attestation.yml"
PLUGIN_HEADER = "wp-content/plugins/dynamic-filter-seo-bridge/dynamic-filter-seo-bridge.php"
COMMAND = re.compile(
    r"/run-dfsb-release-review ([a-f0-9]{40}) ([1-9][0-9]*) "
    r"([0-9]+\.[0-9]+\.[0-9]+-alpha\.[0-9]+-rc\.[0-9]+)\Z"
)


class Denied(ValueError):
    pass


def require(ok: bool, why: str) -> None:
    if not ok:
        raise Denied(why)


def parse_event(kind: str, event: dict, policy: dict):
    require(kind == "issue_comment" and event.get("action") == "created",
            "Only freshly created PR issue comments are accepted")
    comment = event.get("comment") or {}
    login = (comment.get("user") or {}).get("login")
    sender = (event.get("sender") or {}).get("login")
    owners = policy.get("single_owner_safety", {}).get("authorized_owner_logins", [])
    require(isinstance(owners, list) and bool(owners)
            and isinstance(login, str) and login in owners and sender == login,
            "Owner identity is not authorized or consistent")
    raw = comment.get("body")
    m = COMMAND.fullmatch(raw.strip()) if isinstance(raw, str) else None
    require(m is not None, "Exact bounded release-review command required")
    sha, number, version = m.groups()
    issue = event.get("issue") or {}
    require(isinstance(issue.get("pull_request"), dict)
            and issue.get("number") == int(number),
            "The command must be placed on its own source PR")
    comment_id = comment.get("id")
    require(isinstance(comment_id, int) and comment_id > 0,
            "Comment must have a stable GitHub ID")
    return sha, int(number), version, login, comment_id


def check_source(repo: str, sha: str, number: int, version: str, owner: str, comment_id: int, get):
    pr = get(f"repos/{repo}/pulls/{number}")
    require(pr.get("merged") is True and pr.get("state") == "closed"
            and (pr.get("base") or {}).get("ref") == "master"
            and (pr.get("head") or {}).get("sha") == sha
            and ((pr.get("head") or {}).get("repo") or {}).get("full_name") == repo,
            "The reviewed PR is not merged to master at the exact requested source")
    master = (get(f"repos/{repo}/branches/master").get("commit") or {}).get("sha")
    require(isinstance(master, str) and bool(re.fullmatch(r"[a-f0-9]{40}", master)),
            "Trusted master SHA unavailable")
    compare = get(f"repos/{repo}/compare/{sha}...{master}")
    require(compare.get("behind_by") == 0 and compare.get("status") in {"ahead", "identical"},
            "Approved source is not contained in current master")
    content = get(f"repos/{repo}/contents/{PLUGIN_HEADER}?ref={sha}")
    require(content.get("encoding") == "base64" and isinstance(content.get("content"), str),
            "Exact source WordPress header unavailable")
    try:
        header = base64.b64decode(content["content"], validate=False).decode("utf-8")
    except (ValueError, UnicodeDecodeError) as exc:
        raise Denied("Invalid source header encoding") from exc
    require(f" * Version: {version}\n" in header, "Requested version differs from merged plugin")
    response = get(f"repos/{repo}/issues/{number}/comments?per_page=100&page=1")
    require(isinstance(response, list) and len(response) < 100,
            "Comment evidence pagination exceeds bounded one-page authority")
    attestation = f"OWNER_ATTEST_SINGLE_OWNER\nexact_head_sha: {sha}"
    require(any((x.get("user") or {}).get("login") == owner
                and (x.get("body") or "").strip() == attestation for x in response),
            "Exact-head owner attestation is absent")
    command = f"/run-dfsb-release-review {sha} {number} {version}"
    matches = [x for x in response
               if (x.get("user") or {}).get("login") == owner
               and (x.get("body") or "").strip() == command]
    require(matches and min(x["id"] for x in matches) == comment_id,
            "Duplicate command or owner comment mismatch")
    return master


def process(event: dict, kind: str, repo: str, policy: dict, get, dispatch) -> dict:
    require(repo == REPO, "Repository is not the enrolled signing repository")
    sha, number, version, login, cid = parse_event(kind, event, policy)
    master = check_source(repo, sha, number, version, login, cid, get)
    # This second read narrows the time-of-check/use window for the only write.
    require((get(f"repos/{repo}/branches/master").get("commit") or {}).get("sha") == master,
            "Master moved during authorization")
    pr = get(f"repos/{repo}/pulls/{number}")
    require(pr.get("merged") is True and (pr.get("head") or {}).get("sha") == sha,
            "PR changed during authorization")
    dispatch(
        f"repos/{repo}/actions/workflows/{WORKFLOW}/dispatches",
        {"ref": "master", "inputs[source_sha]": sha,
         "inputs[review_pr_number]": str(number), "inputs[version]": version}
    )
    return {"contract": "mad4b.dfsb-owner-command-dispatch.v1",
            "result": "REVIEW_WORKFLOW_DISPATCH_REQUESTED",
            "review_pr_number": number, "source_sha": sha,
            "source_version": version, "master_at_dispatch": master,
            "provider_certified": False, "staging_authorized": False,
            "production_authorized": False}


def gh_get(path: str):
    r = subprocess.run(["gh", "api", "--method", "GET", path],
                       text=True, capture_output=True, timeout=30, check=False)
    require(r.returncode == 0, "GitHub read permission or network error")
    try:
        return json.loads(r.stdout)
    except json.JSONDecodeError as exc:
        raise Denied("GitHub API returned invalid JSON") from exc


def gh_dispatch(path: str, fields: dict):
    argv = ["gh", "api", "--method", "POST", path]
    for k, v in fields.items():
        argv += ["-f", f"{k}={v}"]
    r = subprocess.run(argv, text=True, capture_output=True, timeout=30, check=False)
    require(r.returncode == 0, "GitHub workflow dispatch was rejected")


def main() -> int:
    try:
        event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text(encoding="utf-8"))
        policy = json.loads(Path(".github/mad4b-repository-governance-policy.json").read_text(encoding="utf-8"))
        result = process(event, os.environ["GITHUB_EVENT_NAME"], os.environ["GITHUB_REPOSITORY"],
                         policy, gh_get, gh_dispatch)
        print(json.dumps(result, sort_keys=True))
        if os.environ.get("GITHUB_STEP_SUMMARY"):
            with Path(os.environ["GITHUB_STEP_SUMMARY"]).open("a", encoding="utf-8") as out:
                out.write("### DFSB owner-command review dispatch\n"
                          f"Source: {result['source_sha']}; PR #{result['review_pr_number']}\n\n"
                          "Only a **signed review** workflow was requested. "
                          "Provider, Staging and Production remain **NO-GO**.\n")
        return 0
    except (Denied, KeyError, OSError, TypeError, ValueError, subprocess.TimeoutExpired) as exc:
        print("DFSB_OWNER_DISPATCH_DENIED:", str(exc), file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())

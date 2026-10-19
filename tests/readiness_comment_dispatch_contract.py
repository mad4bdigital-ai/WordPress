#!/usr/bin/env python3
"""Isolated behavioral tests for trusted owner-comment rerun dispatch."""
import importlib.util
from pathlib import Path

source = Path(__file__).resolve().parents[1] / "tools/readiness_comment_dispatch.py"
spec = importlib.util.spec_from_file_location("governed_readiness_comment", source)
assert spec and spec.loader
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

SHA = "a" * 40
OTHER = "b" * 40
BASE = "c" * 40
REPO = "mad4bdigital-ai/WordPress"
BRANCH = "develop/readiness-registry-coverage-enforcement-20261018"
OWNER = "mad4bdigital-ai"
POLICY = {"single_owner_safety": {"authorized_owner_logins": [OWNER]}}
BODY = "/run-production-readiness " + SHA


def comment(kind="issue_comment", body=BODY, sha=SHA, login=OWNER, pr=426, action="created"):
    c = {"body": body, "user": {"login": login}, "commit_id": sha}
    data = {"action": action, "sender": {"login": login}, "comment": c}
    if kind == "issue_comment":
        data["issue"] = {"number": pr, "pull_request": {"url": "https://api.github.com/repos/example/pulls/426"}}
    return data


def pull(number=426, head=SHA, state="open", base="master", repo=REPO, base_sha=BASE):
    return {"number": number, "state": state, "base": {"ref": base, "sha": base_sha},
            "head": {"sha": head, "ref": BRANCH, "repo": {"full_name": repo}}}


def run(status="completed", sha=SHA, associated=True, branch=BRANCH, rid=98,
        conclusion="failure", base_sha=BASE):
    return {"id": rid, "event": "pull_request", "head_sha": sha,
            "head_branch": branch, "status": status,
            "conclusion": conclusion if status == "completed" else None,
            "pull_requests": [{
                "number": 426,
                "head": {"sha": sha},
                "base": {"sha": base_sha},
            }] if associated else []}


def fake_api(pr=None, runs=None, commits=None, current_pr=None, current_run=None):
    pr = pull() if pr is None else pr
    runs = [run()] if runs is None else runs
    calls, posts = [], []

    def get(path):
        calls.append(path)
        if "/commits/" in path and path.endswith("/pulls?per_page=100"):
            return [pull()] if commits is None else commits
        if path.endswith("/pulls/426"):
            return current_pr if current_pr is not None and calls.count(path) > 1 else pr
        if "/actions/workflows/" in path:
            return {"workflow_runs": runs}
        if path.endswith("/actions/runs/98"):
            return current_run if current_run is not None else runs[0]
        raise AssertionError("Untrusted endpoint: " + path)

    def post(path):
        posts.append(path)
    return get, post, calls, posts


def denied(kind, event, get, post):
    try:
        mod.process(kind, event, POLICY, REPO, get, post)
    except mod.Denied:
        return
    raise AssertionError("Unauthorized or stale operation accepted")


def test_success():
    get, post, _, posts = fake_api()
    outcome = mod.process("issue_comment", comment(), POLICY, REPO, get, post)
    assert outcome["run_id"] == 98 and outcome["outcome"] == "RERUN_REQUESTED"
    assert outcome["production_authorized"] is False
    assert posts == [f"repos/{REPO}/actions/runs/98/rerun"]


def test_active_deduplicated():
    get, post, _, posts = fake_api(runs=[run(status="in_progress")])
    outcome = mod.process("issue_comment", comment(), POLICY, REPO, get, post)
    assert outcome["outcome"] == "ALREADY_ACTIVE" and not posts


def test_success_deduplicated():
    get, post, _, posts = fake_api(runs=[run(conclusion="success")])
    outcome = mod.process("issue_comment", comment(), POLICY, REPO, get, post)
    assert outcome["outcome"] == "ALREADY_SUCCESS" and not posts


def test_rerun_toctou_fences():
    scenarios = (
        {"current_pr": pull(head=OTHER)},
        {"current_pr": pull(base_sha=OTHER)},
        {"current_run": run(status="in_progress")},
        {"current_run": run(sha=OTHER)},
        {"current_run": run(base_sha=OTHER)},
        {"current_run": run(conclusion="success")},
    )
    for case in scenarios:
        get, post, _, posts = fake_api(**case)
        denied("issue_comment", comment(), get, post)
        assert not posts


def test_security():
    for body in ("run " + SHA, "/run-production-readiness " + OTHER + " unsafe",
                 "/run-production-readiness " + SHA + "\n/merge",
                 "/run-production-readiness " + SHA[:-1], "/run-production-readiness " + SHA.upper()):
        get, post, _, posts = fake_api()
        denied("issue_comment", comment(body=body), get, post)
        assert not posts
    for kwargs in ({"login":"intruder"}, {"action":"edited"}):
        get, post, _, posts = fake_api()
        denied("issue_comment", comment(**kwargs), get, post)
        assert not posts
    for arg in ({"state":"closed"}, {"head":OTHER}, {"base":"another"}, {"repo":"untrusted/WordPress"}):
        get, post, _, posts = fake_api(pr=pull(**arg))
        denied("issue_comment", comment(), get, post)
        assert not posts
    for rows in ([run(sha=OTHER)], [run(associated=False)], [run(branch="stale")],
                 [run(base_sha=OTHER)]):
        get, post, _, posts = fake_api(runs=rows)
        denied("issue_comment", comment(), get, post)
        assert not posts
    get, post, _, posts = fake_api(runs=[run() for _ in range(100)])
    denied("issue_comment", comment(), get, post)
    assert not posts


def test_only_supported_github_event():
    get, post, _, posts = fake_api()
    denied("commit_comment", comment(kind="commit_comment"), get, post)
    assert not posts
    workflow = (Path(__file__).resolve().parents[1]
                / ".github/workflows/mad4b-readiness-comment-dispatch.yml").read_text()
    assert "\n  issue_comment:\n" in workflow
    assert "\n  commit_comment:\n" not in workflow
    assert "types: [created]" in workflow
    assert "actions: write" in workflow
    assert "ref: ${{ github.event.repository.default_branch }}" in workflow


if __name__ == "__main__":
    test_success()
    test_active_deduplicated()
    test_success_deduplicated()
    test_rerun_toctou_fences()
    test_security()
    test_only_supported_github_event()
    print("READINESS_COMMENT_DISPATCH_SECURITY_CONTRACT: PASS")
